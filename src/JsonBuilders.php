<?php

declare(strict_types=1);

namespace WebtreesAnd\Api;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\Elements\UnknownElement;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Note;
use Fisharebest\Webtrees\Place;
use Fisharebest\Webtrees\PlaceLocation;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function class_exists;
use function html_entity_decode;
use function in_array;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function str_replace;
use function strip_tags;
use function strrpos;
use function substr;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Bausteine fuer die JSON-Antworten: Personen, Familien, Ereignisse, Medien - immer ohne HTML und nur mit dem,
 * was der angemeldete Benutzer sehen darf.
 */
trait JsonBuilders
{
    /**
     * Kurzform einer Person. Fuer nicht sichtbare Personen liefert webtrees selbst
     * "Privat" als Namen und leere Daten - hier wird nichts zusaetzlich preisgegeben.
     *
     * @return array<string,mixed>
     */
    private function personSummary(Individual $individual): array
    {
        $thumb = null;

        try {
            $media_file = $individual->findHighlightedMediaFile();
            if ($media_file !== null && $media_file->isImage()) {
                $thumb = $media_file->imageUrl(200, 200, 'crop');
            }
        } catch (Throwable) {
            // Fehlende Datei o. ae. - dann eben kein Bild.
        }

        return [
            'xref'     => $individual->xref(),
            'name'     => $this->plain($individual->fullName()),
            'sortName' => $individual->sortName(),
            'sex'      => $individual->sex(),
            'isDead'   => $individual->isDead(),
            'private'  => !$individual->canShow(),
            'lifespan' => $this->plain($individual->lifespan()),
            'birth'    => $this->eventJson($individual->getBirthDate(), $individual->getBirthPlace()),
            'death'    => $this->eventJson($individual->getDeathDate(), $individual->getDeathPlace()),
            'thumb'    => $thumb,
            'url'      => $individual->url(),
        ];
    }

