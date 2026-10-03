<?php

declare(strict_types=1);

namespace Api4Webtrees;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Location;
use Fisharebest\Webtrees\Note;
use Fisharebest\Webtrees\PlaceLocation;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomService;
use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function abs;
use function array_flip;
use function array_key_exists;
use function array_pad;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function arsort;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function ltrim;
use function mb_strlen;
use function mb_substr;
use function md5;
use function max;
use function mb_strtolower;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function preg_split;
use function response;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_ends_with;
use function trim;
use function usort;

use const PREG_SET_ORDER;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Orte (ab Stufe 21): Ortsliste und ein Ort mit allem, was dazu im Baum steht.
 *
 * Ein Ort ist der PLAC-Text, wie er am Ereignis steht ("Allenstein, Ostpreußen"). Gezeigt wird nur, was an sichtbaren
 * Ereignissen sichtbarer Personen und Familien steht - die Ortstabelle von webtrees kennt auch die Orte verborgener
 * Personen. Ortsdaten (GOV-Kennung, Koordinaten, Notiz, Quellen, Medien) kommen aus dem GEDCOM-L-Datensatz _LOC,
 * zugeordnet wie im Modul Ortsregister: Verweis am Ereignis (3 _LOC), dessen gespeicherte Bindung, GOV-Kennung,
 * zuletzt der Blattname, wenn er auf beiden Seiten eindeutig ist. Koordinaten: _LOC, dann "Geografische Daten" der
 * Verwaltung, dann 3 MAP am Ereignis.
 */
trait PlaceActions
{
    /** Personen und Familien je Ort hoechstens (wie bei Source). */
    private int $placeRecordLimit = 1000;

    /**
     * ?name=<Ort, wie er am Ereignis steht>
     */
    public function getPlaceAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $name = trim(Validator::queryParams($request)->string('name', ''));
        $key  = mb_strtolower($name);

        if ($name === '') {
            return $this->error(400, 'name-missing');
        }

        $ids = $this->placeIds($tree);
        $id  = $ids[$key] ?? null;

        // Datensaetze an diesem Ort oder darunter (webtrees verknuepft jede Ebene einzeln) - geladen werden nur die,
        // in deren GEDCOM der Name ueberhaupt vorkommt; ob er wirklich am Ereignis steht, prueft placeUsage().
        $records = $id === null ? [] : $this->linkedRecords($tree, $id, $name);
        $usage   = $this->placeUsage($records, true, $key);
        $here    = $usage[$key] ?? null;

        // Orte direkt darunter aus der Ortstabelle - genannt wird einer nur, wenn ein sichtbarer Datensatz dort liegt.
        $children = [];
        if ($id !== null) {
            $rows = DB::table('places')->where('p_file', '=', $tree->id())->where('p_parent_id', '=', $id)->get(['p_id', 'p_place']);
            foreach ($rows as $row) {
                if ($this->placeVisible($tree, (int) $row->p_id)) {
                    $children[] = ['name' => $row->p_place . ', ' . ($here['name'] ?? $name)];
                }
            }
        }

        if ($here === null && $children === []) {
            return $this->error(404, 'not-found');
        }

        $here ??= ['name' => $name, 'events' => 0, 'individuals' => [], 'families' => [], 'lat' => null, 'lng' => null, 'locs' => [], 'facts' => []];
        $context  = $this->placeContext($tree);
        $location = $this->placeLocation($tree, $here, $context);
        [$lat, $lng, $source] = $this->placeCoordinates($here, $location, $context);

        // Erst nur Namen, sortieren und auf die gezeigte Zahl kuerzen - Lebensdaten und Ereignistexte kosten je
        // Person Zeit und werden nur fuer die gelieferten gebraucht.
        $comparator = I18N::comparator();
        $individuals = [];
        $families    = [];
        foreach ($here['facts'] as $facts) {
            $record = $facts[0]->record();
            $entry  = ['name' => $this->plain($record->fullName()), 'record' => $record, 'facts' => $facts];
            if ($record instanceof Individual) {
                $individuals[] = $entry;
            } elseif ($record instanceof Family) {
                $families[] = $entry;
            }
        }
        usort($individuals, static fn (array $a, array $b): int => $comparator($a['name'], $b['name']));
        usort($families, static fn (array $a, array $b): int => $comparator($a['name'], $b['name']));
        usort($children, static fn (array $a, array $b): int => $comparator($a['name'], $b['name']));

        $events = fn (array $facts): array => array_map(fn (Fact $fact): array => [
            'tag'   => $this->shortTag($fact->tag()),
            'label' => $this->factLabel($fact),
            'date'  => $this->dateJson($fact->date()),
        ], $facts);

        $individualsJson = array_map(fn (array $e): array => [
            'xref'     => $e['record']->xref(),
            'name'     => $e['name'],
            'sex'      => $e['record']->sex(),
            'private'  => false,
            'lifespan' => $this->plain($e['record']->lifespan()),
            'url'      => $e['record']->url(),
            'facts'    => $events($e['facts']),
        ], array_slice($individuals, 0, $this->placeRecordLimit));

        $familiesJson = array_map(fn (array $e): array => [
            'xref'    => $e['record']->xref(),
            'name'    => $e['name'],
            'husband' => $e['record']->husband() instanceof Individual && $e['record']->husband()->canShowName() ? $e['record']->husband()->xref() : null,
            'wife'    => $e['record']->wife() instanceof Individual && $e['record']->wife()->canShowName() ? $e['record']->wife()->xref() : null,
            'facts'   => $events($e['facts']),
        ], array_slice($families, 0, $this->placeRecordLimit));

        $levels = explode(', ', $here['name']);

        // Ereignisse nach Art (wie die Kacheln im Ortsregister): alle sichtbaren, nicht nur die gelieferten Personen
        $zaehlen = ['birth' => 0, 'marriage' => 0, 'death' => 0, 'other' => 0];
        foreach ($here['facts'] as $facts) {
            foreach ($facts as $fact) {
                $tag = explode(':', $fact->tag())[1] ?? $fact->tag();
                $zaehlen[match ($tag) { 'BIRT' => 'birth', 'MARR' => 'marriage', 'DEAT' => 'death', default => 'other' }]++;
            }
        }

