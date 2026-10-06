<?php

declare(strict_types=1);

namespace Api4Webtrees;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\LinkedRecordService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_diff;
use function array_filter;
use function array_map;
use function array_reverse;
use function array_slice;
use function array_values;
use function bin2hex;
use function count;
use function date;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function random_bytes;
use function response;
use function str_contains;
use function str_replace;
use function time;
use function trim;

/**
 * Personen zusammenfuehren (ab Stufe 29) - mit Vorschau und Rueckgaengig.
 *
 * Zwei Personen werden zu einer: die zweite verschwindet, alles, was auf sie zeigte (Familien, Quellen, Notizen,
 * Medien, Paten-Verweise), zeigt danach auf die erste - so, wie es webtrees in der Verwaltung tut. Welche Fakten
 * bleiben, sagt der Client (keep1/keep2); die Vorschau schlaegt vor: alle der ersten, von der zweiten nur, was die
 * erste nicht wortgleich hat. Verknuepfungen (FAMC, FAMS, OBJE) bleiben immer, von beiden.
 *
 * Rueckgaengig: webtrees schreibt jede Aenderung dauerhaft in die Tabelle change (alter und neuer Text je Datensatz),
 * auch bei Sofortfreigabe. Das Modul merkt sich je Zusammenfuehren die Aenderungsnummern (Moduleinstellung je Baum).
 * MergeUndo spielt die alten Texte in umgekehrter Reihenfolge wieder ein - aber nur, wenn seitdem niemand an einem der
 * Datensaetze gearbeitet hat; sonst nennt es die Datensaetze und aendert nichts. Ohne Sofortfreigabe sind Zusammenfuehren
 * und Rueckgaengig gewoehnliche ausstehende Aenderungen.
 *
 * Nur Verwalter des Stammbaums duerfen zusammenfuehren - wie in webtrees selbst.
 */
trait MergeActions
{
    /** So viele Vorgaenge merkt sich das Modul je Baum (aelteste fallen heraus). */
    private const int MERGE_LOG_SIZE = 200;

    /** Verknuepfungen bleiben beim Zusammenfuehren immer und sind nicht waehlbar (anders als SKIP_FACTS und GedcomText::LINK_TAGS). */
    private const array MERGE_LINK_TAGS = ['FAMC', 'FAMS', 'OBJE'];

    /**
     * POST Merge/{tree}: Rumpf { xref1, xref2, keep1?, keep2?, preview? }. xref1 bleibt, xref2 geht in ihr auf.
     * Vorschau: beide Personen, ihre Fakten mit Vorschlag (keep), was auf xref2 zeigt, moegliche weitere Paare
     * (Eltern, Partner, Kinder gleichen Namens). Ohne preview wird zusammengefuehrt und der Vorgang gemerkt (mergeId).
     */
    public function postMergeAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree    = Validator::attributes($request)->tree();
        $body    = $this->body($request);
        $preview = ($body['preview'] ?? false) === true;
        $xref1   = $this->str($body, 'xref1');
        $xref2   = $this->str($body, 'xref2');

        if (!Auth::isManager($tree)) {
            return $this->error(403, 'not-manager');
        }

        if ($xref1 === '' || $xref2 === '') {
            return $this->error(400, 'xref-missing');
        }

        if ($xref1 === $xref2) {
            return $this->error(400, 'same-record');
        }

        $person1 = Registry::individualFactory()->make($xref1, $tree);
        $person2 = Registry::individualFactory()->make($xref2, $tree);

        if ($person1 === null || $person2 === null) {
            return $this->error(404, 'not-found');
        }

        if ($person1->isPendingDeletion() || $person2->isPendingDeletion()) {
            return $this->error(409, 'pending-deletion');
        }

        if (!$person1->canShow() || !$person2->canShow()) {
            return $this->error(403, 'private');
        }

        if (!$person1->canEdit() || !$person2->canEdit()) {
            return $this->error(403, 'not-editable');
        }