    /**
     * @param Individual|null $relative_to bei Partnerfamilien: die Person, deren Partner gesucht wird
     *
     * @return array<string,mixed>
     */
    private function familyJson(Family $family, Individual|null $relative_to): array
    {
        $husband = $family->husband();
        $wife    = $family->wife();
        $spouse  = $relative_to instanceof Individual ? $family->spouse($relative_to) : null;

        $children = [];
        foreach ($family->children() as $child) {
            $children[] = $this->personSummary($child);
        }

        return [
            'xref'     => $family->xref(),
            'name'     => $this->plain($family->fullName()),
            'url'      => $family->url(),
            'husband'  => $husband instanceof Individual ? $this->personSummary($husband) : null,
            'wife'     => $wife instanceof Individual ? $this->personSummary($wife) : null,
            'spouse'   => $spouse instanceof Individual ? $this->personSummary($spouse) : null,
            'marriage' => $this->eventJson($family->getMarriageDate(), $family->getMarriagePlace()),
            'facts'    => $this->factsJson($family),
            'children' => $children,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function descendantsJson(Individual $individual, int $generations): array
    {
        $families = [];

        if ($generations > 1) {
            foreach ($individual->spouseFamilies() as $family) {
                $spouse   = $family->spouse($individual);
                $children = [];

                foreach ($family->children() as $child) {
                    $children[] = $this->descendantsJson($child, $generations - 1);
                }

                $families[] = [
                    'xref'     => $family->xref(),
                    'spouse'   => $spouse instanceof Individual ? $this->personSummary($spouse) : null,
                    'marriage' => $this->eventJson($family->getMarriageDate(), $family->getMarriagePlace()),
                    'children' => $children,
                ];
            }
        }

        return [
            'person'   => $this->personSummary($individual),
            'families' => $families,
        ];
    }

    /**
     * Ereignisse und Attribute eines Datensatzes. facts() filtert bereits nach Zugriffsrechten.
     *
     * @return array<int,array<string,mixed>>
     */
    private function factsJson(GedcomRecord $record): array
    {
        $facts = $this->sortFacts($record->facts());
        $data  = [];

        foreach ($facts as $fact) {
            $tag = $this->shortTag($fact->tag());

            if (in_array($tag, self::SKIP_FACTS, true) || $fact->isPendingDeletion()) {
                continue;
            }

            $place = $fact->place();

            $data[] = [
                'id'      => $fact->id(),
                'tag'     => $tag,
                'label'   => $this->factLabel($fact),
                // false: ein Tag, das webtrees nicht kennt (Hersteller-Tag ohne Definition, z. B. Ahnenblatts _INET).
                // Clients koennen solche Zeilen ausblenden; in webtrees selbst bleiben sie unveraendert erhalten.
                'known'   => !Registry::elementFactory()->make($fact->tag()) instanceof UnknownElement,
                'value'   => $this->factValue($fact, $record->tree()),
                'type'    => $fact->attribute('TYPE'),
                'date'    => $this->dateJson($fact->date()),
                'place'   => $this->placeJson($place, $fact->latitude(), $fact->longitude()),
                'notes'   => $this->factNotes($fact, $record->tree()),
                'sources' => $this->factSources($fact, $record->tree()),
            ];
        }

        return $data;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function mediaJson(GedcomRecord $record): array
    {
        $data = [];

        foreach ($record->facts(['OBJE']) as $fact) {
            $media = $fact->target();

            if (!$media instanceof Media || !$media->canShow()) {
                continue;
            }

            foreach ($this->mediaFilesJson($media) as $file) {
                $data[] = $file;
            }
        }

        return $data;
    }

    /**
     * Die Dateien eines Medienobjekts. Defekte oder fehlende Dateien werden uebersprungen.
     *
     * @return array<int,array<string,mixed>>
     */
    private function mediaFilesJson(Media $media): array
    {
        $data = [];

        foreach ($media->mediaFiles() as $media_file) {
            $is_image = $media_file->isImage();

            try {
                $thumb = $is_image ? $media_file->imageUrl(400, 400, 'contain') : null;
                $full  = $media_file->isExternal() ? $media_file->filename() : $media_file->downloadUrl('inline');
            } catch (Throwable) {
                continue;
            }

            $data[] = [
                'xref'    => $media->xref(),
                'title'   => $media_file->title() !== '' ? $media_file->title() : $this->plain($media->fullName()),
                'mime'    => $media_file->mimeType(),
                'isImage' => $is_image,
                'thumb'   => $thumb,
                'file'    => $full,
                'url'     => $media->url(),
            ];
        }

        return $data;
    }

    /**
     * "Urgrossmutter", "Cousin" ... - wie $individual mit einer Bezugsperson verwandt ist.
     * Bezugsperson: ?relativeTo=<xref>, sonst die eigene Person des angemeldeten Benutzers.
     */
    private function relationship(ServerRequestInterface $request, Individual $individual): string
    {
        $tree = $individual->tree();
        $xref = Validator::queryParams($request)->string('relativeTo', '');

        if ($xref === '') {
            $xref = $tree->getUserPreference(Auth::user(), UserInterface::PREF_TREE_ACCOUNT_XREF);
        }

        if ($xref === '' || $xref === $individual->xref()) {
            return '';
        }

        $other = Registry::individualFactory()->make($xref, $tree);

        if ($other === null || !$other->canShow()) {
            return '';
        }

        try {
            return $this->plain(Registry::container()->get(RelationshipService::class)->getCloseRelationshipName($other, $individual));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function eventJson(Date $date, Place $place): array|null
    {
        $date_json  = $this->dateJson($date);
        $place_json = $this->placeJson($place, null, null);

        if ($date_json === null && $place_json === null) {
            return null;
        }

        return ['date' => $date_json, 'place' => $place_json];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function dateJson(Date $date): array|null
    {
        if (!$date->isOK()) {
            return null;
        }

        return [
            'text' => $this->plain($date->display()),
            'year' => $date->gregorianYear(),
            'jd'   => $date->minimumJulianDay(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function placeJson(Place $place, float|null $latitude, float|null $longitude): array|null
    {
        if ($place->gedcomName() === '') {
            return null;
        }

        // Steht am Ereignis keine Koordinate (2 PLAC / 3 MAP), kennt webtrees den Ort vielleicht aus seiner
        // Ortstabelle (Verwaltung -> Geografische Daten).
        if ($latitude === null || $longitude === null) {
            try {
                $location  = new PlaceLocation($place->gedcomName());
                $latitude  = $location->latitude();
                $longitude = $location->longitude();
            } catch (Throwable) {
                $latitude = $longitude = null;
            }
        }

        return [
            'name'  => $place->gedcomName(),
            'short' => $this->plain($place->shortName()),
            'lat'   => $latitude,
            'lng'   => $longitude,
        ];
    }

    /**
     * Fuer unbekannte (Hersteller-)Tags liefert webtrees das Tag samt Pfad ("INDI:_INET") - dann nur das Tag.
     */
    private function factLabel(Fact $fact): string
    {
        $label = $this->plain($fact->label());

        return preg_match('/^[A-Z_]+(:[A-Z0-9_]+)+$/', $label) === 1 ? $this->shortTag($label) : $label;
    }

    /**
     * Wert eines Ereignisses so, wie webtrees ihn anzeigt (Geschlecht, Ja/Nein, Notiztext ...), ohne HTML.
     */
    private function factValue(Fact $fact, Tree $tree): string
    {
        $value = $fact->value();

        if ($value === '') {
            return '';
        }

        try {
            return $this->plain(Registry::elementFactory()->make($fact->tag())->value($value, $tree));
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * @return array<int,string>
     */
    private function factNotes(Fact $fact, Tree $tree): array
    {
        preg_match_all('/\n2 NOTE ?(.*(?:\n3 CONT ?.*)*)/', $fact->gedcom(), $matches);

        $notes = [];

        foreach ($matches[1] as $text) {
            if (preg_match('/^@(.+)@$/', $text, $match) === 1) {
                $note = Registry::noteFactory()->make($match[1], $tree);
                $text = $note instanceof Note && $note->canShow() ? $note->getNote() : '';
            } else {
                $text = (string) preg_replace('/\n3 CONT ?/', "\n", $text);
            }

            if (trim($text) !== '') {
                $notes[] = $text;
            }
        }

        return $notes;
    }

    /**
     * @return array<int,array{xref:string,title:string}>
     */
    private function factSources(Fact $fact, Tree $tree): array
    {
        preg_match_all('/\n2 SOUR @(.+)@/', $fact->gedcom(), $matches);

        $sources = [];

        foreach ($matches[1] as $xref) {
            $source = Registry::sourceFactory()->make($xref, $tree);

            if ($source !== null && $source->canShow()) {
                $sources[] = ['xref' => $xref, 'title' => $this->plain($source->fullName())];
            }
        }

        return $sources;
    }

    /**
     * webtrees 2.2: Fact::sortFacts() - ab 2.3: FactSortService::sort().
     *
     * @param Collection<int,Fact> $facts
     *
     * @return Collection<int,Fact>
     */
    private function sortFacts(Collection $facts): Collection
    {
        $service = 'Fisharebest\\Webtrees\\Services\\FactSortService';

        if (class_exists($service)) {
            return Registry::container()->get($service)->sort($facts);
        }

        return Fact::sortFacts($facts);
    }

    /**
     * "INDI:BIRT" -> "BIRT"
     */
    private function shortTag(string $tag): string
    {
        $pos = strrpos($tag, ':');

        return $pos === false ? $tag : substr($tag, $pos + 1);
    }

    private function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Bidi-Steuerzeichen, die webtrees um Namen und Daten legt
        $text = str_replace(["\u{202A}", "\u{202B}", "\u{202C}", "\u{200E}", "\u{200F}", "\u{2068}", "\u{2069}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