        return response([
            'name'            => $here['name'],
            'levels'          => $levels,
            'parent'          => count($levels) > 1 ? implode(', ', array_slice($levels, 1)) : null,
            'children'        => $children,
            'events'          => $here['events'],
            'eventCounts'     => $zaehlen,
            'lat'             => $lat,
            'lng'             => $lng,
            'coordSource'     => $source,
            'location'        => $location === null ? null : $this->locationJson($location),
            'individuals'     => $individualsJson,
            'families'        => $familiesJson,
            'moreIndividuals' => max(0, count($individuals) - $this->placeRecordLimit),
            'moreFamilies'    => max(0, count($families) - $this->placeRecordLimit),
            'canEdit'         => Auth::isEditor($tree),
        ]);
    }

    /**
     * Ortsdaten speichern (ab Stufe 22): Rumpf { name, gov?, lat?, lng?, note?, mapData? }. Geschrieben wird in den
     * _LOC-Datensatz des Orts (GEDCOM-L); fehlt er, wird er angelegt. Nur die genannten Teile werden ersetzt, alles
     * andere am _LOC bleibt. lat/lng null entfernt die Koordinaten. Ist der Blattname nicht eindeutig, bekommen die
     * Ereignisse am Ort den Verweis "3 _LOC @L1@" - sonst faende sich der neue _LOC nicht wieder. mapData: true traegt
     * die Koordinaten fuer Administratoren zusaetzlich in die Geografischen Daten von webtrees ein (die Karten von
     * webtrees lesen nur diese und MAP am Ereignis).
     */
    public function postPlaceAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $body = $this->body($request);
        $name = $this->placeName($this->str($body, 'name'));
        $key  = mb_strtolower($name);

        if ($name === '') {
            return $this->error(400, 'name-missing');
        }

        if (!Auth::isEditor($tree)) {
            return $this->error(403, 'not-editable');
        }

        foreach (['gov', 'note', 'postalCode', 'region', 'country', 'shortName'] as $feld) {
            if (GedcomText::looksLikePointer($this->str($body, $feld))) {
                return $this->error(400, 'invalid-value');
            }
        }

        $gov = trim($this->str($body, 'gov'));
        if ($gov !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $gov) !== 1) {
            return $this->error(400, 'invalid-gov');
        }

        $koordinaten = array_key_exists('lat', $body) || array_key_exists('lng', $body);
        $lat         = $body['lat'] ?? null;
        $lng         = $body['lng'] ?? null;
        if ($koordinaten && (($lat === null) !== ($lng === null)
            || ($lat !== null && (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180)))) {
            return $this->error(400, 'invalid-coordinates');
        }

        $id      = $this->placeIds($tree)[$key] ?? null;
        $records = $id === null ? [] : $this->linkedRecords($tree, $id, $name);
        $here    = $this->placeUsage($records, true, $key)[$key] ?? null;

        if ($here === null) {
            return $this->error(404, 'not-found');
        }

        $context  = $this->placeContext($tree);
        $location = $this->placeLocation($tree, $here, $context);
        $status   = 200;
        $linked   = 0;

        if ($location === null) {
            $leaf     = explode(', ', $name)[0];
            $location = $tree->createRecord($this->locationGedcom('0 @@ _LOC' . "\n1 NAME " . GedcomText::line($leaf), $body));
            $status   = 201;

            // Nur ueber den Namen wiederzufinden, wenn er auf beiden Seiten eindeutig ist - sonst Verweise setzen.
            $leafKey = mb_strtolower($leaf);
            if (($context['leaves'][$leafKey] ?? 0) > 1 || ($context['byName'][$leafKey] ?? []) !== []) {
                $linked = $this->linkEvents($here, $location->xref());
            }
        } else {
            $denied = $this->denyEdit($location);
            if ($denied !== null) {
                return $denied;
            }
            $location->updateRecord($this->locationGedcom($location->gedcom(), $body), true);
        }

        $mapData = false;
        if (($body['mapData'] ?? false) === true && Auth::isAdmin() && $koordinaten) {
            $place = new PlaceLocation($name);
            DB::table('place_location')->where('id', '=', $place->id())->update([
                'latitude'  => $lat === null ? null : (float) $lat,
                'longitude' => $lng === null ? null : (float) $lng,
            ]);
            $mapData = true;
        }

        return $this->written($location, ['linked' => $linked, 'mapData' => $mapData], $status);
    }

    /**
     * Das GEDCOM eines _LOC mit den im Rumpf genannten Teilen ersetzt; alles andere bleibt.
     *
     * @param array<string,mixed> $body
     */
    private function locationGedcom(string $alt, array $body): string
    {
        [$kopf, $rest] = array_pad(explode("\n", $alt, 2), 2, '');
        $rest = $rest === '' ? '' : "\n" . $rest;

        if (array_key_exists('gov', $body)) {
            $gov  = trim($this->str($body, 'gov'));
            $rest = (string) preg_replace('/\n1 _GOV(?: [^\n]*)?(?:\n[2-9] [^\n]*)*/', '', $rest);
            $rest .= $gov === '' ? '' : "\n1 _GOV " . $gov;
        }

        if (array_key_exists('lat', $body) || array_key_exists('lng', $body)) {
            $rest = (string) preg_replace('/\n1 MAP(?:\n[2-9] [^\n]*)*/', '', $rest);
            if (($body['lat'] ?? null) !== null && ($body['lng'] ?? null) !== null) {
                $rest .= "\n1 MAP\n2 LATI " . $this->gedcomDegrees((float) $body['lat'], 'N', 'S') . "\n2 LONG " . $this->gedcomDegrees((float) $body['lng'], 'E', 'W');
            }
        }

        if (array_key_exists('note', $body)) {
            $note = GedcomText::multiline($this->str($body, 'note'), 2);
            // Nur die eingebettete Notiz ersetzen; Verweise auf Notiz-Datensaetze bleiben
            $rest = (string) preg_replace('/\n1 NOTE (?!@)[^\n]*(\n2 CON[CT][^\n]*)*/', '', $rest);
            $rest .= $note === '' ? '' : "\n1 NOTE " . $note;
        }

        // Kurzname: "2 ABBR" unter dem ersten "1 NAME" ersetzen; NAME selbst und seine anderen Unterzeilen bleiben
        if (array_key_exists('shortName', $body)) {
            $kurz = GedcomText::line($this->str($body, 'shortName'));
            $rest = (string) preg_replace_callback('/(\n1 NAME[^\n]*)((?:\n[2-9] [^\n]*)*)/', static function (array $m) use ($kurz): string {
                $unter = (string) preg_replace('/\n2 ABBR(?: [^\n]*)?(?:\n[3-9] [^\n]*)*/', '', $m[2]);

                return $m[1] . ($kurz === '' ? '' : "\n2 ABBR " . $kurz) . $unter;
            }, $rest, 1);
        }

        // Postleitzahl, Region, Land - vorhandene Schreibweise (POST/_POST) bleibt, neu als _POST
        foreach (['postalCode' => ['_POST', 'POST'], 'region' => ['_STAE'], 'country' => ['_CTRY']] as $feld => $tags) {
            if (array_key_exists($feld, $body)) {
                $wert = GedcomText::line($this->str($body, $feld));
                $tag  = $tags[0];
                foreach ($tags as $t) {
                    if (preg_match('/\n1 ' . $t . '\b/', $rest) === 1) {
                        $tag = $t;
                    }
                    $rest = (string) preg_replace('/\n1 ' . $t . '(?: [^\n]*)?(?:\n[2-9] [^\n]*)*/', '', $rest);
                }
                $rest .= $wert === '' ? '' : "\n1 " . $tag . ' ' . $wert;
            }
        }

        // Die verknuepften Medienobjekte (wie bei Source): die Liste ersetzt alle "1 OBJE @M@"
        if (array_key_exists('media', $body) && is_array($body['media'])) {
            $rest = (string) preg_replace('/\n1 OBJE @[^\n]*(?:\n[2-9] [^\n]*)*/', '', $rest);
            foreach ($body['media'] as $m) {
                if (preg_match('/^@?([A-Za-z0-9:_.-]+)@?$/', (string) $m, $mm) === 1) {
                    $rest .= "\n1 OBJE @" . $mm[1] . '@';
                }
            }
        }

        return $kopf . $rest;
    }

    /** 53.778417 -> "N53.778417" (GEDCOM: Himmelsrichtung und Dezimalgrad, ohne angehaengte Nullen) */
    private function gedcomDegrees(float $wert, string $plus, string $minus): string
    {
        $zahl = rtrim(rtrim(sprintf('%.6F', abs($wert)), '0'), '.');

        return ($wert < 0 ? $minus : $plus) . $zahl;
    }

    /**
     * "3 _LOC @L1@" unter das PLAC aller Ereignisse am Ort, die es noch nicht haben und bearbeitet werden duerfen.
     *
     * @param array{facts:array<string,list<Fact>>} $here
     */
    private function linkEvents(array $here, string $xref): int
    {
        $n = 0;
        foreach ($here['facts'] as $facts) {
            foreach ($facts as $fact) {
                if (!$fact->canEdit() || preg_match('/\n3 _LOC /', $fact->gedcom()) === 1) {
                    continue;
                }
                $neu = (string) preg_replace('/(\n2 PLAC [^\n]*(?:\n[3-9] [^\n]*)*)/', '$1' . "\n3 _LOC @" . $xref . '@', $fact->gedcom(), 1);
                $fact->record()->updateFact($fact->id(), $neu, true);
                $n++;
            }
        }

        return $n;
    }

    /**
     * Ort umbenennen oder zusammenfuehren (ab Stufe 23): Rumpf { from, to, preview? }. Jedes Ereignis mit dem Ort
     * "from" bekommt "to"; Orte darunter wandern mit ("Kortau, Allenstein" -> "Kortau, Olsztyn"). Gibt es "to" schon,
     * ist es ein Zusammenfuehren: die beiden _LOC werden zu einem (Luecken fuellen, Notizen, Quellen und Medien
     * anhaengen, Abweichungen melden), die Verweise "3 _LOC" zeigen danach auf ihn. Ereignisse, die der Benutzer nicht
     * bearbeiten darf (gesperrt, vertraulich), bleiben und werden gezaehlt. preview: true aendert nichts und liefert
     * nur die Zahlen. Ohne Sofortfreigabe entstehen gewoehnliche ausstehende Aenderungen.
     */
    public function postPlaceRenameAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree    = Validator::attributes($request)->tree();
        $body    = $this->body($request);
        $from    = $this->placeName($this->str($body, 'from'));
        $to      = $this->placeName($this->str($body, 'to'));
        $preview = ($body['preview'] ?? false) === true;

        if ($from === '' || $to === '') {
            return $this->error(400, 'name-missing');
        }

        if (!Auth::isEditor($tree)) {
            return $this->error(403, 'not-editable');
        }

        if (GedcomText::looksLikePointer($to) || str_contains($to, "\n")) {
            return $this->error(400, 'invalid-value');
        }

        $fromKey = mb_strtolower($from);
        $toKey   = mb_strtolower($to);
        $ids     = $this->placeIds($tree);
        $fromId  = $ids[$fromKey] ?? null;

        // Der Ort muss fuer den Benutzer sichtbar an einem Ereignis stehen - sonst gibt es ihn fuer ihn nicht.
        $sichtbar = $fromId === null ? [] : $this->linkedRecords($tree, $fromId, $from);
        $here     = $this->placeUsage($sichtbar, true, $fromKey)[$fromKey] ?? null;
        if ($here === null && ($fromId === null || !$this->placeVisible($tree, $fromId))) {
            return $this->error(404, 'not-found');
        }

        $here ??= ['name' => $from, 'events' => 0, 'individuals' => [], 'families' => [], 'lat' => null, 'lng' => null, 'locs' => [], 'facts' => []];
        // Zusammenfuehren nur, wenn am Ziel noch etwas haengt - webtrees laesst alte Orte in seiner Ortstabelle stehen
        $merge   = $fromKey !== $toKey && isset($ids[$toKey])
            && DB::table('placelinks')->where('pl_file', '=', $tree->id())->where('pl_p_id', '=', $ids[$toKey])->exists();
        $context = $this->placeContext($tree);
        $fromLoc = $this->placeLocation($tree, $here, $context);
        $toLoc   = null;
        $ziel    = null;

        if ($merge) {
            $zielRecords = $this->linkedRecords($tree, $ids[$toKey], $to);
            $ziel        = $this->placeUsage($zielRecords, true, $toKey)[$toKey] ?? null;
            $toLoc       = $ziel === null ? null : $this->placeLocation($tree, $ziel, $context);
        }
        if ($toLoc !== null && $fromLoc !== null && $toLoc->xref() === $fromLoc->xref()) {
            $toLoc = null;
        }

        // Alle Personen und Familien an "from" oder darunter, auch die der Benutzer nicht sieht (die werden gezaehlt)
        $rows = [];
        foreach (['individuals' => ['i', Registry::individualFactory()->mapper($tree)], 'families' => ['f', Registry::familyFactory()->mapper($tree)]] as $table => [$p, $mapper]) {
            $q = DB::table($table)
                ->join('placelinks', static function ($join) use ($p): void {
                    $join->on('pl_gid', '=', $p . '_id')->on('pl_file', '=', $p . '_file');
                })
                ->where($p . '_file', '=', $tree->id())
                ->where('pl_p_id', '=', $fromId ?? -1)
                ->select([$table . '.*'])
                ->get();
            foreach ($q as $row) {
                $rows[] = $mapper($row);
            }
        }

        $zielLoc   = $toLoc ?? $fromLoc;
        $aenderung = [];
        $events    = 0;
        $skipped   = 0;
        $unterorte = [];

        foreach ($rows as $record) {
            $darf  = $record->canShow() && $record->canEdit();
            $neu   = $record->gedcom();
            $n     = 0;
            $neu   = (string) preg_replace_callback('/\n1 \S+[^\n]*(?:\n[2-9] [^\n]*)*/', function (array $m) use ($record, $fromKey, $to, $from, $darf, $zielLoc, $fromLoc, &$n, &$skipped, &$unterorte): string {
                $block = $m[0];
                if (preg_match('/\n2 PLAC ([^\n]*)/', $block, $pl) !== 1) {
                    return $block;
                }
                $name = $this->placeName($pl[1]);
                $key  = mb_strtolower($name);
                $genau = $key === $fromKey;
                if (!$genau && !str_ends_with($key, ', ' . $fromKey)) {
                    return $block;
                }
                $fact = new Fact(ltrim($block, "\n"), $record, md5(ltrim($block, "\n")));
                if (!$darf || !$fact->canShow() || !$fact->canEdit()) {
                    $skipped++;
                    return $block;
                }
                $neuName = $genau ? $to : mb_substr($name, 0, mb_strlen($name) - mb_strlen($from)) . $to;
                if (!$genau) {
                    $unterorte[$key] = true;
                }
                $block = str_replace($pl[0], "\n2 PLAC " . $neuName, $block);
                // Der Verweis auf den _LOC: beim Zusammenfuehren auf den bleibenden; am Ort selbst immer setzen, wenn
                // es einen _LOC gibt - nach dem Umbenennen findet ihn der Name vielleicht nicht mehr eindeutig.
                if ($genau && $zielLoc !== null) {
                    $block = (string) preg_replace('/\n3 _LOC @[^@]*@/', '', $block);
                    $block = (string) preg_replace('/(\n2 PLAC [^\n]*(?:\n[3-9] [^\n]*)*)/', '$1' . "\n3 _LOC @" . $zielLoc->xref() . '@', $block, 1);
                } elseif ($fromLoc !== null && $zielLoc !== null) {
                    $block = str_replace('@' . $fromLoc->xref() . '@', '@' . $zielLoc->xref() . '@', $block);
                }
                $n++;

                return $block;
            }, "\n" . $neu);
            $neu = ltrim($neu, "\n");

            if ($n > 0) {
                $aenderung[] = [$record, $neu];
                $events += $n;
            }
        }

        $konflikte = $toLoc !== null && $fromLoc !== null ? $this->locConflicts($fromLoc, $toLoc) : [];
        $antwort   = [
            'from'      => $from,
            'to'        => $to,
            'merge'     => $merge,
            'records'   => count($aenderung),
            'events'    => $events,
            'subPlaces' => count($unterorte),
            'skipped'   => $skipped,
            'location'  => ['from' => $fromLoc?->xref(), 'to' => $toLoc?->xref(), 'conflicts' => $konflikte],
        ];

        if ($preview) {
            return response(['ok' => true, 'preview' => true] + $antwort);
        }

        foreach ($aenderung as [$record, $neu]) {
            $record->updateRecord($neu, true);
        }

        if ($fromLoc !== null && $toLoc !== null) {
            // Zusammenfuehren: alles vom alten _LOC in den bleibenden, dann den alten loeschen - aber nur, wenn
            // nichts mehr auf ihn zeigt (uebersprungene Ereignisse behalten ihren Verweis).
            if ($toLoc->canEdit()) {
                $toLoc->updateRecord($this->locMerge($toLoc->gedcom(), $fromLoc->gedcom()), true);
            }
            $rest = DB::table('link')->where('l_file', '=', $tree->id())->where('l_to', '=', $fromLoc->xref())->count();
            if ($skipped === 0 && $rest === 0 && $fromLoc->canEdit()) {
                $fromLoc->deleteRecord();
            }
        } elseif ($fromLoc !== null && $fromLoc->canEdit()) {
            // Umbenannt (oder in einen Ort ohne _LOC zusammengefuehrt): der _LOC heisst wie der neue Blattname
            if ($merge && $ziel !== null) {
                $this->linkEvents($ziel, $fromLoc->xref());
            }
            $leaf = explode(', ', $to)[0];
            $neu  = (string) preg_replace('/\n1 NAME [^\n]*/', "\n1 NAME " . GedcomText::line($leaf), $fromLoc->gedcom(), 1);
            if ($neu !== $fromLoc->gedcom()) {
                $fromLoc->updateRecord($neu, true);
            }
        }

        $pending = DB::table('change')->where('gedcom_id', '=', $tree->id())->where('status', '=', 'pending')
            ->whereIn('xref', array_map(static fn (array $a): string => $a[0]->xref(), $aenderung))->exists();

        return response(['ok' => true, 'preview' => false, 'pending' => $pending] + $antwort);
    }

    /**
     * Was beim Zusammenfuehren zweier _LOC nicht zusammenpasst: abweichende GOV-Kennung oder Koordinaten.
     *
     * @return list<string>
     */
    private function locConflicts(Location $from, Location $to): array
    {
        $konflikte = [];
        $govA = $this->locGov($from);
        $govB = $this->locGov($to);
        if ($govA !== null && $govB !== null && $govA !== $govB) {
            $konflikte[] = 'gov';
        }
        [$la, $lo] = $this->locCoordinates($from);
        [$lb, $lob] = $this->locCoordinates($to);
        if ($la !== null && $lb !== null && (abs($la - $lb) > 0.01 || abs($lo - $lob) > 0.01)) {
            $konflikte[] = 'coordinates';
        }

        return $konflikte;
    }

    /**
     * Den alten _LOC in den bleibenden einarbeiten: GOV und Koordinaten nur, wo sie fehlen; Notizen, Quellen, Medien
     * und alles Weitere angehaengt, wenn es so nicht schon dasteht. NAME, CHAN und die Kennung bleiben die des Ziels.
     */
    private function locMerge(string $ziel, string $alt): string
    {
        preg_match_all('/\n1 (\S+)[^\n]*(?:\n[2-9] [^\n]*)*/', "\n" . $alt, $bloecke, PREG_SET_ORDER);
        foreach ($bloecke as [$block, $tag]) {
            if (in_array($tag, ['NAME', 'CHAN', '_UID'], true)) {
                continue;
            }
            if (in_array($tag, ['_GOV', 'MAP'], true) && preg_match('/\n1 ' . $tag . '\b/', $ziel) === 1) {
                continue;
            }
            if (!str_contains($ziel, $block)) {
                $ziel .= $block;
            }
        }

        return $ziel;
    }

    /**
     * Places?list=1: alle Orte mit Zahl der Ereignisse, Personen und Familien, Koordinaten und _LOC.
     */
    private function placeList(Tree $tree): ResponseInterface
    {
        $records = [];
        foreach (['individuals' => 'i', 'families' => 'f'] as $table => $p) {
            $mapper = $p === 'i' ? Registry::individualFactory()->mapper($tree) : Registry::familyFactory()->mapper($tree);
            $rows   = DB::table($table)->where($p . '_file', '=', $tree->id())->where($p . '_gedcom', 'LIKE', '%PLAC %')->get();

            foreach ($rows as $row) {
                $record = $mapper($row);
                if ($record->canShow()) {
                    $records[] = $record;
                }
            }
        }

        $context = $this->placeContext($tree);
        $places  = [];

        foreach ($this->placeUsageFast($tree, $records) as $u) {
            $location = $this->placeLocation($tree, $u, $context);
            [$lat, $lng, $source] = $this->placeCoordinates($u, $location, $context);
            $places[] = [
                'name'        => $u['name'],
                'events'      => $u['events'],
                'individuals' => count($u['individuals']),
                'families'    => count($u['families']),
                'lat'         => $lat,
                'lng'         => $lng,
                'coordSource' => $source,
                'location'    => $location?->xref(),
                'gov'         => $location === null ? null : $this->locGov($location),
                'shortName'   => $location === null ? null : $this->locKurz($location),
            ];
        }

        $comparator = I18N::comparator();
        usort($places, static fn (array $a, array $b): int => $comparator($a['name'], $b['name']));

        return response(['total' => count($places), 'places' => $places]);
    }

    /**
     * Sichtbare Personen und Familien, die mit dem Ort verknuepft sind und seinen Namen im GEDCOM tragen.
     *
     * @return list<GedcomRecord>
     */
    private function linkedRecords(Tree $tree, int $placeId, string $name): array
    {
        // "2 PLAC Kortau, Allenstein" - genau dieser Ort, Leerzeichen um die Kommas wie bei webtrees beliebig
        $teile   = array_map(static fn (string $t): string => preg_quote($t, '/'), explode(', ', $name));
        $muster  = '/\n2 PLAC ' . implode(' *,[, ]*', $teile) . ' *(?:\n|$)/iu';
        $records = [];

        foreach (['individuals' => ['i', Registry::individualFactory()->mapper($tree)], 'families' => ['f', Registry::familyFactory()->mapper($tree)]] as $table => [$p, $mapper]) {
            $rows = DB::table($table)
                ->join('placelinks', static function ($join) use ($p): void {
                    $join->on('pl_gid', '=', $p . '_id')->on('pl_file', '=', $p . '_file');
                })
                ->where($p . '_file', '=', $tree->id())
                ->where('pl_p_id', '=', $placeId)
                ->select([$table . '.*'])
                ->get();

            foreach ($rows as $row) {
                if (preg_match($muster, (string) $row->{$p . '_gedcom'}) !== 1) {
                    continue;
                }
                $record = $mapper($row);
                if ($record->canShow()) {
                    $records[] = $record;
                }
            }
        }

        return $records;
    }

    /** Liegt wenigstens ein sichtbarer Datensatz an diesem Ort (oder darunter)? Bricht beim ersten Treffer ab. */
    private function placeVisible(Tree $tree, int $placeId): bool
    {
        $gids = DB::table('placelinks')->where('pl_file', '=', $tree->id())->where('pl_p_id', '=', $placeId)->pluck('pl_gid');

        foreach ($gids as $gid) {
            $record = Registry::gedcomRecordFactory()->make((string) $gid, $tree);
            if (($record instanceof Individual || $record instanceof Family) && $record->canShow()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wo welcher Ort an sichtbaren Ereignissen steht, je Ort (Schluessel: Name in Kleinbuchstaben).
     *
     * @param list<GedcomRecord> $records
     *
     * @return array<string,array{name:string,events:int,individuals:array<string,true>,families:array<string,true>,lat:float|null,lng:float|null,locs:array<string,int>,facts:array<string,list<Fact>>}>
     */
    private function placeUsage(array $records, bool $keepFacts = true, string|null $only = null): array
    {
        $usage = [];

        foreach ($records as $record) {
            foreach ($this->placeFacts($record, $only) as $fact) {
                if (!$fact->canShow()) {
                    continue;
                }

                $name = $fact->place()->gedcomName();
                if ($name === '') {
                    continue;
                }

                $key = mb_strtolower($name);
                $usage[$key] ??= ['name' => $name, 'events' => 0, 'individuals' => [], 'families' => [], 'lat' => null, 'lng' => null, 'locs' => [], 'facts' => []];
                $u   = &$usage[$key];
                $u['events']++;
                $u[$record instanceof Family ? 'families' : 'individuals'][$record->xref()] = true;

                if ($u['lat'] === null && $fact->latitude() !== null && $fact->longitude() !== null) {
                    $u['lat'] = $fact->latitude();
                    $u['lng'] = $fact->longitude();
                }

                if (preg_match('/\n3 _LOC @([^@]+)@/', $fact->gedcom(), $m) === 1) {
                    $u['locs'][$m[1]] = ($u['locs'][$m[1]] ?? 0) + 1;
                }

                if ($keepFacts) {
                    $u['facts'][$record->xref()][] = $fact;
                }

                unset($u);
            }
        }

        return $usage;
    }

    /**
     * Die Ereignisse eines Datensatzes - mit [$only] nur die an diesem Ort (Name in Kleinbuchstaben), ohne die
     * uebrigen erst als Fact zu bauen.
     *
     * @return iterable<Fact>
     */
    private function placeFacts(GedcomRecord $record, string|null $only): iterable
    {
        if ($only === null || $this->exactPath($record)) {
            return $record->facts();
        }

        preg_match_all('/\n(1 \S+[^\n]*(?:\n[2-9] [^\n]*)*)/', $record->gedcom(), $bloecke);
        $facts = [];
        foreach ($bloecke[1] as $block) {
            if (preg_match('/\n2 PLAC ([^\n]*)/', $block, $m) === 1 && mb_strtolower($this->placeName($m[1])) === $only) {
                $facts[] = new Fact($block, $record, md5($block));
            }
        }

        return $facts;
    }

    /** Ortsname wie bei webtrees: Teile ohne leere, mit ", " getrennt. */
    private function placeName(string $plac): string
    {
        return implode(', ', preg_split('/ *,[, ]*/', trim($plac), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Muss dieser Datensatz ueber facts() und canShow() gehen? Ja, wenn ein Ereignis darin eine eigene Sperre haben
     * kann: RESN am Ereignis, eine Datenschutz-Regel des Baums fuer eine Ereignisart mit Ort (webtrees legt fuer jeden
     * neuen Baum einige an, etwa SSN) oder fuer Ereignisse dieser Person.
     */
    private function exactPath(GedcomRecord $record): bool
    {
        $tree   = $record->tree();
        $gedcom = $record->gedcom();

        if (isset($tree->getIndividualFactPrivacy()[$record->xref()]) || preg_match('/\n2 RESN /', $gedcom) === 1) {
            return true;
        }

        $regeln = $tree->getFactPrivacy();
        if ($regeln !== [] && preg_match_all('/\n1 (\S+)[^\n]*((?:\n[2-9] [^\n]*)*)/', $gedcom, $bloecke) > 0) {
            foreach ($bloecke[1] as $i => $tag) {
                if (isset($regeln[$tag]) && str_contains($bloecke[2][$i], "\n2 PLAC ")) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Wie placeUsage(), aber fuer viele Datensaetze: liest PLAC, MAP und _LOC direkt aus dem GEDCOM-Text statt jedes
     * Ereignis als Fact zu bauen (bei 50.000 Personen zehnmal schneller). Wo ein Ereignis eine eigene Sperre haben
     * kann - RESN am Ereignis, Datenschutz-Regeln des Baums je Ereignisart oder je Person -, nimmt es den genauen Weg.
     *
     * @param list<GedcomRecord> $records
     *
     * @return array<string,array<string,mixed>>
     */
    private function placeUsageFast(Tree $tree, array $records): array
    {
        $usage   = [];
        $service = new GedcomService();

        foreach ($records as $record) {
            if ($this->exactPath($record)) {
                foreach ($this->placeUsage([$record], false) as $key => $u) {
                    $this->placeMerge($usage, $key, $u);
                }
                continue;
            }

            preg_match_all('/\n1 \S+[^\n]*((?:\n[2-9] [^\n]*)*)/', $record->gedcom(), $bloecke);
            foreach ($bloecke[1] as $block) {
                if (preg_match('/\n2 PLAC ([^\n]*)/', $block, $m) !== 1) {
                    continue;
                }
                $name = $this->placeName($m[1]);
                if ($name === '') {
                    continue;
                }

                $u = ['name' => $name, 'events' => 1, 'individuals' => [], 'families' => [], 'lat' => null, 'lng' => null, 'locs' => [], 'facts' => []];
                $u[$record instanceof Family ? 'families' : 'individuals'][$record->xref()] = true;
                if (preg_match('/\n4 LATI ([^\n]+)/', $block, $la) === 1 && preg_match('/\n4 LONG ([^\n]+)/', $block, $lo) === 1) {
                    $u['lat'] = $service->readLatitude($la[1]);
                    $u['lng'] = $service->readLongitude($lo[1]);
                }
                if (preg_match('/\n3 _LOC @([^@]+)@/', $block, $l) === 1) {
                    $u['locs'][$l[1]] = 1;
                }
                $this->placeMerge($usage, mb_strtolower($name), $u);
            }
        }

        return $usage;
    }

    /**
     * @param array<string,array<string,mixed>> $usage
     * @param array<string,mixed>               $u
     */
    private function placeMerge(array &$usage, string $key, array $u): void
    {
        if (!isset($usage[$key])) {
            $usage[$key] = $u;
            return;
        }

        $usage[$key]['events'] += $u['events'];
        $usage[$key]['individuals'] += $u['individuals'];
        $usage[$key]['families'] += $u['families'];
        if ($usage[$key]['lat'] === null && $u['lat'] !== null) {
            $usage[$key]['lat'] = $u['lat'];
            $usage[$key]['lng'] = $u['lng'];
        }
        foreach ($u['locs'] as $x => $n) {
            $usage[$key]['locs'][$x] = ($usage[$key]['locs'][$x] ?? 0) + $n;
        }
    }

    /**
     * Was fuer die Zuordnung Ort -> _LOC und fuer Koordinaten einmal je Abruf gelesen wird.
     *
     * @return array{locs:array<string,Location>,byName:array<string,list<string>>,byGov:array<string,list<string>>,leaves:array<string,int>,bound:array<string,string>,boundGov:array<string,string>,mapData:array<string,array{0:float,1:float}>}
     */
    private function placeContext(Tree $tree): array
    {
        $locs   = [];
        $byName = [];
        $byGov  = [];

        $rows      = DB::table('other')->where('o_file', '=', $tree->id())->where('o_type', '=', '_LOC')->get();
        $locations = $rows->map(Registry::locationFactory()->mapper($tree))->all();

        // Neu angelegte, noch nicht freigegebene _LOC sieht ein Bearbeiter schon - sonst legte das zweite Speichern
        // vor der Freigabe einen zweiten an.
        if (Auth::isEditor($tree)) {
            $neu = DB::table('change')->where('gedcom_id', '=', $tree->id())->where('status', '=', 'pending')
                ->where('old_gedcom', '=', '')->where('new_gedcom', 'LIKE', '0 @%@ _LOC%')->pluck('xref');
            foreach ($neu as $xref) {
                $locations[] = Registry::locationFactory()->make((string) $xref, $tree);
            }
        }

        foreach ($locations as $location) {
            if (!$location instanceof Location || !$location->canShow()) {
                continue;
            }

            $locs[$location->xref()] = $location;
            foreach (GedcomText::unterzeilen($location->gedcom(), 1, 'NAME') as [$n]) {
                $byName[mb_strtolower(trim($n))][] = $location->xref();
            }
            $gov = $this->locGov($location);
            if ($gov !== null) {
                $byGov[$gov][] = $location->xref();
            }
        }

        // Ortstabelle des Baums: voller Name je Kennung - fuer die Bindungen des Ortsregisters und die Blattnamen.
        $ids    = $this->placeIds($tree);
        $leaves = [];
        foreach (array_keys($ids) as $full) {
            $leaf          = explode(', ', $full)[0];
            $leaves[$leaf] = ($leaves[$leaf] ?? 0) + 1;
        }

        $bound    = [];
        $boundGov = [];
        try {
            if (DB::schema()->hasTable('ortsregister_place_meta')) {
                $names = array_flip($ids);
                $meta  = DB::table('ortsregister_place_meta')->where('tree_id', '=', $tree->id())->get();
                foreach ($meta as $row) {
                    $full = $names[(int) $row->place_id] ?? null;
                    if ($full === null) {
                        continue;
                    }
                    $data = json_decode((string) ($row->meta_data ?? ''), true);
                    if (is_array($data) && isset($data['loc_xref']) && is_string($data['loc_xref'])) {
                        $bound[$full] = $data['loc_xref'];
                    }
                    if (($row->gov_id ?? null) !== null && $row->gov_id !== '') {
                        $boundGov[$full] = (string) $row->gov_id;
                    }
                }
            }
        } catch (Throwable) {
            // Modul nicht da oder anders gebaut - dann ohne seine Bindungen.
        }

        return [
            'locs'     => $locs,
            'byName'   => $byName,
            'byGov'    => $byGov,
            'leaves'   => $leaves,
            'bound'    => $bound,
            'boundGov' => $boundGov,
            'mapData'  => $this->mapData(),
        ];
    }

    /**
     * Der _LOC eines Orts - oder keiner, wenn er nicht eindeutig ist.
     *
     * @param array{name:string,locs:array<string,int>} $usage
     * @param array<string,mixed>                       $context
     */
    private function placeLocation(Tree $tree, array $usage, array $context): Location|null
    {
        $locs = $context['locs'];
        $key  = mb_strtolower($usage['name']);

        // 1. Verweis am Ereignis (GEDCOM-L "3 _LOC @L1@"), der haeufigste
        $pointers = $usage['locs'];
        arsort($pointers);
        foreach (array_keys($pointers) as $xref) {
            if (isset($locs[$xref])) {
                return $locs[$xref];
            }
        }

        // 2. Gespeicherte Bindung des Ortsregisters
        $bound = $context['bound'][$key] ?? null;
        if ($bound !== null && isset($locs[$bound])) {
            return $locs[$bound];
        }

        // 3. GOV-Kennung des Ortsregisters, genau ein _LOC damit
        $gov = $context['boundGov'][$key] ?? null;
        if ($gov !== null && count($context['byGov'][$gov] ?? []) === 1) {
            return $locs[$context['byGov'][$gov][0]];
        }

        // 4. Blattname, nur wenn auf beiden Seiten eindeutig
        $leaf = explode(', ', $key)[0];
        if (count($context['byName'][$leaf] ?? []) === 1 && ($context['leaves'][$leaf] ?? 0) <= 1) {
            return $locs[$context['byName'][$leaf][0]];
        }

        return null;
    }

    /**
     * @param array{name:string,lat:float|null,lng:float|null} $usage
     * @param array<string,mixed>                              $context
     *
     * @return array{0:float|null,1:float|null,2:string|null}
     */
    private function placeCoordinates(array $usage, Location|null $location, array $context): array
    {
        if ($location !== null) {
            [$lat, $lng] = $this->locCoordinates($location);
            if ($lat !== null && $lng !== null) {
                return [$lat, $lng, 'location'];
            }
        }

        $map = $context['mapData'][mb_strtolower($usage['name'])] ?? null;
        if ($map !== null) {
            return [$map[0], $map[1], 'mapData'];
        }

        if ($usage['lat'] !== null && $usage['lng'] !== null) {
            return [$usage['lat'], $usage['lng'], 'event'];
        }

        return [null, null, null];
    }

    /**
     * @return array<string,mixed>
     */
    private function locationJson(Location $location): array
    {
        [$lat, $lng] = $this->locCoordinates($location);

        $sources = [];
        foreach ($location->facts(['SOUR']) as $fact) {
            if (!$fact->canShow()) {
                continue;
            }
            $target    = $fact->target();
            $sources[] = [
                'xref'  => $target instanceof Source && $target->canShow() ? $target->xref() : null,
                'title' => $target instanceof Source ? ($target->canShow() ? $this->plain($target->fullName()) : null) : $this->plain($fact->value()),
                'page'  => $fact->attribute('PAGE') === '' ? null : $fact->attribute('PAGE'),
            ];
        }

        return [
            'xref'    => $location->xref(),
            'name'    => GedcomText::ersterWert($location->gedcom(), 1, 'NAME'),
            'gov'     => $this->locGov($location),
            // Postleitzahl, Region, Land: GEDCOM-L kennt _POST; _STAE und _CTRY (und POST) schreiben andere Programme
            'shortName'  => $this->locKurz($location),
            'postalCode' => $this->locWert($location, ['_POST', 'POST']),
            'region'     => $this->locWert($location, ['_STAE']),
            'country'    => $this->locWert($location, ['_CTRY']),
            'lat'     => $lat,
            'lng'     => $lng,
            'notes'   => $location->facts(['NOTE'])->filter(static fn (Fact $f): bool => $f->canShow())
                ->map(fn (Fact $f): string => $f->target() instanceof Note ? $f->target()->getNote() : $this->plainLines($f->value()))
                ->filter(static fn (string $t): bool => trim($t) !== '')->values()->all(),
            'sources' => $sources,
            'media'   => $this->mediaMitInfo($location),
            'canEdit' => $location->canEdit(),
            'url'     => $location->url(),
        ];
    }

    /**
     * Der erste Wert einer der Zeilen "1 <tag>" am _LOC.
     *
     * @param list<string> $tags
     */
    private function locWert(Location $location, array $tags): string|null
    {
        foreach ($tags as $tag) {
            $wert = trim(GedcomText::ersterWert($location->gedcom(), 1, $tag));
            if ($wert !== '') {
                return $wert;
            }
        }

        return null;
    }

    /**
     * Die Medien des _LOC wie mediaJson, dazu je Datei die Angaben, die webtrees auf der Medienseite zeigt (Dateigroesse,
     * Bildmasse) - liest dafuer die Datei, darum nur hier und nicht in Listen.
     *
     * @return list<array<string,mixed>>
     */
    private function mediaMitInfo(Location $location): array
    {
        return array_map(static function (array $m) use ($location): array {
            $media = Registry::mediaFactory()->make((string) $m['xref'], $location->tree());
            $datei = $media?->mediaFiles()->first();
            $info  = [];
            if ($datei !== null) {
                foreach ($datei->attributes() as $label => $wert) {
                    $info[] = $label . ': ' . $wert;
                }
            }

            return $m + ['info' => $info];
        }, $this->mediaJson($location));
    }

    /** Kurzname des Orts (GEDCOM-L: "2 ABBR" unter dem ersten "1 NAME" des _LOC), fuer gekuerzte Ortsangaben in Buechern. */
    private function locKurz(Location $location): string|null
    {
        $name = GedcomText::unterzeilen("\n" . $location->gedcom(), 1, 'NAME')[0][1] ?? '';
        $kurz = trim(GedcomText::unterzeilen($name, 2, 'ABBR')[0][0] ?? '');

        return $kurz === '' ? null : $kurz;
    }

    private function locGov(Location $location): string|null
    {
        $gov = trim(GedcomText::ersterWert($location->gedcom(), 1, '_GOV'));

        return $gov === '' ? null : $gov;
    }

    /**
     * @return array{0:float|null,1:float|null}
     */
    private function locCoordinates(Location $location): array
    {
        $gedcom = $location->gedcom();
        if (preg_match('/\n1 MAP(?:\n[2-9].*)*?\n2 LATI (.+)/', $gedcom, $la) === 1 && preg_match('/\n1 MAP(?:\n[2-9].*)*?\n2 LONG (.+)/', $gedcom, $lo) === 1) {
            $service = new GedcomService();

            return [$service->readLatitude($la[1]), $service->readLongitude($lo[1])];
        }

        return [null, null];
    }

    /**
     * Ortstabelle des Baums als voller Name (Kleinbuchstaben) -> Kennung. Liest nur; Place::id() wuerde fehlende
     * Orte anlegen.
     *
     * @return array<string,int>
     */
    private function placeIds(Tree $tree): array
    {
        $rows = DB::table('places')->where('p_file', '=', $tree->id())->get(['p_id', 'p_place', 'p_parent_id']);

        return $this->fullNames($rows->map(static fn (object $r): array => [(int) $r->p_id, (string) $r->p_place, (int) $r->p_parent_id])->all(), 0);
    }

    /**
     * "Geografische Daten" der Verwaltung (fuer alle Baeume gemeinsam): voller Name -> Breite, Laenge.
     *
     * @return array<string,array{0:float,1:float}>
     */
    private function mapData(): array
    {
        try {
            $rows = DB::table('place_location')->get(['id', 'parent_id', 'place', 'latitude', 'longitude']);
        } catch (Throwable) {
            return [];
        }

        $coords = [];
        foreach ($rows as $r) {
            if ($r->latitude !== null && $r->longitude !== null) {
                $coords[(int) $r->id] = [(float) $r->latitude, (float) $r->longitude];
            }
        }

        $data = [];
        foreach ($this->fullNames($rows->map(static fn (object $r): array => [(int) $r->id, (string) $r->place, $r->parent_id === null ? 0 : (int) $r->parent_id])->all(), 0) as $full => $id) {
            if (isset($coords[$id])) {
                $data[$full] = $coords[$id];
            }
        }

        return $data;
    }

    /**
     * Baum aus (Kennung, Name, Eltern-Kennung) -> voller Name in Kleinbuchstaben ("kortau, allenstein") -> Kennung.
     *
     * @param list<array{0:int,1:string,2:int}> $rows
     *
     * @return array<string,int>
     */
    private function fullNames(array $rows, int $root): array
    {
        $byId = [];
        foreach ($rows as [$id, $place, $parent]) {
            $byId[$id] = [$place, $parent];
        }

        $names = [];
        foreach ($byId as $id => [$place, $parent]) {
            $parts = [$place];
            $seen  = [$id => true];
            while ($parent !== $root && isset($byId[$parent]) && !isset($seen[$parent])) {
                $seen[$parent] = true;
                $parts[]       = $byId[$parent][0];
                $parent        = $byId[$parent][1];
            }
            $names[mb_strtolower(implode(', ', $parts))] = $id;
        }

        return $names;
    }
}
