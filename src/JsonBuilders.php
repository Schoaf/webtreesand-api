<?php

declare(strict_types=1);

namespace Api4Webtrees;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\Elements\RelationIsDescriptor;
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
use Fisharebest\Webtrees\Repository;
use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\Services\LinkedRecordService;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Illuminate\Support\Collection;
use Psr\Http\Message\ServerRequestInterface;

use function array_keys;
use function array_map;
use function array_pad;
use function class_exists;
use function explode;
use function implode;
use function html_entity_decode;
use function in_array;
use function mb_strtolower;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function str_replace;
use function str_contains;
use function strip_tags;
use function strrpos;
use function substr;
use function trim;
use function usort;

use const ENT_HTML5;
use const PHP_INT_MAX;
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
    /**
     * Kurzform fuer lange Listen (Jahrestage): nur, was eine Zeile mit Bild braucht. Die Felder sind dieselben wie in
     * personSummary(), Clients lesen sie mit demselben Modell (fehlende Felder bleiben leer).
     *
     * @return array<string,mixed>
     */
    private function personShort(Individual $individual): array
    {
        $media_file = $individual->findHighlightedMediaFile();

        return [
            'xref'     => $individual->xref(),
            'name'     => $this->plain($individual->fullName()),
            'sex'      => $individual->sex(),
            'isDead'   => $individual->isDead(),
            'private'  => !$individual->canShow(),
            'lifespan' => $this->plain($individual->lifespan()),
            'thumb'    => $media_file !== null && $media_file->isImage() ? $media_file->imageUrl(200, 200, 'crop') : null,
            'url'      => $individual->url(),
        ];
    }

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
            'xref'       => $individual->xref(),
            'name'       => $this->plain($individual->fullName()),
            'sortName'   => $individual->sortName(),
            'given'      => $individual->canShowName() ? $given : '',
            'surname'    => $individual->canShowName() ? $surname : '',
            'sex'        => $individual->sex(),
            'isDead'     => $individual->isDead(),
            'private'    => !$individual->canShow(),
            'lifespan'   => $this->plain($individual->lifespan()),
            'birth'      => $this->eventJson($individual->getBirthDate(), $individual->getBirthPlace()),
            'death'      => $this->eventJson($individual->getDeathDate(), $individual->getDeathPlace()),
            // ab Stufe 14: Rufname, Taufe, Begraebnis und erster Beruf - fuer Tafeln und Listen, ohne die Person
            // einzeln abzurufen. facts() liefert fuer nicht sichtbare Personen nichts, dann bleibt alles leer.
            'call'       => $individual->canShowName() ? $this->callName($individual, $primary['full'] ?? '') : '',
            'chr'        => $this->firstEventJson($individual, ['CHR', 'BAPM']),
            'buri'       => $this->firstEventJson($individual, ['BURI', 'CREM']),
            'occupation' => $this->firstFactValue($individual, 'OCCU'),
            'thumb'      => $thumb,
            'url'        => $individual->url(),
        ];

        if ($with_counts) {
            $summary['hasParents']    = $individual->childFamilies()->isNotEmpty();
            $summary['partnersCount'] = $individual->spouseFamilies()->count();
            $summary['childrenCount'] = $individual->spouseFamilies()->sum(static fn (Family $family): int => $family->children()->count());
        }

        return $summary;
    }

    /**
     * Rufname: der mit * markierte Vorname ("Johann Heinrich*") oder, wie GEDCOM-L ihn schreibt,
     * 2 _RUFNAME unter dem ersten Namen. '' wenn keiner angegeben ist.
     */
    private function callName(Individual $individual, string $full_name): string
    {
        if (preg_match('/<span class="starredname">(.*?)<\/span>/', $full_name, $match) === 1) {
            return $this->plain($match[1]);
        }

        return trim($individual->facts(['NAME'])->first()?->attribute('_RUFNAME') ?? '');
    }

    /**
     * Datum und Ort des ersten sichtbaren Ereignisses mit einem der Tags - die Tags in dieser Reihenfolge bevorzugt
     * (Taufe: CHR vor BAPM, Begraebnis: BURI vor CREM).
     *
     * @param list<string> $tags
     *
     * @return array<string,mixed>|null
     */
    private function firstEventJson(Individual $individual, array $tags): array|null
    {
        foreach ($tags as $tag) {
            $fact = $individual->facts([$tag])->first();

            if ($fact instanceof Fact) {
                return $this->eventJson($fact->date(), $fact->place());
            }
        }

        return null;
    }

    private function firstFactValue(Individual $individual, string $tag): string|null
    {
        $fact = $individual->facts([$tag])->first(static fn (Fact $fact): bool => $fact->value() !== '');

        return $fact instanceof Fact ? $this->factValue($fact, $individual->tree()) : null;
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
     * Ab Stufe 19 je Fakt zusaetzlich: typeLabel (TYPE uebersetzt), noteKinds (parallel zu notes), associates
     * (2 _ASSO: Paten, Trauzeugen ...) und freeAssociates (aus Notizen "Paten: ..."). Alles additiv.
     *
     * @return array<int,array<string,mixed>>
     */
    private function factsJson(GedcomRecord $record): array
    {
        $tree  = $record->tree();
        $facts = $this->sortFacts($record->facts());
        $data  = [];

        foreach ($facts as $fact) {
            $tag = $this->shortTag($fact->tag());

            if (in_array($tag, self::SKIP_FACTS, true) || $fact->isPendingDeletion()) {
                continue;
            }

            $place = $fact->place();
            $type  = $fact->attribute('TYPE');
            [$notes, $kinds, $free] = $this->factNotesJson($fact, $tree);

            $data[] = [
                'id'             => $fact->id(),
                'tag'            => $tag,
                'label'          => $this->factLabel($fact),
                // false: ein Tag, das webtrees nicht kennt (Hersteller-Tag ohne Definition, z. B. _INET).
                // Clients koennen solche Zeilen ausblenden; in webtrees selbst bleiben sie unveraendert erhalten.
                'known'          => !Registry::elementFactory()->make($fact->tag()) instanceof UnknownElement,
                'value'          => $this->factValue($fact, $tree),
                'type'           => $type,
                // TYPE so, wie webtrees ihn anzeigt (FAM:MARR:TYPE: civil -> "Standesamtliche Heirat"); unbekannte
                // Werte bleiben roh, ohne TYPE null.
                'typeLabel'      => $type === '' ? null : $this->plain(Registry::elementFactory()->make($fact->tag() . ':TYPE')->value($type, $tree)),
                'date'           => $this->dateJson($fact->date(), $fact->attribute('DATE')),
                'place'          => $this->placeJson($place, $fact->latitude(), $fact->longitude()),
                'notes'          => $notes,
                'noteKinds'      => $kinds,
                'sources'        => $this->factSources($fact, $tree),
                'associates'     => $this->factAssociates($fact, $tree),
                'freeAssociates' => $free,
            ];
        }

        if ($record instanceof Individual) {
            $this->attachLevel1Associates($data);
        }

        return $data;
    }

    /**
     * "1 ASSO @I…@" + "2 RELA godparent" an der Person (GEDCOM 5.5.1, ältere Exporte): der Pate erscheint
     * zusaetzlich bei der Taufe (CHR, sonst BAPM) mit level1: true. Der Fakt ASSO selbst bleibt in der Liste stehen -
     * so, wie ihn aeltere Clients kennen; neuere blenden ihn aus, wenn sie ihn ueber die Taufe zeigen.
     *
     * @param array<int,array<string,mixed>> $data
     */
    private function attachLevel1Associates(array &$data): void
    {
        $target = null;

        foreach (['CHR', 'BAPM'] as $tag) {
            foreach ($data as $i => $fact) {
                if ($fact['tag'] === $tag) {
                    $target = $i;
                    break 2;
                }
            }
        }

        if ($target === null) {
            return;
        }

        foreach ($data as $fact) {
            if ($fact['tag'] === 'ASSO') {
                foreach ($fact['associates'] as $associate) {
                    if ($associate['role'] === 'godparent') {
                        $data[$target]['associates'][] = $associate;
                    }
                }
            }
        }
    }

    /**
     * Verknuepfte Personen eines Ereignisses (2 _ASSO @I…@ mit 3 RELA, 3 NOTE, 3 SOUR) - Paten, Trauzeugen ... Bei
     * einem Fakt "1 ASSO" an der Person ist der Fakt selbst die Verknuepfung (ein Eintrag, level1: true).
     *
     * @return array<int,array<string,mixed>>
     */
    private function factAssociates(Fact $fact, Tree $tree): array
    {
        if ($this->shortTag($fact->tag()) === 'ASSO') {
            [, $rest] = array_pad(explode("\n", $fact->gedcom(), 2), 2, '');
            $entry    = $this->associateJson($fact->value(), $rest === '' ? '' : "\n" . $rest, 1, $tree, true);

            return $entry === null ? [] : [$entry];
        }

        $data = [];

        foreach (GedcomText::unterzeilen($fact->gedcom(), 2, '_ASSO') as [$wert, $unter]) {
            $entry = $this->associateJson($wert, $unter, 2, $tree, false);

            if ($entry !== null) {
                $data[] = $entry;
            }
        }

        return $data;
    }

    /**
     * Ein Eintrag fuer associates[]. Datenschutz wie bei verborgenen Kindern: darf der Betrachter die Person nicht
     * sehen, bleiben nur xref und private: true - kein Name, kein Geschlecht. Darf er nicht einmal den Verweis sehen
     * (canShowName), faellt der Eintrag ganz weg. Verweise auf fehlende Datensaetze fallen weg.
     *
     * @return array<string,mixed>|null
     */
    private function associateJson(string $wert, string $unter, int $ebene, Tree $tree, bool $level1): array|null
    {
        if (preg_match('/^@([^@]+)@$/', trim($wert), $match) !== 1) {
            return null;
        }

        $individual = Registry::individualFactory()->make($match[1], $tree);

        if (!$individual instanceof Individual || !$individual->canShowName()) {
            return null;
        }

        $u       = $ebene + 1;
        $private = !$individual->canShow();
        $rela    = trim(GedcomText::unterzeilen($unter, $u, 'RELA')[0][0] ?? '');
        $role    = $this->associateRole($rela);

        return [
            'xref'    => $match[1],
            'name'    => $private ? null : $this->plain($individual->fullName()),
            'sex'     => $private ? null : $individual->sex(),
            'rela'    => $rela,
            'role'    => $role,
            'label'   => $this->associateLabel($rela, $role, $private ? 'U' : $individual->sex()),
            'private' => $private,
            'level1'  => $level1,
            'notes'   => $this->notesFromBlock($unter, $u, $tree),
            'sources' => $this->sourcesFromBlock($unter, $u, $tree),
        ];
    }

    private function visibleXref(Individual|null $individual): string|null
    {
        return $individual instanceof Individual && $individual->canShow() ? $individual->xref() : null;
    }

    /**
     * RELA normalisiert, ohne Ruecksicht auf Gross-/Kleinschreibung: godparent, witness oder other.
     */
    private function associateRole(string $rela): string
    {
        $rela = mb_strtolower(trim($rela));

        if (in_array($rela, ['godparent', 'godfather', 'godmother', 'pate', 'patin', 'taufpate', 'taufpatin', 'gevatter', 'gevatterin'], true)) {
            return 'godparent';
        }

        if (in_array($rela, ['witness', 'trauzeuge', 'trauzeugin', 'zeuge', 'zeugin'], true)) {
            return 'witness';
        }

        return 'other';
    }

    /**
     * Beschriftung wie webtrees (RelationIsDescriptor): "godparent" wird nach dem Geschlecht der verknuepften Person zu
     * "Pate"/"Patin". Werte, die webtrees nicht kennt (godfather, Gevatter ...), bekommen die Beschriftung ihrer Rolle;
     * sonst bleibt der Rohwert.
     */
    private function associateLabel(string $rela, string $role, string $sex): string
    {
        $element = Registry::elementFactory()->make('INDI:*:_ASSO:RELA');
        $element = $element instanceof RelationIsDescriptor ? $element : new RelationIsDescriptor('');
        $values  = $element->values($sex) + $element->values('U');
        $key     = mb_strtolower(trim($rela));

        return $values[$key] ?? ($role === 'other' ? $rela : $values[$role] ?? $rela);
    }

    /**
     * Gegenrichtung (ab Stufe 19): bei welchen Ereignissen anderer Personen und Familien diese Person als Pate, Zeuge ...
     * steht - wie webtrees' IndividualFactsService ueber die Verknuepfungen ASSO und _ASSO. Nur sichtbare Datensaetze
     * (LinkedRecordService filtert) und sichtbare Fakten (facts()). Ein "1 ASSO" an der Person zaehlt mit: steht er
     * fuer einen Paten und hat die Person eine Taufe, wird deren Ereignis genannt (level1: true). Nach Datum sortiert.
     *
     * @return array<int,array<string,mixed>>
     */
    private function associatedIn(Individual $individual): array
    {
        $service = Registry::container()->get(LinkedRecordService::class);
        $records = new Collection();

        foreach (['ASSO', '_ASSO'] as $type) {
            $records = $records->merge($service->linkedIndividuals($individual, $type))->merge($service->linkedFamilies($individual, $type));
        }

        $pointer = '@' . $individual->xref() . '@';
        $data    = [];
        $order   = [];

        foreach ($records->unique(static fn (GedcomRecord $record): string => $record->xref()) as $record) {
            $facts   = $record->facts()->filter(static fn (Fact $fact): bool => !$fact->isPendingDeletion());
            $baptism = $facts->first(fn (Fact $fact): bool => $this->shortTag($fact->tag()) === 'CHR')
                ?? $facts->first(fn (Fact $fact): bool => $this->shortTag($fact->tag()) === 'BAPM');

            foreach ($facts as $fact) {
                $links = [];

                if ($this->shortTag($fact->tag()) === 'ASSO' && trim($fact->value()) === $pointer) {
                    $links[] = [trim($fact->attribute('RELA')), true];
                }

                foreach (GedcomText::unterzeilen($fact->gedcom(), 2, '_ASSO') as [$wert, $unter]) {
                    if (trim($wert) === $pointer) {
                        $links[] = [trim(GedcomText::unterzeilen($unter, 3, 'RELA')[0][0] ?? ''), false];
                    }
                }

                foreach ($links as [$rela, $level1]) {
                    $role  = $this->associateRole($rela);
                    $shown = $level1 && $role === 'godparent' && $baptism instanceof Fact ? $baptism : $fact;
                    $date  = $shown->date();

                    $order[] = $date->isOK() ? $date->minimumJulianDay() : PHP_INT_MAX;
                    $data[]  = [
                        'record'     => $record->xref(),
                        'recordType' => $record instanceof Family ? 'FAM' : 'INDI',
                        'name'       => $this->plain($record->fullName()),
                        'tag'        => $this->shortTag($shown->tag()),
                        'label'      => $this->factLabel($shown),
                        'factId'     => $shown->id(),
                        'date'       => $this->dateJson($date),
                        'place'      => $this->placeJson($shown->place(), null, null),
                        'rela'       => $rela,
                        'role'       => $role,
                        'label2'     => $this->associateLabel($rela, $role, $individual->sex()),
                        'level1'     => $level1,
                        'url'        => $record->url(),
                        // Bei Familien die Partner, damit ein Client den Eintrag oeffnen kann - nur sichtbare
                        'husband'    => $record instanceof Family ? $this->visibleXref($record->husband()) : null,
                        'wife'       => $record instanceof Family ? $this->visibleXref($record->wife()) : null,
                    ];
                }
            }
        }

        $keys = array_keys($data);
        usort($keys, static fn (int $a, int $b): int => $order[$a] <=> $order[$b] ?: $a <=> $b);

        return array_map(static fn (int $key): array => $data[$key], $keys);
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

        // Mit Zeilen: Notizen und andere Texte ueber mehrere Zeilen (CONT) behalten ihre Umbrueche.
        return $this->plainLines(Registry::elementFactory()->make($fact->tag())->value($value, $tree));
    }

    /**
     * Notizen eines Ereignisses (2 NOTE) samt Einordnung: notes (Texte, wie bisher), noteKinds (parallel dazu:
     * "note" oder "associates") und freeAssociates - zuerst aus den GEDCOM-L-Tags "2 _GODP <Text>" (Paten, unter
     * CHR/BAPM) und "2 _WITN <Text>" (Zeugen; GEDCOM-L, webtrees kennt sie), je Person eine Zeile
     * ("Friedrich Plate, Anbauer zu Celle"): name bis zum ersten Komma, detail der Rest; eine Zeile mit ";" zaehlt wie
     * eine Notiz "Paten: …" als Liste. Danach die Eintraege aus solchen Notizen (siehe freeAssociates()).
     *
     * @return array{0:array<int,string>,1:array<int,string>,2:array<int,array<string,mixed>>}
     */
    private function factNotesJson(Fact $fact, Tree $tree): array
    {
        $notes = [];
        $kinds = [];
        $free  = [];

        foreach (['_GODP' => 'godparent', '_WITN' => 'witness'] as $tag => $role) {
            foreach (GedcomText::unterzeilen($fact->gedcom(), 2, $tag) as [$wert, $unter]) {
                $text = GedcomText::mitFortsetzung($wert, $unter, 2);
                $free = [...$free, ...$this->freeEntries($role, str_contains($text, ';') ? $text : $text . ';')];
            }
        }

        foreach ($this->notesFromBlock($fact->gedcom(), 2, $tree) as $text) {
            $entries = $this->freeAssociates($text);
            $notes[] = $text;
            $kinds[] = $entries === null ? 'note' : 'associates';
            $free    = [...$free, ...$entries ?? []];
        }

        return [$notes, $kinds, $free];
    }

    /**
     * Notizen "<ebene> NOTE" in einem Block: Texte mit Fortsetzungen (CONT = neue Zeile, CONC = angehaengt), Verweise
     * auf Notiz-Datensaetze aufgeloest - nur sichtbare. Leere fallen weg.
     *
     * @return array<int,string>
     */
    private function notesFromBlock(string $block, int $ebene, Tree $tree): array
    {
        $notes = [];

        foreach (GedcomText::unterzeilen($block, $ebene, 'NOTE') as [$wert, $unter]) {
            if (preg_match('/^@([^@]+)@$/', trim($wert), $match) === 1) {
                $note = Registry::noteFactory()->make($match[1], $tree);
                $text = $note instanceof Note && $note->canShow() ? $note->getNote() : '';
            } else {
                $text = GedcomText::mitFortsetzung($wert, $unter, $ebene);
            }

            if (trim($text) !== '') {
                $notes[] = $text;
            }
        }

        return $notes;
    }

    /**
     * Freie Paten und Trauzeugen - Personen ohne eigenen Datensatz - aus einer Notiz, die mit "Paten:", "Taufpaten:",
     * "Gevattern:", "Trauzeugen:" oder "Zeugen:" beginnt (Gross-/Kleinschreibung egal). Personen trennt ";", name ist
     * der Text bis zum ersten Komma, detail der Rest. Alte Schreibweise nur mit Kommas: ein Eintrag mit name null und
     * dem ganzen Text - nicht raten. null, wenn die Notiz keine solche Liste ist.
     *
     * @return array<int,array<string,mixed>>|null
     */
    private function freeAssociates(string $text): array|null
    {
        if (preg_match('/^(paten|taufpaten|gevattern|trauzeugen|zeugen):\s*(.*)$/isu', trim($text), $match) !== 1) {
            return null;
        }

        return $this->freeEntries(in_array(mb_strtolower($match[1]), ['trauzeugen', 'zeugen'], true) ? 'witness' : 'godparent', $match[2]);
    }

    /**
     * Die Personen einer Liste "A, Beruf zu Ort; B, …" (siehe freeAssociates()).
     *
     * @return array<int,array<string,mixed>>
     */
    private function freeEntries(string $role, string $text): array
    {
        $rest = GedcomText::line($text);

        if ($rest === '') {
            return [];
        }

        if (!str_contains($rest, ';')) {
            return [['role' => $role, 'name' => null, 'detail' => null, 'text' => $rest]];
        }

        $entries = [];

        foreach (explode(';', $rest) as $teil) {
            $teil = trim($teil);

            if ($teil === '') {
                continue;
            }

            [$name, $detail] = array_pad(array_map(trim(...), explode(',', $teil, 2)), 2, null);
            $entries[]       = ['role' => $role, 'name' => $name, 'detail' => $detail, 'text' => $teil];
        }

        return $entries;
    }

    /**
     * Kopfdaten einer Quelle: Titel, Autor, Publikation, Kurztitel, erstes sichtbares Archiv mit Signatur.
     *
     * @return array<string,mixed>
     */
    private function sourceSummary(Source $source): array
    {
        $attr = static fn (string $tag): string => GedcomText::ersterWert($source->gedcom(), 1, $tag);
        $repo = $this->sourceRepositories($source)[0] ?? null;

        return [
            'xref'         => $source->xref(),
            'title'        => $this->plain($source->fullName()),
            'author'       => $attr('AUTH'),
            'publication'  => $attr('PUBL'),
            'abbreviation' => $attr('ABBR'),
            'repository'   => $repo['name'] ?? '',
            'callNumber'   => $repo['callNumber'] ?? '',
            'canEdit'      => $source->canEdit(),
            'url'          => $source->url(),
        ];
    }

    /**
     * Archive einer Quelle (1 REPO @R1@ mit 2 CALN), nur sichtbare.
     *
     * @return array<int,array{xref:string,name:string,callNumber:string}>
     */
    private function sourceRepositories(Source $source): array
    {
        $data = [];

        foreach (GedcomText::unterzeilen("\n" . $source->gedcom(), 1, 'REPO') as [$wert, $unter]) {
            if (preg_match('/^@([^@]+)@$/', trim($wert), $m) !== 1) {
                continue;
            }
            $repo = Registry::repositoryFactory()->make($m[1], $source->tree());
            if ($repo instanceof Repository && $repo->canShow()) {
                $data[] = ['xref' => $m[1], 'name' => $this->plain($repo->fullName()), 'callNumber' => GedcomText::unterzeilen($unter, 2, 'CALN')[0][0] ?? ''];
            }
        }

        return $data;
    }

    /**
     * Quellenverweise eines Ereignisses (2 SOUR), vollstaendig ab Stufe 18.
     *
     * @return array<int,array<string,mixed>>
     */
    private function factSources(Fact $fact, Tree $tree): array
    {
        // Ein allgemeiner Verweis am Datensatz ("1 SOUR @S1@" mit 2 PAGE ...) ist selbst der Verweis
        if ($this->shortTag($fact->tag()) === 'SOUR') {
            [$kopf, $rest] = array_pad(explode("\n", $fact->gedcom(), 2), 2, '');
            $citation      = $this->citationJson($fact->value(), $rest === '' ? '' : "\n" . $rest, 1, $tree);

            return $citation === null ? [] : [$citation];
        }

        return $this->sourcesFromBlock($fact->gedcom(), 2, $tree);
    }

    /**
     * Quellenverweise "<ebene> SOUR" in einem Block (Ereignis, _ASSO ...), nur sichtbare Quellen.
     *
     * @return array<int,array<string,mixed>>
     */
    private function sourcesFromBlock(string $block, int $ebene, Tree $tree): array
    {
        $sources = [];

        foreach (GedcomText::unterzeilen($block, $ebene, 'SOUR') as [$wert, $unter]) {
            $citation = $this->citationJson($wert, $unter, $ebene, $tree);

            if ($citation !== null) {
                $sources[] = $citation;
            }
        }

        return $sources;
    }

    /**
     * Ein Quellenverweis: die Quelle (Datensatz "@S1@" oder eine Text-Quelle ohne Datensatz, "laut Martha Meier"),
     * Seite (PAGE), Qualitaet (QUAY 0-3), Datum und Text der Fundstelle (DATA/DATE, DATA/TEXT), Notizen und Medien.
     * Eine Quelle, die der Betrachter nicht sehen darf, faellt samt Seite weg - wie in webtrees.
     *
     * @return array<string,mixed>|null
     */
    private function citationJson(string $wert, string $unter, int $ebene, Tree $tree): array|null
    {
        $u = $ebene + 1;

        if (preg_match('/^@([^@]+)@$/', trim($wert), $match) === 1) {
            $source = Registry::sourceFactory()->make($match[1], $tree);

            if ($source === null || !$source->canShow()) {
                return null;
            }

            $xref  = $match[1];
            $title = $this->plain($source->fullName());
        } else {
            $xref  = '';
            $title = GedcomText::mitFortsetzung($wert, $unter, $ebene);
        }

        $eins  = static fn (string $tag): array|null => GedcomText::unterzeilen($unter, $u, $tag)[0] ?? null;
        $page  = $eins('PAGE');
        $quay  = $eins('QUAY');
        $data  = $eins('DATA');
        $datum = $data !== null ? (GedcomText::unterzeilen($data[1], $u + 1, 'DATE')[0][0] ?? '') : '';
        $texte = $data !== null ? array_map(static fn (array $t): string => GedcomText::mitFortsetzung($t[0], $t[1], $u + 1),
            GedcomText::unterzeilen($data[1], $u + 1, 'TEXT')) : [];
        // Eine Text-Quelle darf ihren Text auch direkt unter sich tragen (3 TEXT statt 3 DATA / 4 TEXT)
        if ($xref === '') {
            $texte = [...$texte, ...array_map(static fn (array $t): string => GedcomText::mitFortsetzung($t[0], $t[1], $u),
                GedcomText::unterzeilen($unter, $u, 'TEXT'))];
        }

        $notes = $this->notesFromBlock($unter, $u, $tree);

        $media = [];
        foreach (GedcomText::unterzeilen($unter, $u, 'OBJE') as [$o]) {
            if (preg_match('/^@([^@]+)@$/', trim($o), $om) === 1) {
                $medium = Registry::mediaFactory()->make($om[1], $tree);
                if ($medium instanceof Media && $medium->canShow()) {
                    $media = [...$media, ...$this->mediaFilesJson($medium)];
                }
            }
        }

        return [
            'xref'    => $xref,
            'title'   => $title,
            'page'    => $page !== null ? GedcomText::mitFortsetzung($page[0], $page[1], $u) : '',
            'quality' => $quay !== null && preg_match('/^[0-3]$/', trim($quay[0])) === 1 ? (int) trim($quay[0]) : null,
            'date'    => $datum !== '' ? $this->dateJson(new Date($datum), $datum) : null,
            'text'    => implode("\n\n", $texte),
            'notes'   => $notes,
            'media'   => $media,
        ];
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

    /**
     * Wie plain(), aber Zeilenumbrueche und Absaetze bleiben erhalten. webtrees liefert mehrzeilige Texte als HTML
     * (<br>, <p>); ohne diesen Schritt klebten die Zeilen aneinander ("seines Vaters.In der Familie ...").
     */
    private function plainLines(string $html): string
    {
        $html  = (string) preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html  = (string) preg_replace('/<\/(p|div|li|h[1-6]|blockquote|tr)>/i', "\n\n", $html);
        $lines = explode("\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $lines = array_map(fn (string $line): string => $this->plain($line), $lines);

        // Hoechstens eine Leerzeile zwischen Absaetzen
        return trim((string) preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)));
    }

    private function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Bidi-Steuerzeichen, die webtrees um Namen und Daten legt
        $text = str_replace(["\u{202A}", "\u{202B}", "\u{202C}", "\u{200E}", "\u{200F}", "\u{2068}", "\u{2069}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
