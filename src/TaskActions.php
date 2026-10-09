<?php

declare(strict_types=1);

namespace Api4Webtrees;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_filter;
use function array_map;
use function array_search;
use function array_values;
use function count;
use function date;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function md5;
use function preg_match;
use function preg_replace;
use function response;
use function str_replace;
use function strtoupper;
use function trim;
use function uksort;
use function usort;

/**
 * Stufe 30 - der Rest fuer die taegliche Arbeit: Forschungsaufgaben, Reihenfolge, Aenderungsverlauf.
 *
 * Aufgaben sind webtrees' eigene Forschungsaufgaben: "1 _TODO <Text>" mit "2 DATE", "2 _WT_USER" und "2 NOTE" am
 * Personen- oder Familiendatensatz - so, wie das webtrees-Modul "Forschungsaufgaben" sie anlegt und im Block
 * anzeigt. Erledigt heisst geloescht (DeleteFact), wie in webtrees. Nichts Eigenes, nichts, was ein anderes
 * Programm nicht liest: _TODO ist ein erlaubtes Zusatzfeld nach GEDCOM 5.5.1 und geht mit dem Export mit.
 *
 * Reihenfolge: Kinder (CHIL), Partnerschaften (FAMS), Medien (OBJE) und Namen (NAME) sortieren - genau wie die
 * Seiten "Kinder neu anordnen" usw. in webtrees, nur die Reihenfolge der Zeilen aendert sich.
 *
 * Aenderungsverlauf: die Tabelle change von webtrees (wer hat wann welchen Datensatz angelegt, geaendert, geloescht),
 * nur fuer Datensaetze, die der Benutzer sehen darf.
 */
trait TaskActions
{
    /** Aufgaben im Verlauf hoechstens; Verlauf hoechstens so viele Eintraege je Abruf. */
    private const int TASKS_LIMIT   = 2000;
    private const int CHANGES_LIMIT = 200;

    /**
     * GET Tasks/{tree}: alle Forschungsaufgaben des Baums an sichtbaren Personen und Familien, nach Datum.
     * ?open=1 nur die faelligen (Datum nicht in der Zukunft) - webtrees versteht ein Datum in der Zukunft als
     * "Wiedervorlage".
     */
    public function getTasksAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        if (!Auth::check()) {
            return $this->error(403, 'not-logged-in');
        }

        $only_open = Validator::queryParams($request)->string('open', '') === '1';
        $today     = $this->todayJd();
        $tasks     = [];

        foreach ([['individuals', 'i', Registry::individualFactory()], ['families', 'f', Registry::familyFactory()]] as [$table, $p, $factory]) {
            $rows = DB::table($table)
                ->join('dates', static function ($join) use ($p): void {
                    $join->on('d_gid', '=', $p . '_id')->on('d_file', '=', $p . '_file');
                })
                ->where($p . '_file', '=', $tree->id())
                ->where('d_fact', '=', '_TODO')
                ->select([$table . '.*'])
                ->distinct()
                ->get();
            foreach ($rows as $row) {
                $record = $factory->mapper($tree)($row);
                if (!$record->canShow()) {
                    continue;
                }
                foreach ($record->facts(['_TODO'], false, null, true) as $fact) {
                    if ($fact->isPendingDeletion()) {
                        continue;
                    }
                    $task = $this->taskJson($record, $fact);
                    if ($only_open && $task['jd'] !== null && $task['jd'] > $today) {
                        continue;
                    }
                    $tasks[] = $task;
                    if (count($tasks) >= self::TASKS_LIMIT) {
                        break 3;
                    }
                }
            }
        }

        usort($tasks, static fn (array $a, array $b): int => ($a['jd'] ?? PHP_INT_MAX) <=> ($b['jd'] ?? PHP_INT_MAX) ?: $a['name'] <=> $b['name']);