        [$facts1, $facts2] = $this->mergeFactLists($person1, $person2);
        $links             = $this->mergeLinks($person2, $person1);

        if ($preview) {
            return response([
                'ok'          => true,
                'preview'     => true,
                'person1'     => $this->personSummary($person1),
                'person2'     => $this->personSummary($person2),
                'facts1'      => $facts1,
                'facts2'      => $facts2,
                'links'       => $links,
                'suggestions' => $this->mergeSuggestions($person1, $person2),
            ]);
        }

        $keep1 = $this->mergeKeep($body, 'keep1', $facts1);
        $keep2 = $this->mergeKeep($body, 'keep2', $facts2);

        // Die Aenderungsnummern dieses Vorgangs: alles, was ab jetzt in diesem Baum dazukommt
        $before = $this->lastChangeId($tree);

        $this->relinkRecords($person2, $xref1);
        $accounts = $this->mergeUserData($tree, $xref1, $xref2);
        $person1->updateRecord($this->mergedGedcom($person1, $person2, $keep1, $keep2), true);
        $person2->deleteRecord();

        $ids = DB::table('change')
            ->where('gedcom_id', '=', $tree->id())
            ->where('change_id', '>', $before)
            ->orderBy('change_id')
            ->pluck('change_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $entry = [
            'id'          => bin2hex(random_bytes(8)),
            'time'        => time(),
            'user'        => Auth::user()->realName(),
            'xref'        => $xref1,
            'removed'     => $xref2,
            'name'        => $this->plain($person1->fullName()),
            'removedName' => $this->plain($person2->fullName()),
            'changes'     => $ids,
            'accounts'    => $accounts,
            'undone'      => null,
        ];
        $log   = $this->mergeLog($tree);
        $log[] = $entry;
        $this->mergeLogSave($tree, $log);

        $pending = $this->pendingChanges($tree)->whereIn('change_id', $ids === [] ? [-1] : $ids)->exists();

