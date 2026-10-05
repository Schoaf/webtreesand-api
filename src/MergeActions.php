<?php

declare(strict_types=1);

namespace Api4Webtrees;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\LinkedRecordService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_filter;
use function array_map;
use function array_reverse;
use function array_slice;
use function array_values;
use function bin2hex;
use function count;
use function date;
use function explode;
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

    /** Verknuepfungen bleiben immer und sind nicht waehlbar. */
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
        $vorher = (int) DB::table('change')->where('gedcom_id', '=', $tree->id())->max('change_id');

        // 1. Alles, was auf die zweite Person zeigt, auf die erste umhaengen (doppelte Verweise fallen weg)
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

        // 2. Benutzerkonten und Startpersonen, Bloecke, Favoriten, Zaehler - wie webtrees
        $konten = DB::table('user_gedcom_setting')
            ->where('gedcom_id', '=', $tree->id())
            ->whereIn('setting_name', [UserInterface::PREF_TREE_ACCOUNT_XREF, UserInterface::PREF_TREE_DEFAULT_XREF])
            ->where('setting_value', '=', $xref2)
            ->get()
            ->map(static fn (object $row): array => ['user' => (int) $row->user_id, 'name' => (string) $row->setting_name])
            ->all();
        DB::table('user_gedcom_setting')
            ->where('gedcom_id', '=', $tree->id())
            ->whereIn('setting_name', [UserInterface::PREF_TREE_ACCOUNT_XREF, UserInterface::PREF_TREE_DEFAULT_XREF])
            ->where('setting_value', '=', $xref2)
            ->update(['setting_value' => $xref1]);
        DB::table('block')->where('gedcom_id', '=', $tree->id())->where('xref', '=', $xref2)->update(['xref' => $xref1]);
        if (DB::schema()->hasTable('favorite')) {
            DB::table('favorite')->where('gedcom_id', '=', $tree->id())->where('xref', '=', $xref2)->update(['xref' => $xref1]);
        }
        DB::table('hit_counter')->where('gedcom_id', '=', $tree->id())->where('page_parameter', '=', $xref2)->delete();

        // 3. Die bleibende Person neu aufbauen: gewaehlte Fakten beider, Verknuepfungen immer
        $gedcom = '0 @' . $xref1 . '@ INDI';
        foreach ([[$person1, $keep1], [$person2, $keep2]] as [$person, $keep]) {
            foreach ($person->facts([], false, Auth::PRIV_HIDE, true) as $fact) {
                $tag = $this->shortTag($fact->tag());
                if ($tag === 'CHAN') {
                    continue;
                }
                $zeile = "\n" . $fact->gedcom();
                if (in_array($tag, self::MERGE_LINK_TAGS, true) || $tag === '_UID' && $person === $person1) {
                    if (!str_contains($gedcom, $zeile)) {
                        $gedcom .= $zeile;
                    }
                } elseif (in_array($fact->id(), $keep, true) && !str_contains($gedcom, $zeile)) {
                    $gedcom .= $zeile;
                }
            }
        }
        $gedcom = str_replace('@' . $xref2 . '@', '@' . $xref1 . '@', $gedcom);

        $person1->updateRecord($gedcom, true);
        $person2->deleteRecord();

        $ids = DB::table('change')
            ->where('gedcom_id', '=', $tree->id())
            ->where('change_id', '>', $vorher)
            ->orderBy('change_id')
            ->pluck('change_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $eintrag = [
            'id'          => bin2hex(random_bytes(8)),
            'time'        => time(),
            'user'        => Auth::user()->realName(),
            'xref'        => $xref1,
            'removed'     => $xref2,
            'name'        => $this->plain($person1->fullName()),
            'removedName' => $this->plain($person2->fullName()),
            'changes'     => $ids,
            'accounts'    => $konten,
            'undone'      => null,
        ];
        $log = $this->mergeLog($tree);
        $log[] = $eintrag;
        $this->mergeLogSave($tree, $log);

        $pending = DB::table('change')
            ->where('gedcom_id', '=', $tree->id())
            ->whereIn('change_id', $ids === [] ? [-1] : $ids)
            ->where('status', '=', 'pending')
            ->exists();

        return response([
            'ok'      => true,
            'preview' => false,
            'xref'    => $xref1,
            'removed' => $xref2,
            'mergeId' => $eintrag['id'],
            'records' => count($ids),
            'pending' => $pending,
        ]);
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

        $eintrag = $log[$pos];

        if ($eintrag['undone'] !== null) {
            return $this->error(409, 'already-undone');
        }

        $rows = DB::table('change')
            ->where('gedcom_id', '=', $tree->id())
            ->whereIn('change_id', $eintrag['changes'] === [] ? [-1] : $eintrag['changes'])
            ->orderByDesc('change_id')
            ->get();

        if ($rows->count() !== count($eintrag['changes'])) {
            return $this->error(409, 'history-missing');
        }

        // Seitdem geaendert? Angenommen: der heutige Text muss der von damals sein (eine geloeschte Person darf es nicht
        // mehr geben). Ausstehend: es darf keine juengere Aenderung am Datensatz geben.
        $geaendert = [];
        $letzte    = (int) $eintrag['changes'][count($eintrag['changes']) - 1];
        foreach ($rows as $row) {
            if ($row->status === 'rejected') {
                continue;
            }
            $record = Registry::gedcomRecordFactory()->make($row->xref, $tree);
            $juenger = DB::table('change')
                ->where('gedcom_id', '=', $tree->id())
                ->where('xref', '=', $row->xref)
                ->where('change_id', '>', $letzte)
                ->where('status', '<>', 'rejected')
                ->exists();
            $passt = $row->status === 'pending'
                ? !$juenger
                : ($row->new_gedcom === '' ? $record === null || $record->isPendingDeletion() : $record !== null && $record->gedcom() === $row->new_gedcom && !$juenger);
            if (!$passt) {
                $geaendert[] = ['xref' => $row->xref, 'name' => $record === null ? $row->xref : $this->plain($record->fullName())];
            }
        }

        if ($geaendert !== []) {
            return response(['ok' => false, 'error' => 'changed-since', 'status' => 409, 'changed' => $geaendert]);
        }

        if ($preview) {
            return response(['ok' => true, 'preview' => true, 'xref' => $eintrag['xref'], 'removed' => $eintrag['removed'], 'records' => $rows->count()]);
        }

        $auto     = Auth::user()->getPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS) === '1';
        $import   = Registry::container()->get(GedcomImportService::class);
        $neuIds   = [];

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
            $neuIds[] = (int) DB::table('change')->insertGetId([
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

        foreach ($eintrag['accounts'] as $konto) {
            DB::table('user_gedcom_setting')
                ->where('gedcom_id', '=', $tree->id())
                ->where('user_id', '=', $konto['user'])
                ->where('setting_name', '=', $konto['name'])
                ->where('setting_value', '=', $eintrag['xref'])
                ->update(['setting_value' => $eintrag['removed']]);
        }

        $log[$pos]['undone']      = time();
        $log[$pos]['undoChanges'] = $neuIds;
        $this->mergeLogSave($tree, $log);

        return response([
            'ok'      => true,
            'preview' => false,
            'xref'    => $eintrag['xref'],
            'removed' => $eintrag['removed'],
            'records' => $rows->count(),
            'pending' => !$auto && $neuIds !== [],
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
        $texte1 = [];
        $texte2 = [];
        $tags1  = [];
        $tags2  = [];
        foreach ($person1->facts([], false, Auth::PRIV_HIDE, true) as $fact) {
            $texte1[] = trim($fact->gedcom());
            $tags1[]  = $this->shortTag($fact->tag());
        }
        foreach ($person2->facts([], false, Auth::PRIV_HIDE, true) as $fact) {
            $texte2[] = trim($fact->gedcom());
            $tags2[]  = $this->shortTag($fact->tag());
        }

        $liste = function (Individual $person, array $andere, array $andereTags, bool $erste): array {
            $out = [];
            foreach ($person->facts([], false, Auth::PRIV_HIDE, true) as $fact) {
                $tag = $this->shortTag($fact->tag());
                if ($tag === 'CHAN' || $tag === '_UID' && !$erste) {
                    continue;
                }
                $text = trim($fact->gedcom());
                // "1 DEAT Y" ist nur die Angabe "verstorben" - hat die andere Person einen Tod mit Datum, ist sie gemeint
                $flag = preg_match('/^1 ([A-Z]{3,5}) Y$/', $text, $m) === 1 && in_array($m[1], $andereTags, true);
                $same = $flag || in_array($text, $andere, true);
                $link = in_array($tag, self::MERGE_LINK_TAGS, true);
                $out[] = [
                    'id'    => $fact->id(),
                    'tag'   => $tag,
                    'label' => $this->factLabel($fact),
                    'text'  => $this->mergeFactText($fact),
                    'same'  => $same,
                    'link'  => $link,
                    'keep'  => $link || !$flag && ($erste || !$same),
                ];
            }

            return $out;
        };

        return [$liste($person1, $texte2, $tags2, true), $liste($person2, $texte1, $tags1, false)];
    }

    /** Kurztext eines Fakts fuer die Gegenueberstellung: Wert, Datum, Ort. */
    private function mergeFactText(Fact $fact): string
    {
        $tag = $this->shortTag($fact->tag());
        if (in_array($tag, self::MERGE_LINK_TAGS, true)) {
            $ziel = $fact->target();

            return $ziel === null ? $fact->value() : $this->plain($ziel->fullName());
        }
        $teile = [];
        $wert  = $tag === 'NAME' ? trim(str_replace('/', '', $fact->value())) : $this->factValue($fact, $fact->record()->tree());
        if ($wert !== '') {
            $teile[] = $wert;
        }
        $datum = $fact->attribute('DATE');
        if ($datum !== '') {
            $teile[] = $this->plain($fact->date()->display());
        }
        $ort = $fact->place()->gedcomName();
        if ($ort !== '') {
            $teile[] = $ort;
        }

        return implode(' · ', $teile);
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
        $paar = function (string $role, Individual|null $a, Individual|null $b) use (&$out): void {
            if ($a === null || $b === null || $a->xref() === $b->xref() || !$a->canShow() || !$b->canShow()) {
                return;
            }
            $out[] = ['role' => $role, 'xref1' => $a->xref(), 'name1' => $this->plain($a->fullName()), 'xref2' => $b->xref(), 'name2' => $this->plain($b->fullName())];
        };
        $eltern = static function (Individual $p): array {
            $f = $p->childFamilies()->first();

            return $f instanceof Family ? [$f->husband(), $f->wife()] : [null, null];
        };
        [$v1, $m1] = $eltern($person1);
        [$v2, $m2] = $eltern($person2);
        $paar('father', $v1, $v2);
        $paar('mother', $m1, $m2);

        $schluessel = static fn (Individual $p): string => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', str_replace('/', '', $p->getAllNames()[$p->getPrimaryName()]['fullNN'] ?? $p->xref()))));
        $gleich     = function (string $role, array $a, array $b) use ($paar, $schluessel): void {
            foreach ($a as $pa) {
                foreach ($b as $pb) {
                    if ($pa instanceof Individual && $pb instanceof Individual && $pa->xref() !== $pb->xref() && $schluessel($pa) === $schluessel($pb)) {
                        $paar($role, $pa, $pb);
                    }
                }
            }
        };
        $partner = static fn (Individual $p): array => $p->spouseFamilies()->map(static fn (Family $f): Individual|null => $f->spouse($p))->filter()->all();
        $kinder  = static fn (Individual $p): array => $p->spouseFamilies()->flatMap(static fn (Family $f) => $f->children())->all();
        $gleich('spouse', $partner($person1), $partner($person2));
        $gleich('child', $kinder($person1), $kinder($person2));

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
        $wert = $body[$key] ?? null;
        if (is_array($wert)) {
            return array_values(array_filter($wert, static fn ($v): bool => is_string($v)));
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