        return response(['ok' => true, 'today' => $today, 'tasks' => $tasks]);
    }

    /**
     * POST Task/{tree}?xref=: Rumpf { factId?, text, date?, user?, note? }. Ohne factId neu, mit factId aendern.
     * Datum fehlt: heute (webtrees tut dasselbe); Benutzer fehlt: der angemeldete. Loeschen (= erledigt) ueber
     * DeleteFact mit der factId. Antwort: ok, xref, pending, factId (neu nach jeder Aenderung).
     */
    public function postTaskAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $xref   = $this->xref($request);
        $body   = $this->body($request);
        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        $denied = $this->denyEdit($record);

        if ($denied !== null) {
            return $denied;
        }

        if (!$record instanceof Individual && !$record instanceof Family) {
            return $this->error(400, 'not-individual-or-family');
        }

        $text = trim(preg_replace('/\s+/', ' ', $this->str($body, 'text')) ?? '');

        if ($text === '') {
            return $this->error(400, 'text-missing');
        }

        $date = trim($this->str($body, 'date'));
        if ($date === '') {
            $date = strtoupper(date('j M Y'));
        }
        $user = trim($this->str($body, 'user'));
        if ($user === '') {
            $user = Auth::user()->userName();
        }
        $note = trim(str_replace("\r", '', $this->str($body, 'note')));

        $gedcom = '1 _TODO ' . $text . "\n2 DATE " . $date . "\n2 _WT_USER " . $user;
        if ($note !== '') {
            $gedcom .= "\n2 NOTE " . implode("\n3 CONT ", explode("\n", $note));
        }

        $fact_id = $this->str($body, 'factId');
        if ($fact_id !== '') {
            $old = $this->editableFact($record, $fact_id);
            if ($old instanceof ResponseInterface) {
                return $old;
            }
            if ($this->shortTag($old->tag()) !== '_TODO') {
                return $this->error(400, 'not-a-task');
            }
            $record->updateFact($fact_id, $gedcom, true);
        } else {
            $record->createFact($gedcom, true);
        }

        return $this->written($record, ['factId' => md5($gedcom)]);
    }

    /**
     * POST Reorder/{tree}?xref=: Rumpf { type: "children"|"families"|"media"|"names", order: [...] }. children am
     * Familiendatensatz (Kennungen der Kinder), families (FAMS) und names an der Person, media an beiden; order
     * nennt Kennungen (Namen: factIds) in der neuen Reihenfolge, nicht Genanntes kommt dahinter. Genau wie die
     * Seiten "neu anordnen" in webtrees - nur die Reihenfolge der Zeilen aendert sich.
     */
    public function postReorderAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $xref   = $this->xref($request);
        $body   = $this->body($request);
        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        $denied = $this->denyEdit($record);

        if ($denied !== null) {
            return $denied;
        }

        $type  = $this->str($body, 'type');
        $order = is_array($body['order'] ?? null) ? array_values(array_filter($body['order'], static fn ($v): bool => is_string($v))) : [];
        $tag   = match (true) {
            $type === 'children' && $record instanceof Family     => 'FAM:CHIL',
            $type === 'families' && $record instanceof Individual => 'INDI:FAMS',
            $type === 'names' && $record instanceof Individual    => 'INDI:NAME',
            $type === 'media'                                      => $record->tag() . ':OBJE',
            default                                                => null,
        };

        if ($tag === null) {
            return $this->error(400, 'invalid-value');
        }

        $sort = [];
        $keep = [];
        foreach ($record->facts([], false, null, true) as $fact) {
            if ($fact->tag() === $tag) {
                // Kinder, Partnerschaften, Medien nach der Kennung im Wert, Namen nach der Fakt-Kennung
                $key = $tag === 'INDI:NAME' ? $fact->id() : trim($fact->value(), '@');
                $sort[$key] = $fact->gedcom();
            } else {
                $keep[] = $fact->gedcom();
            }
        }

        $pos = static function (string $key) use ($order): int {
            $i = array_search($key, $order, true);

            return $i === false ? PHP_INT_MAX : $i;
        };
        uksort($sort, static fn (string $a, string $b): int => $pos($a) <=> $pos($b));

        $gedcom = implode("\n", [...['0 @' . $record->xref() . '@ ' . $record->tag()], ...array_values($sort), ...$keep]);
        $record->updateRecord($gedcom, false);

        return $this->written($record, ['order' => array_map(static fn (string $k): string => $k, array_keys($sort))]);
    }

    /**
     * GET Changes/{tree}: der Aenderungsverlauf - wer hat wann welchen Datensatz angelegt, geaendert oder geloescht
     * (Tabelle change von webtrees), juengste zuerst. ?limit=50 (hoechstens 200), ?xref= nur dieser Datensatz.
     * Nur Datensaetze, die der Benutzer sehen darf; geloeschte sieht nur, wer den Baum verwaltet.
     */
    public function getChangesAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        if (!Auth::check()) {
            return $this->error(403, 'not-logged-in');
        }

        $limit = Validator::queryParams($request)->integer('limit', 50);
        $limit = max(1, min(self::CHANGES_LIMIT, $limit));
        $only  = Validator::queryParams($request)->string('xref', '');

        $query = DB::table('change')
            ->leftJoin('user', 'user.user_id', '=', 'change.user_id')
            ->where('change.gedcom_id', '=', $tree->id())
            ->whereIn('change.status', ['accepted', 'pending'])
            ->orderByDesc('change.change_id')
            ->select(['change.change_id', 'change.change_time', 'change.status', 'change.xref', 'change.old_gedcom', 'change.new_gedcom', 'user.real_name', 'user.user_name']);
        if ($only !== '') {
            $query->where('change.xref', '=', $only);
        }

        $out     = [];
        $manager = Auth::isManager($tree);
        foreach ($query->limit($limit * 3)->get() as $row) {
            $record = Registry::gedcomRecordFactory()->make($row->xref, $tree);
            if ($record !== null && !$record->canShow()) {
                continue;
            }
            if ($record === null && !$manager) {
                continue;
            }
            preg_match('/^0 @[^@]*@ ([A-Z_]+)/', $row->new_gedcom !== '' ? $row->new_gedcom : $row->old_gedcom, $m);
            $out[] = [
                'time'    => date('c', strtotime((string) $row->change_time)),
                'user'    => (string) ($row->real_name ?? $row->user_name ?? ''),
                'xref'    => $row->xref,
                'type'    => $m[1] ?? '',
                'name'    => $record === null ? $row->xref : $this->plain($record->fullName()),
                'action'  => $row->old_gedcom === '' ? 'created' : ($row->new_gedcom === '' ? 'deleted' : 'updated'),
                'pending' => $row->status === 'pending',
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return response(['ok' => true, 'changes' => $out]);
    }

    /** Eine Aufgabe als JSON: Datensatz, Text, Datum, Bearbeiter, Notiz. */
    private function taskJson(GedcomRecord $record, Fact $fact): array
    {
        $date = $fact->date();

        return [
            'record'     => $record->xref(),
            'recordType' => $record->tag(),
            'name'       => $this->plain($record->fullName()),
            'factId'     => $fact->id(),
            'text'       => $fact->value(),
            'date'       => $this->dateJson($date, $fact->attribute('DATE')),
            'jd'         => $date->isOK() ? $date->minimumJulianDay() : null,
            'user'       => $fact->attribute('_WT_USER'),
            'note'       => $this->plainLines(str_replace("\n3 CONT ", "\n", $fact->attribute('NOTE'))),
            'pending'    => $fact->isPendingAddition(),
        ];
    }

    /**
     * Die Aufgaben eines Datensatzes (fuer die Personen- und Familienantwort).
     *
     * @return list<array<string,mixed>>
     */
    private function tasksJson(GedcomRecord $record): array
    {
        $out = [];
        foreach ($record->facts(['_TODO'], false, null, true) as $fact) {
            if (!$fact->isPendingDeletion()) {
                $out[] = $this->taskJson($record, $fact);
            }
        }

        return $out;
    }

    /** Letzte Aenderung des Datensatzes (CHAN): Zeitpunkt und Benutzer; null, wenn keine vermerkt ist. */
    private function lastChangeJson(GedcomRecord $record): array|null
    {
        if (preg_match('/\n1 CHAN/', $record->gedcom()) !== 1) {
            return null;
        }

        return [
            'time' => $record->lastChangeTimestamp()->format('c'),
            'user' => $record->lastChangeUser(),
        ];
    }

    /** Heute als julianischer Tag (gregorianisch). */
    private function todayJd(): int
    {
        return (new \Fisharebest\ExtCalendar\GregorianCalendar())->ymdToJd((int) date('Y'), (int) date('n'), (int) date('j'));
    }
}
