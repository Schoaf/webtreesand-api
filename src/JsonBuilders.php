<?php

declare(strict_types=1);

namespace Api4Webtrees;

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
     * $with_counts (ab Stufe 12, nur in der Individual-Antwort): hasParents, partnersCount, childrenCount - ob eine
     * Ansicht von dieser Person aus weiter aufklappen kann, ohne sie einzeln abzurufen. Nicht in Listen und Suche,
     * dort waeren es je Treffer unnoetige Datenbankzugriffe. Gezaehlt wird nur, was der Benutzer sehen darf.
     *
     * @return array<string,mixed>
     */
    private function personSummary(Individual $individual, bool $with_counts = false): array
    {
        $media_file = $individual->findHighlightedMediaFile();
        $thumb      = $media_file !== null && $media_file->isImage() ? $media_file->imageUrl(200, 200, 'crop') : null;
        // Vor- und Nachname getrennt (Nachname samt Namenszusatz wie "de' Medici"): der Desktop-Client zeigt
        // "Nachname, Vorname" wie ein Register; sortName von webtrees laesst den Zusatz weg.
        $names   = $individual->getAllNames();
        $primary = $names[$individual->getPrimaryName()] ?? [];
        $given   = str_contains($primary['givn'] ?? '', '@') ? '' : $this->plain($primary['givn'] ?? '');
        $surname = str_contains($primary['surname'] ?? '', '@') ? '' : $this->plain($primary['surname'] ?? '');

        $summary = [
            'xref'     => $individual->xref(),
            'name'     => $this->plain($individual->fullName()),
            'sortName' => $individual->sortName(),
            'given'    => $individual->canShowName() ? $given : '',
            'surname'  => $individual->canShowName() ? $surname : '',
            'sex'      => $individual->sex(),
            'isDead'   => $individual->isDead(),
            'private'  => !$individual->canShow(),
            'lifespan' => $this->plain($individual->lifespan()),
            'birth'    => $this->eventJson($individual->getBirthDate(), $individual->getBirthPlace()),
            'death'    => $this->eventJson($individual->getDeathDate(), $individual->getDeathPlace()),
            'thumb'    => $thumb,
            'url'      => $individual->url(),
        ];

        if ($with_counts) {
            $summary['hasParents']    = $individual->childFamilies()->isNotEmpty();
            $summary['partnersCount'] = $individual->spouseFamilies()->count();
            $summary['childrenCount'] = $individual->spouseFamilies()->sum(static fn (Family $family): int => $family->children()->count());
        }

        return $summary;
    }

    /**
     * @param Individual|null $relative_to bei Partnerfamilien: die Person, deren Partner gesucht wird
     * @param bool            $with_counts siehe personSummary() - fuer Eltern, Partner und Kinder der Familie
     *
     * @return array<string,mixed>
     */
    private function familyJson(Family $family, Individual|null $relative_to, bool $with_counts = false): array
    {
        $husband = $family->husband();
        $wife    = $family->wife();
        $spouse  = $relative_to instanceof Individual ? $family->spouse($relative_to) : null;

        $children = [];
        foreach ($family->children() as $child) {
            // Die Heiraten der Kinder gehoeren in die Lebenslinie der Eltern (ab Stufe 10): je Partnerfamilie des
            // Kindes Partner und Heirat; ohne Datum bleibt date null, die Heirat zaehlt trotzdem.
            $marriages = [];
            // Eigener Variablenname: $spouse ist der Partner DIESER Familie und wird unten noch gebraucht -
            // bis 1.6.0 hat die Schleife ihn ueberschrieben, die App zeigte dann den Partner des letzten Kindes (Fehler 1.5.0-1.6.0).
            foreach ($child->spouseFamilies() as $child_family) {
                $child_spouse = $child_family->spouse($child);
                $marriages[]  = [
                    'family' => $child_family->xref(),
                    'spouse' => $child_spouse instanceof Individual && $child_spouse->canShowName() ? $this->plain($child_spouse->fullName()) : '',
                    'date'   => $this->dateJson($child_family->getMarriageDate()),
                    'place'  => $this->placeJson($child_family->getMarriagePlace(), null, null),
                ];
            }
            $children[] = $this->personSummary($child, $with_counts) + ['marriages' => $marriages];
        }

        return [
            'xref'     => $family->xref(),
            'name'     => $this->plain($family->fullName()),
            'url'      => $family->url(),
            'husband'  => $husband instanceof Individual ? $this->personSummary($husband, $with_counts) : null,
            'wife'     => $wife instanceof Individual ? $this->personSummary($wife, $with_counts) : null,
            'spouse'   => $spouse instanceof Individual ? $this->personSummary($spouse, $with_counts) : null,
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
                'date'    => $this->dateJson($fact->date(), $fact->attribute('DATE')),
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

        // Das Hauptfoto bestimmt webtrees selbst: das erste verknuepfte Medienobjekt mit einem Bild.
        $primary = $record instanceof Individual ? $record->findHighlightedMediaFile()?->media()->xref() : null;

        foreach ($record->facts(['OBJE']) as $fact) {
            $media = $fact->target();

            if (!$media instanceof Media || !$media->canShow()) {
                continue;
            }

            foreach ($this->mediaFilesJson($media) as $file) {
                // factId: die Verknuepfung (1 OBJE @M1@) - fuer UnlinkMedia und PrimaryMedia.
                $data[] = $file + ['factId' => $fact->id(), 'primary' => $media->xref() === $primary];
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
            $thumb    = $is_image ? $media_file->imageUrl(400, 400, 'contain') : null;
            $full     = $media_file->isExternal() ? $media_file->filename() : $media_file->downloadUrl('inline');

            $data[] = [
                'xref'    => $media->xref(),
                'title'   => $media_file->title() !== '' ? $media_file->title() : $this->plain($media->fullName()),
                'mime'    => $media_file->mimeType(),
                'isImage' => $is_image,
                'thumb'   => $thumb,
                'file'    => $full,
                'url'     => $media->url(),
                // Pfad der Datei im Medienordner des Baums (ab Stufe 9) - damit eine App die Datei bei anderen Modulen
                // benennen kann, etwa um ueber Sammlungen EXIF zu schreiben. null bei Internetadressen.
                'path'    => $media_file->isExternal() ? null : $media_file->filename(),
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

        // Liefert '' fuer nicht verwandte Personen.
        return $this->plain(Registry::container()->get(RelationshipService::class)->getCloseRelationshipName($other, $individual));
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
    private function dateJson(Date $date, string $gedcom = ''): array|null
    {
        if (!$date->isOK()) {
            return null;
        }

        $json = [
            'text' => $this->plain($date->display()),
            'year' => $date->gregorianYear(),
            'jd'   => $date->minimumJulianDay(),
        ];

        // Bei Ereignissen zusaetzlich das Datum, wie es im GEDCOM steht ("ABT 1850", "9 NOV 1957") - damit ein
        // Client es zum Bearbeiten vorbelegen kann, ohne die Anzeige ("um 1850") zurueckuebersetzen zu muessen.
        if ($gedcom !== '') {
            $json['gedcom'] = $gedcom;
        }

        return $json;
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
            $location  = new PlaceLocation($place->gedcomName());
            $latitude  = $location->latitude();
            $longitude = $location->longitude();
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

        return $this->plain(Registry::elementFactory()->make($fact->tag())->value($value, $tree));
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