        return response([
            'ok'      => true,
            'preview' => false,
            'xref'    => $xref1,
            'removed' => $xref2,
            'mergeId' => $entry['id'],
            'records' => count($ids),
            'pending' => $pending,
        ]);
    }

    /**
     * 1. Schritt: Alles, was auf die zweite Person zeigt, auf die erste umhaengen - doppelte Verweise fallen weg.
     */
    private function relinkRecords(Individual $person2, string $xref1): void
    {
        $xref2   = $person2->xref();
        $service = Registry::container()->get(LinkedRecordService::class);

        foreach ($service->allLinkedRecords($person2) as $record) {
            if ($record->isPendingDeletion() || $record->xref() === $xref1 || $record->xref() === $xref2) {
                continue;
            }
            $gedcom = str_replace('@' . $xref2 . '@', '@' . $xref1 . '@', $record->gedcom());
            $gedcom = (string) preg_replace('/(\n1.*@.+@.*(?:\n[2-9].*)*)((?:\n1.*(?:\n[2-9].*)*)*\1)/', '$2', $gedcom);
            if ($gedcom !== $record->gedcom()) {
                $record->updateRecord($gedcom, true);
            }
        }
    }

    /**
     * 2. Schritt: Benutzerkonten und Startpersonen, Bloecke, Favoriten und Zaehler umhaengen - wie webtrees selbst.
     * Liefert die umgehaengten Kontoeinstellungen, damit MergeUndo sie zurueckstellen kann.
     *
     * @return list<array{user:int,name:string}>
     */
    private function mergeUserData(Tree $tree, string $xref1, string $xref2): array
    {
        $settings = DB::table('user_gedcom_setting')
            ->where('gedcom_id', '=', $tree->id())
            ->whereIn('setting_name', [UserInterface::PREF_TREE_ACCOUNT_XREF, UserInterface::PREF_TREE_DEFAULT_XREF])
            ->where('setting_value', '=', $xref2);
        $accounts = $settings->get()
            ->map(static fn (object $row): array => ['user' => (int) $row->user_id, 'name' => (string) $row->setting_name])
            ->all();
        $settings->update(['setting_value' => $xref1]);

        DB::table('block')->where('gedcom_id', '=', $tree->id())->where('xref', '=', $xref2)->update(['xref' => $xref1]);
        if (DB::schema()->hasTable('favorite')) {
            DB::table('favorite')->where('gedcom_id', '=', $tree->id())->where('xref', '=', $xref2)->update(['xref' => $xref1]);
        }
        DB::table('hit_counter')->where('gedcom_id', '=', $tree->id())->where('page_parameter', '=', $xref2)->delete();

        return $accounts;
    }

    /**
     * 3. Schritt: der Text der bleibenden Person - die gewaehlten Fakten beider, Verknuepfungen immer, nichts doppelt.
     *
     * @param list<string> $keep1 Fakt-IDs der ersten Person, die bleiben
     * @param list<string> $keep2 Fakt-IDs der zweiten Person, die dazukommen
     */
    private function mergedGedcom(Individual $person1, Individual $person2, array $keep1, array $keep2): string
    {
        $gedcom = '0 @' . $person1->xref() . '@ INDI';

        foreach ([[$person1, $keep1], [$person2, $keep2]] as [$person, $keep]) {
            foreach ($person->facts([], false, Auth::PRIV_HIDE, true) as $fact) {
                $tag = $this->shortTag($fact->tag());
                if ($tag === 'CHAN') {
                    continue;
                }
                $line   = "\n" . $fact->gedcom();
                $always = in_array($tag, self::MERGE_LINK_TAGS, true) || $tag === '_UID' && $person === $person1;
                if (($always || in_array($fact->id(), $keep, true)) && !str_contains($gedcom, $line)) {
                    $gedcom .= $line;
                }
            }
        }

        return str_replace('@' . $person2->xref() . '@', '@' . $person1->xref() . '@', $gedcom);
    }

    /**
     * POST MergeUndo/{tree}: Rumpf { id, preview? }. Nimmt ein Zusammenfuehren zurueck, wenn seitdem keiner der
     * Datensaetze geaendert wurde; sonst "changed-since" mit den Datensaetzen. Noch ausstehende Aenderungen werden
     * verworfen, angenommene durch den alten Text ersetzt (die geloeschte Person entsteht unter ihrer Kennung neu).
     */
    public function postMergeUndoAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree    = Validator::attributes($request)->tree();
        $body    = $this->body($request);
        $preview = ($body['preview'] ?? false) === true;
        $id      = $this->str($body, 'id');

        if (!Auth::isManager($tree)) {
            return $this->error(403, 'not-manager');
        }

        $log = $this->mergeLog($tree);
        $pos = null;
        foreach ($log as $i => $e) {
            if ($e['id'] === $id) {
                $pos = $i;
            }
        }

        if ($pos === null) {
            return $this->error(404, 'not-found');
        }

        $entry = $log[$pos];

        if ($entry['undone'] !== null) {
            return $this->error(409, 'already-undone');
        }

        $rows = DB::table('change')
            ->where('gedcom_id', '=', $tree->id())
            ->whereIn('change_id', $entry['changes'] === [] ? [-1] : $entry['changes'])
            ->orderByDesc('change_id')
            ->get();

        if ($rows->count() !== count($entry['changes'])) {
            return $this->error(409, 'history-missing');
        }

        // Seitdem geaendert? Angenommen: der heutige Text muss der von damals sein (eine geloeschte Person darf es nicht
        // mehr geben). Ausstehend: es darf keine juengere Aenderung am Datensatz geben.
        $changed = [];
        $last    = (int) $entry['changes'][count($entry['changes']) - 1];
        foreach ($rows as $row) {
            if ($row->status === 'rejected') {
                continue;
            }
            $record = Registry::gedcomRecordFactory()->make($row->xref, $tree);
            $newer = DB::table('change')
                ->where('gedcom_id', '=', $tree->id())
                ->where('xref', '=', $row->xref)
                ->where('change_id', '>', $last)
                ->where('status', '<>', 'rejected')
                ->exists();
            if ($row->status === 'pending') {
                $matches = !$newer;
            } elseif ($row->new_gedcom === '') {
                // damals geloescht: den Datensatz darf es heute nicht (mehr) geben
                $matches = $record === null || $record->isPendingDeletion();
            } else {
                $matches = $record !== null && $record->gedcom() === $row->new_gedcom && !$newer;
            }
            if (!$matches) {
                $changed[] = ['xref' => $row->xref, 'name' => $record === null ? $row->xref : $this->plain($record->fullName())];
            }
        }

        if ($changed !== []) {
            return response(['ok' => false, 'error' => 'changed-since', 'status' => 409, 'changed' => $changed]);
        }

        if ($preview) {
            return response(['ok' => true, 'preview' => true, 'xref' => $entry['xref'], 'removed' => $entry['removed'], 'records' => $rows->count()]);
        }

        $auto     = Auth::user()->getPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS) === '1';
        $import   = Registry::container()->get(GedcomImportService::class);
        $new_ids   = [];

        foreach ($rows as $row) {
            if ($row->status === 'pending') {
                DB::table('change')->where('change_id', '=', $row->change_id)->update(['status' => 'rejected']);
                continue;
            }
            if ($row->status === 'rejected') {
                continue;
            }
            // Angenommen: den alten Text als neue Aenderung einspielen (bei '' war es eine Loeschung - der Datensatz
            // entsteht unter seiner alten Kennung neu; bei altem Text '' war es eine Neuanlage - die wird geloescht)
            $new_ids[] = (int) DB::table('change')->insertGetId([
                'gedcom_id'  => $tree->id(),
                'xref'       => $row->xref,
                'old_gedcom' => $row->new_gedcom,
                'new_gedcom' => $row->old_gedcom,
                'status'     => $auto ? 'accepted' : 'pending',
                'user_id'    => Auth::id(),
            ]);
            if ($auto) {
                if ($row->old_gedcom === '') {
                    $import->updateRecord($row->new_gedcom, $tree, true);
                } else {
                    $import->updateRecord($row->old_gedcom, $tree, false);
                }
            }
        }

        foreach ($entry['accounts'] as $konto) {
            DB::table('user_gedcom_setting')
                ->where('gedcom_id', '=', $tree->id())
                ->where('user_id', '=', $konto['user'])
                ->where('setting_name', '=', $konto['name'])
                ->where('setting_value', '=', $entry['xref'])
                ->update(['setting_value' => $entry['removed']]);
        }

        $log[$pos]['undone']      = time();
        $log[$pos]['undoChanges'] = $new_ids;
        $this->mergeLogSave($tree, $log);

        return response([
            'ok'      => true,
            'preview' => false,
            'xref'    => $entry['xref'],
            'removed' => $entry['removed'],
            'records' => $rows->count(),
            'pending' => !$auto && $new_ids !== [],
        ]);
    }

    /**
     * GET Merges/{tree}: das Protokoll der Zusammenfuehrungen dieses Baums, juengste zuerst (nur Verwalter).
     */
    public function getMergesAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        if (!Auth::isManager($tree)) {
            return $this->error(403, 'not-manager');
        }

        $merges = array_map(static fn (array $e): array => [
            'id'          => $e['id'],
            'time'        => date('c', (int) $e['time']),
            'user'        => $e['user'],
            'xref'        => $e['xref'],
            'name'        => $e['name'],
            'removed'     => $e['removed'],
            'removedName' => $e['removedName'],
            'records'     => count($e['changes']),
            'undone'      => $e['undone'] === null ? null : date('c', (int) $e['undone']),
        ], array_reverse($this->mergeLog($tree)));

        return response(['ok' => true, 'merges' => $merges]);
    }

    /**
     * Die Fakten beider Personen fuer die Vorschau: id, tag, label, text, same (wortgleich auch bei der anderen),
     * link (bleibt immer), keep (Vorschlag). Vorschlag: alles der ersten; von der zweiten nur, was die erste nicht hat.
     *
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function mergeFactLists(Individual $person1, Individual $person2): array
    {
        $texts1 = [];
        $texts2 = [];
        $tags1  = [];
        $tags2  = [];
        foreach ($person1->facts([], false, Auth::PRIV_HIDE, true) as $fact) {
            $texts1[] = trim($fact->gedcom());
            $tags1[]  = $this->shortTag($fact->tag());
        }
        foreach ($person2->facts([], false, Auth::PRIV_HIDE, true) as $fact) {
            $texts2[] = trim($fact->gedcom());
            $tags2[]  = $this->shortTag($fact->tag());
        }

        $list = function (Individual $person, array $other_texts, array $other_tags, bool $first): array {
            $out = [];
            foreach ($person->facts([], false, Auth::PRIV_HIDE, true) as $fact) {
                $tag = $this->shortTag($fact->tag());
                if ($tag === 'CHAN' || $tag === '_UID' && !$first) {
                    continue;
                }
                $text = trim($fact->gedcom());
                // "1 DEAT Y" ist nur die Angabe "verstorben" - hat die andere Person einen Tod mit Datum, ist sie gemeint
                $flag = preg_match('/^1 ([A-Z]{3,5}) Y$/', $text, $m) === 1 && in_array($m[1], $other_tags, true);
                $exact = in_array($text, $other_texts, true);
                // Enthalten: dieselbe Angabe mit weniger Unterzeilen (Geburt mit Datum, drueben Datum und Ort) - die
                // vollstaendigere Fassung bleibt, egal bei welcher Person sie steht
                $contained = !$exact && $this->mergeContained($text, $other_texts);
                $same = $flag || $exact || $contained;
                $link = in_array($tag, self::MERGE_LINK_TAGS, true);
                $out[] = [
                    'id'    => $fact->id(),
                    'tag'   => $tag,
                    'label' => $this->factLabel($fact),
                    'text'  => $this->mergeFactText($fact),
                    'same'  => $same,
                    'link'  => $link,
                    'keep'  => $link || !$flag && !$contained && ($first || !$exact),
                ];
            }

            return $out;
        };

        return [$list($person1, $texts2, $tags2, true), $list($person2, $texts1, $tags1, false)];
    }

    /**
     * Ist der Fakt in einem Fakt der anderen Person enthalten? Gleiche erste Zeile und jede weitere Zeile kommt dort auch
     * vor, die andere Fassung hat aber mehr Zeilen.
     *
     * @param list<string> $other_texts
     */
    private function mergeContained(string $text, array $other_texts): bool
    {
        $lines = explode("\n", $text);
        foreach ($other_texts as $other) {
            $other_lines = explode("\n", $other);
            if (count($other_lines) <= count($lines) || $other_lines[0] !== $lines[0]) {
                continue;
            }
            if (array_diff($lines, $other_lines) === []) {
                return true;
            }
        }

        return false;
    }

    /** Kurztext eines Fakts fuer die Gegenueberstellung: Wert, Datum, Ort. */
    private function mergeFactText(Fact $fact): string
    {
        $tag = $this->shortTag($fact->tag());
        if (in_array($tag, self::MERGE_LINK_TAGS, true)) {
            $target = $fact->target();

            return $target === null ? $fact->value() : $this->plain($target->fullName());
        }
        $parts = [];
        $value  = $tag === 'NAME' ? trim(str_replace('/', '', $fact->value())) : $this->factValue($fact, $fact->record()->tree());
        if ($value !== '') {
            $parts[] = $value;
        }
        $date = $fact->attribute('DATE');
        if ($date !== '') {
            $parts[] = $this->plain($fact->date()->display());
        }
        $place = $fact->place()->gedcomName();
        if ($place !== '') {
            $parts[] = $place;
        }

        return implode(' · ', $parts);
    }

    /**
     * Was auf die zweite Person zeigt und nach dem Zusammenfuehren auf die erste zeigt (ohne die beiden selbst).
     *
     * @return list<array{xref:string,type:string,name:string}>
     */
    private function mergeLinks(Individual $person2, Individual $person1): array
    {
        $out     = [];
        $service = Registry::container()->get(LinkedRecordService::class);
        foreach ($service->allLinkedRecords($person2) as $record) {
            if ($record->isPendingDeletion() || $record->xref() === $person1->xref() || $record->xref() === $person2->xref()) {
                continue;
            }
            $out[] = ['xref' => $record->xref(), 'type' => $record->tag(), 'name' => $this->plain($record->fullName())];
        }

        return $out;
    }

    /**
     * Weitere Paare, die wahrscheinlich dieselben Personen sind: Vater und Mutter beider (wenn verschieden), Partner
     * und Kinder mit gleichem Namen. Nur Vorschlaege - der Client entscheidet.
     *
     * @return list<array{role:string,xref1:string,name1:string,xref2:string,name2:string}>
     */
    private function mergeSuggestions(Individual $person1, Individual $person2): array
    {
        $out  = [];
        $pair = function (string $role, Individual|null $a, Individual|null $b) use (&$out): void {
            if ($a === null || $b === null || $a->xref() === $b->xref() || !$a->canShow() || !$b->canShow()) {
                return;
            }
            $out[] = ['role' => $role, 'xref1' => $a->xref(), 'name1' => $this->plain($a->fullName()), 'xref2' => $b->xref(), 'name2' => $this->plain($b->fullName())];
        };
        $parents = static function (Individual $p): array {
            $f = $p->childFamilies()->first();

            return $f instanceof Family ? [$f->husband(), $f->wife()] : [null, null];
        };
        [$father1, $mother1] = $parents($person1);
        [$father2, $mother2] = $parents($person2);
        $pair('father', $father1, $father2);
        $pair('mother', $mother1, $mother2);

        $name_key = static fn (Individual $p): string => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', str_replace('/', '', $p->getAllNames()[$p->getPrimaryName()]['fullNN'] ?? $p->xref()))));
        $same_name     = function (string $role, array $a, array $b) use ($pair, $name_key): void {
            foreach ($a as $pa) {
                foreach ($b as $pb) {
                    if ($pa instanceof Individual && $pb instanceof Individual && $pa->xref() !== $pb->xref() && $name_key($pa) === $name_key($pb)) {
                        $pair($role, $pa, $pb);
                    }
                }
            }
        };
        $spouses = static fn (Individual $p): array => $p->spouseFamilies()->map(static fn (Family $f): Individual|null => $f->spouse($p))->filter()->all();
        $children  = static fn (Individual $p): array => $p->spouseFamilies()->flatMap(static fn (Family $f) => $f->children())->all();
        $same_name('spouse', $spouses($person1), $spouses($person2));
        $same_name('child', $children($person1), $children($person2));

        return $out;
    }

    /**
     * Die zu behaltenden Fakt-Kennungen aus dem Rumpf; fehlt die Liste, gilt der Vorschlag der Vorschau.
     *
     * @param array<string,mixed>      $body
     * @param list<array<string,mixed>> $facts
     * @return list<string>
     */
    private function mergeKeep(array $body, string $key, array $facts): array
    {
        $value = $body[$key] ?? null;
        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($v): bool => is_string($v)));
        }

        return array_values(array_map(static fn (array $f): string => $f['id'], array_filter($facts, static fn (array $f): bool => $f['keep'] === true)));
    }

    /** @return list<array<string,mixed>> */
    private function mergeLog(Tree $tree): array
    {
        $json = json_decode($this->getPreference('merges_' . $tree->id(), '[]'), true);

        return is_array($json) ? array_values($json) : [];
    }

    /** @param list<array<string,mixed>> $log */
    private function mergeLogSave(Tree $tree, array $log): void
    {
        $this->setPreference('merges_' . $tree->id(), (string) json_encode(array_slice($log, -self::MERGE_LOG_SIZE)));
    }
}
