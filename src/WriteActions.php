<?php

declare(strict_types=1);

namespace Api4Webtrees;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Exceptions\FileUploadException;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\MediaFileService;
use Fisharebest\Webtrees\Services\PendingChangesService;
use Fisharebest\Webtrees\Validator;
use League\Flysystem\FilesystemException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_column;
use function array_key_exists;
use function min;
use function max;
use function md5;
use function ltrim;
use function count;
use function array_values;
use function array_map;
use function array_splice;
use function array_pad;
use function array_keys;
use function array_filter;
use function class_exists;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function response;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

/**
 * Schreibende JSON-Endpunkte (POST). Jeder POST laeuft durch die CSRF-Pruefung von webtrees (Header X-CSRF-TOKEN
 * mit dem Token aus "Info"). Geschrieben wird nur ueber createFact/updateFact/createIndividual ... - damit gelten
 * Bearbeiterrechte, RESN-Sperren, Aenderungsprotokoll und Moderation genau wie in der Weboberflaeche.
 */
trait WriteActions
{
    /**
     * Ausstehende Aenderungen annehmen: ?xref=I123 - oder ohne xref alle des Baums.
     */
    public function postAcceptAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->moderate($request, true);
    }

    /**
     * Ausstehende Aenderungen verwerfen: ?xref=I123 - oder ohne xref alle des Baums.
     */
    public function postRejectAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->moderate($request, false);
    }

    private function moderate(ServerRequestInterface $request, bool $accept): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        if (!Auth::isModerator($tree)) {
            return $this->error(403, 'not-moderator');
        }

        $service = Registry::container()->get(PendingChangesService::class);
        $xref    = Validator::queryParams($request)->string('xref', '');

        if ($xref === '') {
            $accept ? $service->acceptTree($tree, 10000) : $service->rejectTree($tree);
        } else {
            $record = Registry::gedcomRecordFactory()->make($xref, $tree);

            if ($record === null) {
                return $this->error(404, 'not-found');
            }

            $accept ? $service->acceptRecord($record) : $service->rejectRecord($record);
        }

        return response(['ok' => true, 'pending' => $service->pendingXrefs($tree)->count()]);
    }

    /**
     * Ereignis anlegen oder aendern: ?xref=I123
     * Rumpf: { factId?, tag, value?, date?, place?, note? }  oder  { factId?, gedcom: "1 BIRT\n2 DATE ..." }
     * Beim Aendern bleiben alle nicht genannten Unterzeilen (Quellen, Medien ...) erhalten.
     */
    public function postFactAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $record = Registry::gedcomRecordFactory()->make($this->xref($request), $tree);
        $denied = $this->denyEdit($record);

        if ($denied !== null) {
            return $denied;
        }

        $body    = $this->body($request);
        $fact_id = $this->str($body, 'factId');
        $old     = null;

        if ($fact_id !== '') {
            foreach ($record->facts([], false, null, true) as $fact) {
                if ($fact->id() === $fact_id) {
                    $old = $fact;
                    break;
                }
            }

            if ($old === null) {
                return $this->error(404, 'fact-not-found');
            }

            if (!$old->canEdit()) {
                return $this->error(403, 'fact-locked');
            }
        }

        [$gedcom, $problem] = $this->checkedFactGedcom($body, $old?->gedcom() ?? '');

        if ($problem !== null) {
            return $this->error(400, $problem);
        }

        if ($old === null) {
            $record->createFact($gedcom, true);
        } else {
            $record->updateFact($fact_id, $gedcom, true);
        }

        return $this->written($record);
    }

    /**
     * Paten, Trauzeugen und andere Beteiligte eines Ereignisses schreiben (ab Stufe 20): ?xref=I123 bzw. ?xref=F12
     * Rumpf: { factId, linked?: [{ xref, role, rela?, note? }], free?: [{ text, role }], convertLevel1? }
     * - linked ersetzt die Liste der verknuepften Personen ("2 _ASSO @I…@" + "3 RELA") in dieser Reihenfolge. role:
     *   godparent oder witness (RELA wird so geschrieben, klein, wie webtrees selbst), other mit rela als freiem Text.
     *   Stand die Person schon am Ereignis, bleiben ihre weiteren Unterzeilen (3 SOUR ...) und - wenn sie zur Rolle
     *   passt - die bisherige Schreibweise von RELA ("Godfather"); note ersetzt nur die eingebettete Notiz, wenn genannt.
     *   Verknuepfungen zu Personen, die der Schreibende nicht einmal als Verweis sehen darf, bleiben immer stehen.
     * - free ersetzt die Personen ohne Datensatz: "2 _GODP <Text>" (Paten) und "2 _WITN <Text>" (Zeugen), je Person
     *   eine Zeile (GEDCOM-L); alte Notizen "Paten: …"/"Trauzeugen: …" am Ereignis gehen dabei in diese Form ueber.
     * - convertLevel1: true verschiebt "1 ASSO" der Person, die in linked stehen, in die Taufe (nur CHR/BAPM einer Person);
     *   ihre Unterzeilen (Notiz, Quelle) kommen mit.
     * Nicht genannte Teile (linked oder free) bleiben, wie sie sind. Antwort: factId (die neue Kennung des Ereignisses).
     */
    public function postAssociationAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $record = Registry::gedcomRecordFactory()->make($this->xref($request), $tree);
        $denied = $this->denyEdit($record);

        if ($denied !== null) {
            return $denied;
        }

        $body    = $this->body($request);
        $fact_id = $this->str($body, 'factId');
        $old     = null;

        foreach ($record->facts([], false, null, true) as $fact) {
            if ($fact->id() === $fact_id) {
                $old = $fact;
                break;
            }
        }

        if ($old === null) {
            return $this->error(404, 'fact-not-found');
        }

        if (!$old->canEdit()) {
            return $this->error(403, 'fact-locked');
        }

        $tag    = $this->shortTag($old->tag());
        $gedcom = $old->gedcom();
        [$kopf] = explode("\n", $gedcom, 2);
        preg_match_all('/\n2 [^\n]*(?:\n[3-9] [^\n]*)*/', substr($gedcom, strlen($kopf)), $treffer);
        $bloecke = $treffer[0];
        $level1  = [];

        // 1 ASSO der Person (fuer convertLevel1): xref => [Fakt-ID, Unterzeilen eine Ebene tiefer]
        if (($body['convertLevel1'] ?? false) === true) {
            if (!$record instanceof Individual || !in_array($tag, ['CHR', 'BAPM'], true)) {
                return $this->error(400, 'not-a-baptism');
            }

            foreach ($record->facts(['ASSO'], false, null, true) as $fact) {
                if (preg_match('/^1 ASSO @([^@]+)@((?:\n[2-9] [^\n]*)*)$/', $fact->gedcom(), $m) === 1 && $fact->canEdit()) {
                    $unter = (string) preg_replace_callback('/\n([2-8]) /', static fn (array $x): string => "\n" . ((int) $x[1] + 1) . ' ', $m[2]);
                    $level1[$m[1]] ??= [$fact->id(), (string) preg_replace('/\n3 RELA [^\n]*/', '', $unter)];
                }
            }
        }

        if (array_key_exists('linked', $body)) {
            if (!is_array($body['linked'])) {
                return $this->error(400, 'invalid-value');
            }

            // Bisherige Verknuepfungen: Position, Kennung, Block
            $alt = [];

            foreach ($bloecke as $i => $block) {
                if (preg_match('/^\n2 _ASSO @([^@]+)@/', $block, $m) === 1) {
                    $alt[] = [$i, $m[1], $block];
                }
            }

            $neu      = [];
            $benutzt  = [];
            $verschoben = [];

            foreach ($body['linked'] as $eintrag) {
                if (!is_array($eintrag)) {
                    return $this->error(400, 'invalid-value');
                }

                $xref = trim((string) ($eintrag['xref'] ?? ''), '@ ');
                $role = strtolower(trim((string) ($eintrag['role'] ?? '')));
                $rela = GedcomText::line((string) ($eintrag['rela'] ?? ''));
                $note = array_key_exists('note', $eintrag) ? (string) $eintrag['note'] : null;

                if (!Registry::individualFactory()->make($xref, $tree) instanceof Individual) {
                    return $this->error(404, 'individual-not-found');
                }

                if (!in_array($role, ['godparent', 'witness', 'other'], true) || ($role === 'other' && $rela === '')) {
                    return $this->error(400, 'invalid-role');
                }

                if (GedcomText::looksLikePointer($rela) || ($note !== null && GedcomText::looksLikePointer($note))) {
                    return $this->error(400, 'invalid-value');
                }

                // Unterzeilen der bisherigen Verknuepfung derselben Person behalten (oder des 1 ASSO, der hereinkommt)
                $unter = '';
                $rela_alt = '';

                foreach ($alt as $n => [, $x, $block]) {
                    if ($x === $xref && !isset($benutzt[$n])) {
                        $benutzt[$n] = true;
                        $unter       = (string) preg_replace('/^\n2 _ASSO [^\n]*/', '', $block);
                        break;
                    }
                }

                if ($unter === '' && isset($level1[$xref]) && !isset($verschoben[$xref])) {
                    $verschoben[$xref] = $level1[$xref][0];
                    $unter             = $level1[$xref][1];
                }

                if (preg_match('/\n3 RELA ([^\n]*)/', $unter, $m) === 1) {
                    $rela_alt = trim($m[1]);
                }

                $unter = (string) preg_replace('/\n3 RELA [^\n]*/', '', $unter);
                $rela  = match ($role) {
                    'godparent', 'witness' => $rela_alt !== '' && $this->associateRole($rela_alt) === $role ? $rela_alt : $role,
                    default => $rela,
                };

                if ($note !== null) {
                    $unter = (string) preg_replace('/\n3 NOTE (?!@)[^\n]*(\n4 CON[CT][^\n]*)*/', '', $unter);
                    $text  = GedcomText::multiline($note, 4);
                    $unter = ($text === '' ? '' : "\n3 NOTE " . $text) . $unter;
                }

                $neu[] = "\n2 _ASSO @" . $xref . "@\n3 RELA " . $rela . $unter;
            }

            // Verweise, die der Schreibende nicht sehen darf, bleiben stehen - die App kennt sie gar nicht
            foreach ($alt as $n => [, $x, $block]) {
                $person = Registry::individualFactory()->make($x, $tree);

                if (!isset($benutzt[$n]) && $person instanceof Individual && !$person->canShowName()) {
                    $neu[] = $block;
                }
            }

            $stelle  = $alt === [] ? $this->stelleFuerBeteiligte($bloecke) : $alt[0][0];
            $bloecke = $this->bloeckeErsetzen($bloecke, array_column($alt, 0), $stelle, $neu);
        } else {
            $verschoben = [];
        }

        if (array_key_exists('free', $body)) {
            if (!is_array($body['free'])) {
                return $this->error(400, 'invalid-value');
            }

            $neu = [];

            foreach ($body['free'] as $eintrag) {
                $role = strtolower(trim((string) (is_array($eintrag) ? ($eintrag['role'] ?? '') : '')));
                $text = GedcomText::line(str_replace("\n", ' ', (string) (is_array($eintrag) ? ($eintrag['text'] ?? '') : $eintrag)));

                if (!in_array($role, ['godparent', 'witness'], true)) {
                    return $this->error(400, 'invalid-role');
                }

                if (GedcomText::looksLikePointer($text)) {
                    return $this->error(400, 'invalid-value');
                }

                if ($text !== '') {
                    $neu[] = "\n2 " . ($role === 'godparent' ? '_GODP' : '_WITN') . ' ' . $text;
                }
            }

            $weg = [];

            foreach ($bloecke as $i => $block) {
                $frei_notiz = preg_match('/^\n2 NOTE (?!@)/', $block) === 1
                    && preg_match('/^(paten|taufpaten|gevattern|trauzeugen|zeugen):/iu', trim(GedcomText::mitFortsetzung(
                        (string) preg_replace('/^\n2 NOTE ?([^\n]*)[\s\S]*$/', '$1', $block),
                        (string) preg_replace('/^\n2 NOTE[^\n]*/', '', $block),
                        2,
                    ))) === 1;

                if (preg_match('/^\n2 (_GODP|_WITN)( |$)/', $block) === 1 || $frei_notiz) {
                    $weg[] = $i;
                }
            }

            $stelle  = $weg === [] ? $this->stelleFuerBeteiligte($bloecke) : $weg[0];
            $bloecke = $this->bloeckeErsetzen($bloecke, $weg, $stelle, $neu);
        }

        $gedcom_neu = $kopf . implode('', $bloecke);

        if (($problem = GedcomText::factGedcomProblem($gedcom_neu)) !== null) {
            return $this->error(400, $problem);
        }

        if ($gedcom_neu !== $gedcom) {
            $record->updateFact($fact_id, $gedcom_neu, true);
        }

        foreach ($verschoben as $asso_id) {
            $record->deleteFact($asso_id, true);
        }

        return $this->written($record, ['factId' => md5($gedcom_neu)]);
    }

    /**
     * Wohin neue Paten/Zeugen kommen, wenn es noch keine gibt: vor die erste Notiz, Quelle oder Medium des Ereignisses
     * (also hinter Datum, Ort, Art ...), sonst ans Ende.
     *
     * @param array<int,string> $bloecke
     */
    private function stelleFuerBeteiligte(array $bloecke): int
    {
        foreach ($bloecke as $i => $block) {
            if (preg_match('/^\n2 (NOTE|SOUR|OBJE|RESN)( |$|\n)/', $block) === 1) {
                return $i;
            }
        }

        return count($bloecke);
    }

    /**
     * Die Bloecke [$weg] entfernen und [$neu] an der Stelle [$stelle] (Index vor dem Entfernen) einsetzen.
     *
     * @param array<int,string> $bloecke
     * @param array<int,int>    $weg
     * @param array<int,string> $neu
     *
     * @return array<int,string>
     */
    private function bloeckeErsetzen(array $bloecke, array $weg, int $stelle, array $neu): array
    {
        $vorher = 0;

        foreach ($weg as $i) {
            if ($i < $stelle) {
                $vorher++;
            }
            unset($bloecke[$i]);
        }

        $bloecke = array_values($bloecke);
        array_splice($bloecke, $stelle - $vorher, 0, $neu);

        return $bloecke;
    }

    /**
     * Quellenverweis (ab Stufe 18): ?xref=I123
     * Rumpf: { factId?, index?, delete?, moveTo?, source?, page?, quality?, date?, text?, note?, media? }
     * - factId: das Ereignis; ohne factId ein allgemeiner Verweis am Datensatz ("1 SOUR"). Ist factId selbst ein
     *   solcher allgemeiner Verweis (Tag SOUR), wird dieser bearbeitet.
     * - index: der wievielte Verweis des Ereignisses (ab 0); ohne index wird ein neuer angehaengt.
     * - delete: true entfernt ihn; moveTo: neue Stelle (ab 0) - beides ohne weitere Aenderung.
     * - source: Kennung einer Quelle ("S1") oder freier Text ("laut Martha Meier"); fehlt sie, bleibt die Quelle.
     * - page, quality (0-3, "" = weg), date, text, note, media (Liste von Kennungen): nur genannte Teile werden
     *   ersetzt, alles andere am Verweis und am Ereignis bleibt, wie es ist.
     */
    public function postCitationAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $record = Registry::gedcomRecordFactory()->make($this->xref($request), $tree);
        $denied = $this->denyEdit($record);

        if ($denied !== null) {
            return $denied;
        }

        $body    = $this->body($request);
        $fact_id = $this->str($body, 'factId');

        foreach (['source', 'page', 'text', 'note'] as $key) {
            if ($key !== 'source' && GedcomText::looksLikePointer($this->str($body, $key))) {
                return $this->error(400, 'invalid-value');
            }
        }

        // Die Qualitaet kommt als Zahl (3) oder Text ("3"); "" oder null = weg
        if (array_key_exists('quality', $body)) {
            $body['quality'] = $body['quality'] === null ? '' : trim((string) $body['quality']);
        }

        if (array_key_exists('quality', $body) && $this->str($body, 'quality') !== '' && preg_match('/^[0-3]$/', $this->str($body, 'quality')) !== 1) {
            return $this->error(400, 'invalid-quality');
        }

        if (array_key_exists('date', $body) && $this->str($body, 'date') !== '' && !(new Date(strtoupper($this->str($body, 'date'))))->isOK()) {
            return $this->error(400, 'invalid-date');
        }

        if (array_key_exists('source', $body)) {
            $source = $this->str($body, 'source');

            if ($source === '') {
                return $this->error(400, 'source-missing');
            }

            if (preg_match('/^@?([A-Za-z0-9:_.-]+)@?$/', $source, $m) === 1 && Registry::sourceFactory()->make($m[1], $tree) !== null) {
                $body['source'] = '@' . $m[1] . '@';
            } elseif (GedcomText::looksLikePointer($source)) {
                return $this->error(404, 'source-not-found');
            }
        }

        // Allgemeiner Verweis am Datensatz: neu anlegen
        if ($fact_id === '') {
            if (!array_key_exists('source', $body)) {
                return $this->error(400, 'source-missing');
            }

            $neu = $this->citationGedcom(1, '', $body);
            $record->createFact($neu, true);

            // Die Kennung des Ereignisses ist ein Hash seines Inhalts - nach dem Schreiben braucht die App die neue.
            return $this->written($record, ['factId' => md5($neu)]);
        }

        $old = null;

        foreach ($record->facts([], false, null, true) as $fact) {
            if ($fact->id() === $fact_id) {
                $old = $fact;
                break;
            }
        }

        if ($old === null) {
            return $this->error(404, 'fact-not-found');
        }

        if (!$old->canEdit()) {
            return $this->error(403, 'fact-locked');
        }

        $gedcom = $old->gedcom();

        // Der allgemeine Verweis ist selbst das Ereignis ("1 SOUR @S1@ ...")
        if ($this->shortTag($old->tag()) === 'SOUR') {
            if (($body['delete'] ?? false) === true) {
                $record->deleteFact($fact_id, true);

                return $this->written($record);
            }

            $neu = $this->citationGedcom(1, $gedcom, $body);
            $record->updateFact($fact_id, $neu, true);

            return $this->written($record, ['factId' => md5($neu)]);
        }

        // Das Ereignis in seine Ebene-2-Bloecke zerlegen; die Verweise sind die Bloecke "2 SOUR"
        [$kopf] = explode("\n", $gedcom, 2);
        preg_match_all('/\n2 [^\n]*(?:\n[3-9] [^\n]*)*/', substr($gedcom, strlen($kopf)), $treffer);
        $bloecke   = $treffer[0];
        $positionen = array_values(array_filter(array_keys($bloecke), static fn (int $i): bool => str_starts_with($bloecke[$i], "\n2 SOUR")));
        $index     = array_key_exists('index', $body) && $body['index'] !== null && $body['index'] !== '' ? (int) $body['index'] : null;

        if ($index !== null && !array_key_exists($index, $positionen)) {
            return $this->error(404, 'citation-not-found');
        }

        if ($index === null) {
            if (!array_key_exists('source', $body)) {
                return $this->error(400, 'source-missing');
            }

            $bloecke[] = $this->citationGedcom(2, '', $body);
        } elseif (($body['delete'] ?? false) === true) {
            unset($bloecke[$positionen[$index]]);
        } elseif (array_key_exists('moveTo', $body)) {
            $ziel = max(0, min(count($positionen) - 1, (int) $body['moveTo']));
            // Nur die Verweise untereinander umsortieren, alle anderen Bloecke bleiben an ihrer Stelle
            $verweise = array_map(static fn (int $i): string => $bloecke[$i], $positionen);
            $block    = $verweise[$index];
            unset($verweise[$index]);
            array_splice($verweise, $ziel, 0, [$block]);
            foreach ($positionen as $n => $i) {
                $bloecke[$i] = $verweise[$n];
            }
        } else {
            $bloecke[$positionen[$index]] = $this->citationGedcom(2, $bloecke[$positionen[$index]], $body);
        }

        $neu = $kopf . implode('', $bloecke);

        if (($problem = GedcomText::factGedcomProblem($neu)) !== null) {
            return $this->error(400, $problem);
        }

        $record->updateFact($fact_id, $neu, true);

        return $this->written($record, ['factId' => md5($neu)]);
    }

    /**
     * Ein Verweis als GEDCOM ("\n2 SOUR @S1@\n3 PAGE ..." bzw. "1 SOUR ..." ohne fuehrenden Umbruch fuer Ebene 1).
     * [$alt]: der bisherige Block (leer = neu). Nur im Rumpf genannte Teile werden ersetzt; Unterzeilen, die die App
     * nicht kennt, bleiben.
     *
     * @param array<string,mixed> $body
     */
    private function citationGedcom(int $ebene, string $alt, array $body): string
    {
        $u        = $ebene + 1;
        $praefix  = $ebene === 1 ? '' : "\n";
        $alt_ohne = $ebene === 1 && $alt !== '' ? "\n" . $alt : $alt;
        [$kopf, $rest] = $alt_ohne === '' ? ['', ''] : (array_pad(explode("\n", ltrim($alt_ohne, "\n"), 2), 2, ''));
        $rest = $rest === '' ? '' : "\n" . $rest;

        // Die Quelle selbst: neu oder wie bisher (samt CONT-Fortsetzungen einer Text-Quelle)
        if (array_key_exists('source', $body)) {
            $wert = $this->str($body, 'source');
            $kopf = $ebene . ' SOUR ' . (str_starts_with($wert, '@') ? $wert : GedcomText::multiline($wert, $u));
            $rest = (string) preg_replace('/^(\n' . $u . ' CON[CT] ?[^\n]*)+/', '', $rest);
        } elseif ($kopf === '') {
            $kopf = $ebene . ' SOUR';
        }

        $ersetzen = function (string $tag, string $neu) use (&$rest, $u): void {
            $rest = (string) preg_replace('/\n' . $u . ' ' . $tag . '(?: [^\n]*)?(?:\n[' . ($u + 1) . '-9] [^\n]*)*/', '', $rest);
            $rest .= $neu;
        };

        if (array_key_exists('page', $body)) {
            $page = GedcomText::multiline($this->str($body, 'page'), $u + 1);
            $ersetzen('PAGE', $page === '' ? '' : "\n" . $u . ' PAGE ' . $page);
        }

        if (array_key_exists('quality', $body)) {
            $q = $this->str($body, 'quality');
            $ersetzen('QUAY', $q === '' ? '' : "\n" . $u . ' QUAY ' . $q);
        }

        if (array_key_exists('date', $body) || array_key_exists('text', $body)) {
            // DATA buendelt Datum und Text der Fundstelle; nicht genannte Teile aus dem alten DATA uebernehmen
            $alt_data = GedcomText::unterzeilen($rest, $u, 'DATA')[0][1] ?? '';
            $datum    = array_key_exists('date', $body) ? strtoupper(GedcomText::line($this->str($body, 'date'))) : (GedcomText::unterzeilen($alt_data, $u + 1, 'DATE')[0][0] ?? '');
            $text     = array_key_exists('text', $body) ? $this->str($body, 'text') : GedcomText::ersterWert(ltrim($alt_data, "\n"), $u + 1, 'TEXT');
            $data     = '';

            if ($datum !== '') {
                $data .= "\n" . ($u + 1) . ' DATE ' . $datum;
            }

            if (trim($text) !== '') {
                $data .= "\n" . ($u + 1) . ' TEXT ' . GedcomText::multiline($text, $u + 2);
            }

            $ersetzen('DATA', $data === '' ? '' : "\n" . $u . ' DATA' . $data);
        }

        if (array_key_exists('note', $body)) {
            $note = GedcomText::multiline($this->str($body, 'note'), $u + 1);
            // Nur eingebettete Notizen ersetzen; Verweise auf Notiz-Datensaetze bleiben
            $rest = (string) preg_replace('/\n' . $u . ' NOTE (?!@)[^\n]*(\n' . ($u + 1) . ' CONT[^\n]*)*/', '', $rest);
            $rest .= $note === '' ? '' : "\n" . $u . ' NOTE ' . $note;
        }

        if (array_key_exists('media', $body) && is_array($body['media'])) {
            $rest = (string) preg_replace('/\n' . $u . ' OBJE @[^\n]*/', '', $rest);

            foreach ($body['media'] as $m) {
                if (preg_match('/^@?([A-Za-z0-9:_.-]+)@?$/', (string) $m, $mm) === 1) {
                    $rest .= "\n" . $u . ' OBJE @' . $mm[1] . '@';
                }
            }
        }

        return $praefix . $kopf . $rest;
    }

    /**
     * Quelle anlegen oder aendern (ab Stufe 18): ohne ?xref neu (title Pflicht), mit ?xref=S1 aendern.
     * Rumpf: { title?, author?, publication?, abbreviation?, text?, note?, repository?, callNumber?, media? }
     * Nur genannte Teile werden ersetzt; repository: Kennung eines Archivs ("R1") oder "" (weg), callNumber: Signatur
     * am (ersten) Archiv; media: die verknuepften Medienobjekte (Kennungen, ersetzt die Liste). Eine neue Datei kommt
     * ueber die Route Media mit ?xref=S1 (verknuepft gleich) oder mit link=false und dann hier. Antwort: xref.
     */
    public function postSourceAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $xref = $this->xrefOptional($request);
        $body = $this->body($request);

        foreach (['title', 'author', 'publication', 'abbreviation', 'text', 'note', 'callNumber'] as $key) {
            if (GedcomText::looksLikePointer($this->str($body, $key))) {
                return $this->error(400, 'invalid-value');
            }
        }

        if (array_key_exists('repository', $body) && $this->str($body, 'repository') !== '') {
            $repo = preg_replace('/^@|@$/', '', $this->str($body, 'repository'));

            if (Registry::repositoryFactory()->make($repo, $tree) === null) {
                return $this->error(404, 'repository-not-found');
            }

            $body['repository'] = $repo;
        }

        if ($xref === '') {
            if (!Auth::isEditor($tree)) {
                return $this->error(403, 'not-editable');
            }

            if (GedcomText::line($this->str($body, 'title')) === '') {
                return $this->error(400, 'title-missing');
            }

            $source = $tree->createRecord($this->sourceGedcom("0 @@ SOUR", $body));

            return $this->written($source, ['xref' => $source->xref()], 201);
        }

        $source = Registry::sourceFactory()->make($xref, $tree);
        $denied = $this->denyEdit($source);

        if ($denied !== null) {
            return $denied;
        }

        $source->updateRecord($this->sourceGedcom($source->gedcom(), $body), true);

        return $this->written($source, ['xref' => $source->xref()]);
    }

    /**
     * Das GEDCOM einer Quelle mit den im Rumpf genannten Teilen ersetzt; alles andere (Medien, weitere Archive,
     * Notiz-Datensaetze, unbekannte Zeilen) bleibt.
     *
     * @param array<string,mixed> $body
     */
    private function sourceGedcom(string $alt, array $body): string
    {
        [$kopf, $rest] = array_pad(explode("\n", $alt, 2), 2, '');
        $rest = $rest === '' ? '' : "\n" . $rest;

        $ersetzen = static function (string $tag, string $neu) use (&$rest): void {
            $rest = (string) preg_replace('/\n1 ' . $tag . '(?: [^\n]*)?(?:\n[2-9] [^\n]*)*/', '', $rest);
            $rest .= $neu;
        };

        foreach (['title' => 'TITL', 'author' => 'AUTH', 'publication' => 'PUBL', 'abbreviation' => 'ABBR', 'text' => 'TEXT'] as $key => $tag) {
            if (array_key_exists($key, $body)) {
                $wert = GedcomText::multiline($this->str($body, $key), 2);
                $ersetzen($tag, $wert === '' ? '' : "\n1 " . $tag . ' ' . $wert);
            }
        }

        if (array_key_exists('note', $body)) {
            $note = GedcomText::multiline($this->str($body, 'note'), 2);
            // Nur die eingebettete Notiz ersetzen; Verweise auf Notiz-Datensaetze bleiben
            $rest = (string) preg_replace('/\n1 NOTE (?!@)[^\n]*(\n2 CONT[^\n]*)*/', '', $rest);
            $rest .= $note === '' ? '' : "\n1 NOTE " . $note;
        }

        if (array_key_exists('repository', $body)) {
            $repo = $this->str($body, 'repository');
            // Das erste Archiv ersetzen, weitere bleiben
            $alte = GedcomText::unterzeilen($rest, 1, 'REPO');
            $alt_caln = $alte === [] ? '' : (GedcomText::unterzeilen($alte[0][1], 2, 'CALN')[0][0] ?? '');
            $caln = array_key_exists('callNumber', $body) ? GedcomText::line($this->str($body, 'callNumber')) : $alt_caln;
            $rest = (string) preg_replace('/\n1 REPO(?: [^\n]*)?(?:\n[2-9] [^\n]*)*/', '', $rest, 1);
            if ($repo !== '') {
                $rest .= "\n1 REPO @" . $repo . '@' . ($caln === '' ? '' : "\n2 CALN " . $caln);
            }
        } elseif (array_key_exists('callNumber', $body)) {
            $caln = GedcomText::line($this->str($body, 'callNumber'));
            $rest = (string) preg_replace_callback('/(\n1 REPO [^\n]*)((?:\n[2-9] [^\n]*)*)/', static function (array $m) use ($caln): string {
                $unter = (string) preg_replace('/\n2 CALN(?: [^\n]*)?(?:\n[3-9] [^\n]*)*/', '', $m[2]);

                return $m[1] . ($caln === '' ? '' : "\n2 CALN " . $caln) . $unter;
            }, $rest, 1);
        }

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

    /**
     * Archiv anlegen oder umbenennen (ab Stufe 18): ohne ?xref neu, mit ?xref=R1 aendern. Rumpf: { name }
     */
    public function postRepositoryAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $xref = $this->xrefOptional($request);
        $name = GedcomText::line($this->str($this->body($request), 'name'));

        if ($name === '' || GedcomText::looksLikePointer($name)) {
            return $this->error(400, 'name-missing');
        }

        if ($xref === '') {
            if (!Auth::isEditor($tree)) {
                return $this->error(403, 'not-editable');
            }

            $repo = $tree->createRecord("0 @@ REPO\n1 NAME " . $name);

            return $this->written($repo, ['xref' => $repo->xref()], 201);
        }

        $repo   = Registry::repositoryFactory()->make($xref, $tree);
        $denied = $this->denyEdit($repo);

        if ($denied !== null) {
            return $denied;
        }

        $gedcom = (string) preg_replace('/\n1 NAME [^\n]*(\n[2-9] [^\n]*)*/', '', $repo->gedcom());
        $repo->updateRecord($gedcom . "\n1 NAME " . $name, true);

        return $this->written($repo, ['xref' => $repo->xref()]);
    }

    /**
     * Ereignis loeschen: ?xref=I123   Rumpf: { factId }
     */
    public function postDeleteFactAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $record = Registry::gedcomRecordFactory()->make($this->xref($request), $tree);
        $denied = $this->denyEdit($record);

        if ($denied !== null) {
            return $denied;
        }

        $fact_id = $this->str($this->body($request), 'factId');

        foreach ($record->facts([], false, null, true) as $fact) {
            if ($fact->id() === $fact_id) {
                if (!$fact->canEdit()) {
                    return $this->error(403, 'fact-locked');
                }

                if (in_array($this->shortTag($fact->tag()), GedcomText::LINK_TAGS, true)) {
                    return $this->error(400, 'link-tag-not-allowed');
                }

                $record->deleteFact($fact_id, true);

                return $this->written($record);
            }
        }

        return $this->error(404, 'fact-not-found');
    }

    /**
     * Person anlegen und verknuepfen.
     * Rumpf: { relation: child|spouse|father|mother|none, relativeTo?, family?,
     *          given, surname, sex: M|F|U, birthDate?, birthPlace?, dead?, deathDate?, deathPlace?,
     *          marriageDate?, marriagePlace?, facts?: [{tag, value?, date?, place?, note?} | {gedcom}, ...] }
     */
    public function postAddIndividualAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        if (!Auth::isEditor($tree)) {
            return $this->error(403, 'not-editor');
        }

        $body     = $this->body($request);
        $relation = $this->str($body, 'relation', 'none');

        if (!in_array($relation, ['child', 'spouse', 'father', 'mother', 'none'], true)) {
            return $this->error(400, 'invalid-relation');
        }

        $relative = null;

        if ($relation !== 'none') {
            $relative = Registry::individualFactory()->make($this->str($body, 'relativeTo'), $tree);
            $denied   = $this->denyEdit($relative);

            if ($denied !== null) {
                return $denied;
            }
        }

        foreach (['birthDate', 'deathDate', 'marriageDate'] as $key) {
            if ($this->str($body, $key) !== '' && !(new Date($this->str($body, $key)))->isOK()) {
                return $this->error(400, 'invalid-date');
            }
        }

        foreach (['birthPlace', 'deathPlace', 'marriagePlace'] as $key) {
            if (GedcomText::looksLikePointer($this->str($body, $key))) {
                return $this->error(400, 'invalid-value');
            }
        }

        $given   = GedcomText::namePart($this->str($body, 'given'));
        $surname = GedcomText::namePart($this->str($body, 'surname'));
        $sex     = strtoupper($this->str($body, 'sex', 'U'));
        $sex     = in_array($sex, ['M', 'F', 'U', 'X'], true) ? $sex : 'U';

        if ($given === '' && $surname === '') {
            return $this->error(400, 'name-required');
        }

        if ($relation === 'father') {
            $sex = 'M';
        } elseif ($relation === 'mother') {
            $sex = 'F';
        }

        // Vorab pruefen, damit bei einem Fehler nichts halb angelegt ist.
        $family = $relative === null ? null : $this->targetFamily($relation, $relative, $this->str($body, 'family'));

        if ($family instanceof ResponseInterface) {
            return $family;
        }

        // Weitere Angaben (Beruf, Wohnort ...) stehen gleich im neuen Datensatz - ein Schritt, nichts halb angelegt.
        $extra = $body['facts'] ?? [];

        if (!is_array($extra)) {
            return $this->error(400, 'invalid-gedcom');
        }

        $extra_gedcom = '';

        foreach ($extra as $spec) {
            [$fact_gedcom, $problem] = is_array($spec) ? $this->checkedFactGedcom($spec, '') : ['', 'invalid-gedcom'];

            if ($problem !== null) {
                return $this->error(400, $problem);
            }

            $extra_gedcom .= "\n" . $fact_gedcom;
        }

        $gedcom = "0 @@ INDI\n1 NAME " . trim($given . ' /' . $surname . '/');
        $gedcom .= $given === '' ? '' : "\n2 GIVN " . $given;
        $gedcom .= $surname === '' ? '' : "\n2 SURN " . $surname;
        $gedcom .= "\n1 SEX " . $sex;
        $gedcom .= GedcomText::eventGedcom('BIRT', $this->str($body, 'birthDate'), $this->str($body, 'birthPlace'), false);
        $gedcom .= GedcomText::eventGedcom('DEAT', $this->str($body, 'deathDate'), $this->str($body, 'deathPlace'), ($body['dead'] ?? false) === true);
        $gedcom .= $extra_gedcom;

        $new = $tree->createIndividual($gedcom);

        if ($relative !== null) {
            $marriage = GedcomText::eventGedcom('MARR', $this->str($body, 'marriageDate'), $this->str($body, 'marriagePlace'), false);
            $family   = $this->attach($relation, $new, $relative, $family, $marriage, false);
        }

        return $this->written($new, ['family' => $family instanceof GedcomRecord ? $family->xref() : null], 201);
    }

    /**
     * Zwei vorhandene Personen verknuepfen - wie AddIndividual, nur ohne neue Person.
     * Rumpf: { individual, relation: child|spouse|father|mother, relativeTo, family?, marriageDate?, marriagePlace? }
     * "individual" wird Kind/Partner/Vater/Mutter von "relativeTo".
     */
    public function postLinkAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree     = Validator::attributes($request)->tree();
        $body     = $this->body($request);
        $relation = $this->str($body, 'relation');

        if (!in_array($relation, ['child', 'spouse', 'father', 'mother'], true)) {
            return $this->error(400, 'invalid-relation');
        }

        $individual = Registry::individualFactory()->make($this->str($body, 'individual'), $tree);
        $relative   = Registry::individualFactory()->make($this->str($body, 'relativeTo'), $tree);

        foreach ([$individual, $relative] as $record) {
            $denied = $this->denyEdit($record);

            if ($denied !== null) {
                return $denied;
            }
        }

        if ($individual->xref() === $relative->xref()) {
            return $this->error(400, 'invalid-relation');
        }

        if ($this->str($body, 'marriageDate') !== '' && !(new Date($this->str($body, 'marriageDate')))->isOK()) {
            return $this->error(400, 'invalid-date');
        }

        if (GedcomText::looksLikePointer($this->str($body, 'marriagePlace'))) {
            return $this->error(400, 'invalid-value');
        }

        $family = $this->targetFamily($relation, $relative, $this->str($body, 'family'));

        if ($family instanceof ResponseInterface) {
            return $family;
        }

        // Schon so verknuepft? Dann nichts doppelt eintragen.
        $already = match ($relation) {
            'child'            => $family instanceof Family && $family->children()->contains(static fn (Individual $c): bool => $c->xref() === $individual->xref()),
            'spouse'           => $relative->spouseFamilies()->contains(static fn (Family $f): bool => $f->spouse($relative)?->xref() === $individual->xref()),
            'father', 'mother' => $family instanceof Family && ($family->husband()?->xref() === $individual->xref() || $family->wife()?->xref() === $individual->xref()),
        };

        if ($already) {
            return $this->error(409, 'link-exists');
        }

        $marriage = GedcomText::eventGedcom('MARR', $this->str($body, 'marriageDate'), $this->str($body, 'marriagePlace'), false);
        $family   = $this->attach($relation, $individual, $relative, $family, $marriage, true);

        return $this->written($individual, ['family' => $family->xref()]);
    }

    /**
     * Die Familie, in die eine Person als Kind/Vater/Mutter von $relative kommt - oder null, wenn eine neue entsteht.
     * Fehler als fertige Antwort. (Partner bekommen immer eine neue Familie.)
     */
    private function targetFamily(string $relation, Individual $relative, string $family_xref): Family|ResponseInterface|null
    {
        $family = null;

        if ($relation === 'child') {
            $families = $relative->spouseFamilies();

            if ($family_xref !== '') {
                $family = $families->first(static fn (Family $f): bool => $f->xref() === $family_xref);

                if ($family === null) {
                    return $this->error(404, 'family-not-found');
                }
            } elseif ($families->count() > 1) {
                return $this->error(400, 'family-required');
            } else {
                $family = $families->first();
            }
        } elseif ($relation === 'father' || $relation === 'mother') {
            $family = $relative->childFamilies()->first();
            $slot   = $relation === 'father' ? 'HUSB' : 'WIFE';

            if ($family instanceof Family && preg_match('/\n1 ' . $slot . ' @/', $family->gedcom()) === 1) {
                return $this->error(409, 'parent-exists');
            }
        }

        if ($family instanceof Family && !$family->canEdit()) {
            return $this->error(403, 'family-locked');
        }

        return $family;
    }

    /**
     * $person als Kind/Partner/Vater/Mutter von $relative eintragen - in $family oder einer neuen Familie.
     * $person_chan: bei einer eben angelegten Person gibt es noch nichts, dessen Aenderungsdatum zu setzen waere.
     */
    private function attach(string $relation, Individual $person, Individual $relative, Family|null $family, string $marriage, bool $person_chan): Family
    {
        $tree = $person->tree();

        switch ($relation) {
            case 'child':
                if ($family instanceof Family) {
                    $family->createFact('1 CHIL @' . $person->xref() . '@', true);
                } else {
                    $link   = $this->sexCode($relative) === 'F' ? 'WIFE' : 'HUSB';
                    $family = $tree->createFamily("0 @@ FAM\n1 " . $link . ' @' . $relative->xref() . "@\n1 CHIL @" . $person->xref() . '@');
                    $relative->createFact('1 FAMS @' . $family->xref() . '@', true);
                }
                $person->createFact('1 FAMC @' . $family->xref() . '@', $person_chan);
                break;

            case 'spouse':
                $relative_link = $this->sexCode($relative) === 'F' ? 'WIFE' : 'HUSB';
                $person_link   = $relative_link === 'HUSB' ? 'WIFE' : 'HUSB';
                $family        = $tree->createFamily("0 @@ FAM\n1 " . $relative_link . ' @' . $relative->xref() . "@\n1 " . $person_link . ' @' . $person->xref() . '@' . $marriage);
                $relative->createFact('1 FAMS @' . $family->xref() . '@', true);
                $person->createFact('1 FAMS @' . $family->xref() . '@', $person_chan);
                break;

            default: // father, mother
                $link = $relation === 'father' ? 'HUSB' : 'WIFE';
                if ($family instanceof Family) {
                    $family->createFact('1 ' . $link . ' @' . $person->xref() . '@', true);
                } else {
                    $family = $tree->createFamily("0 @@ FAM\n1 " . $link . ' @' . $person->xref() . "@\n1 CHIL @" . $relative->xref() . '@');
                    $relative->createFact('1 FAMC @' . $family->xref() . '@', true);
                }
                $person->createFact('1 FAMS @' . $family->xref() . '@', $person_chan);
                break;
        }

        return $family;
    }

    /**
     * Datensatz loeschen: ?xref=I123
     * Uebergibt an die Loesch-Logik von webtrees selbst: Verweise anderer Datensaetze werden entfernt, eine Familie
     * mit nur noch einem Mitglied und ohne Ereignisse wird mit geloescht - genau wie in der Weboberflaeche.
     */
    public function postDeleteRecordAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $xref   = $this->xref($request);
        $record = Registry::gedcomRecordFactory()->make($xref, $tree);
        $denied = $this->denyEdit($record);

        if ($denied !== null) {
            return $denied;
        }

        $request = $request->withAttribute('xref', $xref);

        // webtrees 2.2: RequestHandlers\DeleteRecord::handle() - ab 2.3: Controllers\DeleteRecord::post()
        $old = 'Fisharebest\\Webtrees\\Http\\RequestHandlers\\DeleteRecord';
        $new = 'Fisharebest\\Webtrees\\Http\\Controllers\\DeleteRecord';

        if (class_exists($old)) {
            Registry::container()->get($old)->handle($request);
        } elseif (class_exists($new)) {
            Registry::container()->get($new)->post($request, $tree);
        } else {
            return $this->error(501, 'not-supported');
        }

        // Die Hinweise ("Die Familie ... wurde geloescht") sind fuer die Weboberflaeche gedacht - hier verwerfen,
        // sonst tauchen sie beim naechsten Seitenaufruf im Browser auf.
        FlashMessages::getMessages();

        return $this->written($record);
    }

    /**
     * Verknuepfung loesen - die Person bleibt, sie gehoert nur nicht mehr zur Familie.
     * Rumpf: { family: "F12", individual: "I34" }
     */
    public function postUnlinkAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree       = Validator::attributes($request)->tree();
        $body       = $this->body($request);
        $family     = Registry::familyFactory()->make($this->str($body, 'family'), $tree);
        $individual = Registry::individualFactory()->make($this->str($body, 'individual'), $tree);

        foreach ([$family, $individual] as $record) {
            $denied = $this->denyEdit($record);

            if ($denied !== null) {
                return $denied;
            }
        }

        $removed = 0;

        foreach ($family->facts(['HUSB', 'WIFE', 'CHIL'], false, null, true) as $fact) {
            if ($fact->value() === '@' . $individual->xref() . '@') {
                $family->deleteFact($fact->id(), true);
                $removed++;
            }
        }

        foreach ($individual->facts(['FAMS', 'FAMC'], false, null, true) as $fact) {
            if ($fact->value() === '@' . $family->xref() . '@') {
                $individual->deleteFact($fact->id(), true);
                $removed++;
            }
        }

        if ($removed === 0) {
            return $this->error(404, 'link-not-found');
        }

        return $this->written($individual, ['family' => $family->xref()]);
    }

    /**
     * Datei hochladen und als Medienobjekt mit einem Datensatz verknuepfen: ?xref=I123
     * multipart/form-data: file, title?, note?
     */
    public function postMediaAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        // Hochladen braucht das Upload-Recht; ein vorhandenes Medium verknuepfen nur das Bearbeitungsrecht (wie in webtrees)
        if (!Auth::canUploadMedia($tree, Auth::user()) && $this->str($this->body($request), 'media') === '') {
            return $this->error(403, 'upload-not-allowed');
        }

        $record = Registry::gedcomRecordFactory()->make($this->xref($request), $tree);
        $denied = $this->denyEdit($record);

        if ($denied !== null) {
            return $denied;
        }

        $body  = $this->body($request);

        // Ab Stufe 23: ein vorhandenes Medienobjekt verknuepfen statt hochzuladen - Rumpf { media: "M5" }
        $vorhanden = $this->str($body, 'media');
        if ($vorhanden !== '') {
            $media = Registry::mediaFactory()->make(trim($vorhanden, '@'), $tree);
            if ($media === null || !$media->canShow()) {
                return $this->error(404, 'media-not-found');
            }
            if (str_contains($record->gedcom(), "\n1 OBJE @" . $media->xref() . '@')) {
                return $this->written($record, ['media' => $media->xref()]);
            }
            $record->createFact('1 OBJE @' . $media->xref() . '@', true);

            return $this->written($record, ['media' => $media->xref()]);
        }

        $title = Registry::elementFactory()->make('OBJE:FILE:TITL')->canonical($this->str($body, 'title'));
        $note  = Registry::elementFactory()->make('OBJE:NOTE')->canonical($this->str($body, 'note'));

        // Der Upload-Dienst von webtrees prueft Dateinamen und gesperrte Endungen (php, exe ...).
        // auto=1: Dateiname wird der SHA1 des Inhalts - keine Kollisionen, keine Sonderzeichen; die Datei liegt
        // dann immer direkt im Medienordner des Baums (einen Unterordner ignoriert webtrees bei auto=1).
        $upload_request = $request->withParsedBody([
            'file_location' => 'upload',
            'folder'        => '',
            'new_file'      => '',
            'auto'          => '1',
        ]);

        try {
            $file = Registry::container()->get(MediaFileService::class)->uploadFile($upload_request);
        } catch (FileUploadException | FilesystemException) {
            // Abgebrochener Upload oder Medienordner nicht beschreibbar - fuer die App ein fachlicher Fehler, kein 500er.
            $file = '';
        }

        if ($file === '') {
            return $this->error(400, 'upload-failed');
        }

        // Art der Datei (ab Stufe 18 waehlbar): "document" fuer Scans von Urkunden und Kirchenbuchseiten, sonst "photo".
        $type   = in_array($this->str($body, 'type'), ['photo', 'document', 'certificate', 'book', 'newspaper', 'card', 'map', 'tombstone', 'audio', 'video', 'other'], true) ? $this->str($body, 'type') : 'photo';
        $gedcom = "0 @@ OBJE\n" . Registry::container()->get(MediaFileService::class)->createMediaFileGedcom($file, $type, $title, $note);
        $media  = $tree->createMediaObject($gedcom);

        // Wie webtrees selbst: das Medienobjekt sofort annehmen, damit Dateisystem und Baum zusammenpassen.
        // Die Verknuepfung zur Person bleibt eine normale (ggf. ausstehende) Aenderung.
        Registry::container()->get(PendingChangesService::class)->acceptRecord($media);

        // link=false (ab Stufe 18): nur das Medienobjekt anlegen, ohne Verknuepfung - die App haengt es danach an
        // einen Quellenverweis (Route Citation, media) oder an eine Quelle (Route Source, media).
        if (($body['link'] ?? true) !== false && $this->str($body, 'link') !== 'false') {
            $record->createFact('1 OBJE @' . $media->xref() . '@', true);
        }

        return $this->written($record, ['media' => $media->xref()], 201);
    }

    /**
     * Titel und Art eines Medienobjekts aendern (ab Stufe 23): ?xref=M1, Rumpf { title?, type? }. Betrifft die erste
     * Datei (1 FILE / 2 TITL, 2 FORM / 3 TYPE); alles andere am Medienobjekt bleibt.
     */
    public function postMediaObjectAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $media  = Registry::mediaFactory()->make($this->xref($request), $tree);
        $denied = $this->denyEdit($media);

        if ($denied !== null) {
            return $denied;
        }

        $body = $this->body($request);
        if (GedcomText::looksLikePointer($this->str($body, 'title'))) {
            return $this->error(400, 'invalid-value');
        }
        $arten = ['photo', 'document', 'certificate', 'book', 'newspaper', 'card', 'map', 'tombstone', 'audio', 'video', 'electronic', 'film', 'fiche', 'magazine', 'manuscript', 'painting', 'other'];
        if (array_key_exists('type', $body) && $this->str($body, 'type') !== '' && !in_array($this->str($body, 'type'), $arten, true)) {
            return $this->error(400, 'invalid-type');
        }

        $gedcom = (string) preg_replace_callback('/(\n1 FILE[^\n]*)((?:\n[2-9] [^\n]*)*)/', function (array $m) use ($body): string {
            $unter = $m[2];
            if (array_key_exists('title', $body)) {
                $titel = GedcomText::line($this->str($body, 'title'));
                $unter = (string) preg_replace('/\n2 TITL(?: [^\n]*)?(?:\n[3-9] [^\n]*)*/', '', $unter);
                $unter = ($titel === '' ? '' : "\n2 TITL " . $titel) . $unter;
            }
            if (array_key_exists('type', $body)) {
                $art   = $this->str($body, 'type');
                $unter = (string) preg_replace('/\n3 TYPE[^\n]*/', '', $unter);
                if ($art !== '') {
                    $unter = preg_match('/\n2 FORM[^\n]*/', $unter) === 1
                        ? (string) preg_replace('/(\n2 FORM[^\n]*)/', '$1' . "\n3 TYPE " . $art, $unter, 1)
                        : $unter . "\n2 FORM\n3 TYPE " . $art;
                }
            }

            return $m[1] . $unter;
        }, $media->gedcom(), 1);

        $media->updateRecord($gedcom, true);

        return $this->written($media);
    }

    /**
     * Medienobjekt aus einer Datei, die schon im Medienordner liegt (ab Stufe 18) - etwa ein Kirchenbuchscan aus dem
     * Archiv (Modul Sammlungen): ?xref=<Datensatz, nur fuer die Rechtepruefung>  Rumpf: { file, title?, type? }
     * Gibt es zu der Datei schon ein Medienobjekt, kommt dessen Kennung zurueck; sonst entsteht eines - ohne
     * Verknuepfung. Die Datei bleibt, wo sie ist; verknuepft wird erst, was die App danach ausdruecklich zuordnet.
     */
    public function postMediaFromFileAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        if (!Auth::canUploadMedia($tree, Auth::user())) {
            return $this->error(403, 'upload-not-allowed');
        }

        $body = $this->body($request);
        $file = trim(str_replace('\\', '/', $this->str($body, 'file')), '/');

        if ($file === '' || str_contains($file, '..') || str_contains($file, "\n")) {
            return $this->error(400, 'invalid-value');
        }

        if (!$tree->mediaFilesystem()->fileExists($file)) {
            return $this->error(404, 'file-not-found');
        }

        $vorhanden = DB::table('media_file')
            ->where('m_file', '=', $tree->id())
            ->where('multimedia_file_refn', '=', $file)
            ->value('m_id');

        if ($vorhanden !== null) {
            return response(['ok' => true, 'pending' => false, 'media' => $vorhanden, 'existing' => true]);
        }

        $title = Registry::elementFactory()->make('OBJE:FILE:TITL')->canonical($this->str($body, 'title'));
        $type  = in_array($this->str($body, 'type'), ['photo', 'document', 'certificate', 'book', 'newspaper', 'card', 'map', 'tombstone', 'audio', 'video', 'other'], true) ? $this->str($body, 'type') : 'document';
        $media = $tree->createMediaObject("0 @@ OBJE\n" . Registry::container()->get(MediaFileService::class)->createMediaFileGedcom($file, $type, $title, ''));
        // Wie beim Hochladen: sofort annehmen, damit Datei und Baum zusammenpassen
        Registry::container()->get(PendingChangesService::class)->acceptRecord($media);

        return response(['ok' => true, 'pending' => false, 'media' => $media->xref(), 'existing' => false]);
    }

    /**
     * Foto von einem Datensatz loesen: ?xref=I123   Rumpf: { media: "M45" }
     * Entfernt nur die Verknuepfung (1 OBJE @M45@); das Medienobjekt und die Datei bleiben, wie beim Loesen in webtrees.
     */
    public function postUnlinkMediaAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $record = Registry::gedcomRecordFactory()->make($this->xref($request), $tree);
        $denied = $this->denyEdit($record);

        if ($denied !== null) {
            return $denied;
        }

        $links = $this->mediaLinks($record, $this->str($this->body($request), 'media'));

        if ($links === []) {
            return $this->error(404, 'link-not-found');
        }

        foreach ($links as $link) {
            if (!$link->canEdit()) {
                return $this->error(403, 'fact-locked');
            }
        }

        // Ein Schreibvorgang statt einem je Verknuepfung - also auch nur eine ausstehende Aenderung.
        $lines = ['0 @' . $record->xref() . '@ ' . $record->tag()];

        foreach ($record->facts([], false, Auth::PRIV_HIDE, true) as $fact) {
            if (!in_array($fact, $links, true)) {
                $lines[] = $fact->gedcom();
            }
        }

        $record->updateRecord(implode("\n", $lines), true);

        return $this->written($record);
    }

    /**
     * Hauptfoto festlegen: ?xref=I123   Rumpf: { media: "M45" }
     * webtrees zeigt als Hauptfoto das erste verknuepfte Bild - die Verknuepfung rueckt deshalb vor alle anderen.
     */
    public function postPrimaryMediaAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $record = Registry::gedcomRecordFactory()->make($this->xref($request), $tree);
        $denied = $this->denyEdit($record);

        if ($denied !== null) {
            return $denied;
        }

        $links = $this->mediaLinks($record, $this->str($this->body($request), 'media'));

        if ($links === []) {
            return $this->error(404, 'link-not-found');
        }

        $first = $record->facts(['OBJE'], false, Auth::PRIV_HIDE, true)->first();

        if ($first === $links[0]) {
            return $this->written($record);
        }

        $lines = ['0 @' . $record->xref() . '@ ' . $record->tag()];

        foreach ($record->facts([], false, Auth::PRIV_HIDE, true) as $fact) {
            if ($fact === $first) {
                $lines[] = $links[0]->gedcom();
            }

            if ($fact !== $links[0]) {
                $lines[] = $fact->gedcom();
            }
        }

        $record->updateRecord(implode("\n", $lines), true);

        return $this->written($record);
    }

    /**
     * Die Verknuepfungen (1 OBJE @M45@) eines Datensatzes mit einem Medienobjekt, in der Reihenfolge des Datensatzes.
     *
     * @return list<Fact>
     */
    private function mediaLinks(GedcomRecord $record, string $media_xref): array
    {
        if ($media_xref === '') {
            return [];
        }

        return $record->facts(['OBJE'], false, Auth::PRIV_HIDE, true)
            ->filter(static fn (Fact $fact): bool => $fact->value() === '@' . $media_xref . '@')
            ->values()
            ->all();
    }

    /**
     * GEDCOM eines Ereignisses aus dem Rumpf ({tag, value?, date?, place?, note?} oder {gedcom}) - gebaut und geprueft.
     *
     * @param array<string,mixed> $body
     *
     * @return array{0:string,1:string|null} GEDCOM und Fehlercode (null = in Ordnung)
     */
    private function checkedFactGedcom(array $body, string $old): array
    {
        // Ein Wert der Form @X@ waere fuer GEDCOM ein Verweis auf einen Datensatz, kein Text.
        foreach (['value', 'place', 'note', 'type'] as $key) {
            if (GedcomText::looksLikePointer($this->str($body, $key))) {
                return ['', 'invalid-value'];
            }
        }

        if ($this->str($body, 'gedcom') !== '') {
            $gedcom = trim(str_replace("\r", '', $this->str($body, 'gedcom')));
        } else {
            $gedcom = $this->buildFactGedcom($body, $old);
        }

        return [$gedcom, GedcomText::factGedcomProblem($gedcom)];
    }

    /**
     * Neues Ereignis bauen oder ein bestehendes gezielt aendern.
     *
     * @param array<string,mixed> $body
     */
    private function buildFactGedcom(array $body, string $old): string
    {
        if ($old === '') {
            $tag    = strtoupper(GedcomText::line($this->str($body, 'tag')));
            $value  = GedcomText::multiline($this->str($body, 'value'), 2);
            $gedcom = '1 ' . $tag . ($value === '' ? '' : ' ' . $value);
        } else {
            [$gedcom] = explode("\n", $old, 2);
            $tag      = preg_match('/^1 (\S+)/', $gedcom, $match) === 1 ? $match[1] : '';
            $rest     = substr($old, strlen($gedcom));

            if (array_key_exists('value', $body)) {
                $value  = GedcomText::multiline($this->str($body, 'value'), 2);
                $gedcom = '1 ' . $tag . ($value === '' ? '' : ' ' . $value);
                // Fortsetzungszeilen des alten Werts entfernen
                $rest = (string) preg_replace('/^(\n2 CONT ?.*)+/', '', $rest);

                // GIVN, SURN und NSFX stehen im Namen selbst und werden unten neu gesetzt. Spitzname und Praefixe
                // (NICK, NPFX, SPFX) lassen sich nicht aus dem Namen ableiten - sie bleiben, wie alles andere darunter.
                if ($tag === 'NAME') {
                    $rest = (string) preg_replace('/\n2 (GIVN|SURN|NSFX) .*/', '', $rest);
                }
            }

            $gedcom .= $rest;
        }

        if ($tag === 'NAME' && array_key_exists('value', $body) && preg_match('#^([^/]*)/([^/]*)/(.*)$#s', $this->str($body, 'value'), $match) === 1) {
            $given = trim($match[1]);
            // Ein vorhandenes Praefix ("Dr.") steht vorn im Namen, gehoert aber nicht zu den Vornamen.
            if (preg_match('/\n2 NPFX (.+)/', $gedcom, $npfx) === 1 && str_starts_with($given, trim($npfx[1]) . ' ')) {
                $given = trim(substr($given, strlen(trim($npfx[1]))));
            }
            $insert = ($given === '' ? '' : "\n2 GIVN " . $given) . (trim($match[2]) === '' ? '' : "\n2 SURN " . trim($match[2]))
                . (trim($match[3]) === '' ? '' : "\n2 NSFX " . trim($match[3]));
            $gedcom = GedcomText::insertAfterFirstLine($gedcom, $insert);
        }

        if (array_key_exists('place', $body)) {
            $place   = GedcomText::line($this->str($body, 'place'));
            $current = preg_match('/\n2 PLAC (.*)/', $gedcom, $match) === 1 ? trim($match[1]) : '';

            // Unveraenderter Ort: Koordinaten (3 MAP ...) behalten.
            if ($place !== $current) {
                $gedcom = (string) preg_replace('/\n2 PLAC.*(\n[3-9] .*)*/', '', $gedcom);
                $gedcom = GedcomText::insertAfterFirstLine($gedcom, $place === '' ? '' : "\n2 PLAC " . $place);
            }
        }

        if (array_key_exists('date', $body)) {
            $date   = strtoupper(GedcomText::line($this->str($body, 'date')));
            $gedcom = (string) preg_replace('/\n2 DATE.*(\n[3-9] .*)*/', '', $gedcom);
            $gedcom = GedcomText::insertAfterFirstLine($gedcom, $date === '' ? '' : "\n2 DATE " . $date);
        }

        // Art des Ereignisses (ab Stufe 20), bei der Heirat in webtrees' Form: CIVIL, RELIGIOUS, PARTNERS, COMMON LAW
        if (array_key_exists('type', $body)) {
            $type = GedcomText::line($this->str($body, 'type'));

            if ($tag === 'MARR') {
                $type = match (strtolower($type)) {
                    'civil' => 'CIVIL',
                    'religious', 'reli' => 'RELIGIOUS',
                    'partners', 'partnership' => 'PARTNERS',
                    'common law', 'common' => 'COMMON LAW',
                    default => $type,
                };
            }

            $gedcom = (string) preg_replace('/\n2 TYPE.*(\n[3-9] .*)*/', '', $gedcom);
            $gedcom = GedcomText::insertAfterFirstLine($gedcom, $type === '' ? '' : "\n2 TYPE " . $type);
        }

        if (array_key_exists('note', $body)) {
            $note   = GedcomText::multiline($this->str($body, 'note'), 3);
            // Nur die erste eingebettete Notiz ersetzen; Verweise auf Notiz-Datensaetze bleiben. Eine Patenliste
            // ("Paten: …", "Trauzeugen: …") ist keine gewoehnliche Notiz - die pflegt die Route Association (ab Stufe 20).
            $erledigt = false;
            $gedcom   = (string) preg_replace_callback('/\n2 NOTE (?!@)([^\n]*)((?:\n3 CON[CT][^\n]*)*)/', function (array $m) use (&$erledigt): string {
                if ($erledigt || preg_match('/^(paten|taufpaten|gevattern|trauzeugen|zeugen):/iu', trim(GedcomText::mitFortsetzung($m[1], $m[2], 2))) === 1) {
                    return $m[0];
                }
                $erledigt = true;

                return '';
            }, $gedcom);
            $gedcom .= $note === '' ? '' : "\n2 NOTE " . $note;
        }

        // "1 BIRT" ohne alles waere leer - GEDCOM schreibt dafuer "1 BIRT Y".
        if (in_array($tag, GedcomText::EVENT_TAGS, true) && preg_match('/^1 ' . preg_quote($tag, '/') . '$/', $gedcom) === 1) {
            $gedcom .= ' Y';
        } elseif (in_array($tag, GedcomText::EVENT_TAGS, true)) {
            $gedcom = (string) preg_replace('/^(1 ' . preg_quote($tag, '/') . ') Y(?=\n)/', '$1', $gedcom);
        }

        return $gedcom;
    }

    private function denyEdit(GedcomRecord|null $record): ResponseInterface|null
    {
        if ($record === null) {
            return $this->error(404, 'not-found');
        }

        if (!$record->canShow()) {
            return $this->error(403, 'private');
        }

        if (!Auth::isEditor($record->tree()) || !$record->canEdit()) {
            return $this->error(403, 'not-editable');
        }

        return null;
    }

    /**
     * @param array<string,mixed> $extra
     */
    /**
     * Merkliste aendern (ab Stufe 11): Rumpf { xref, add: true|false }. Antwort wie Bookmarks (die ganze Liste).
     */
    public function postBookmarksAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::check()) {
            return $this->error(403, 'not-logged-in');
        }

        $tree = Validator::attributes($request)->tree();
        $body = $this->body($request);
        $xref = $this->str($body, 'xref');
        $add  = (bool) ($body['add'] ?? true);

        $individual = Registry::individualFactory()->make($xref, $tree);

        if (!$individual instanceof Individual || !$individual->canShow()) {
            return $this->error(404, 'not-found');
        }

        $xrefs = $this->bookmarkXrefs($tree);
        $xrefs = array_values(array_filter($xrefs, static fn (string $x): bool => $x !== $xref));

        if ($add) {
            $xrefs[] = $xref;
        }

        $tree->setUserPreference(Auth::user(), self::BOOKMARKS_PREF, implode(',', array_slice($xrefs, -500)));

        return $this->getBookmarksAction($request);
    }

    /**
     * Startperson festlegen (ab Stufe 24): Rumpf { xref, forTree? }. Ohne forTree die eigene Standardperson des
     * Benutzers (wie unter "Mein Konto"; leere xref entfernt sie), mit forTree die des Stammbaums - nur Verwalter.
     */
    public function postStartPersonAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::check()) {
            return $this->error(403, 'not-logged-in');
        }

        $tree    = Validator::attributes($request)->tree();
        $body    = $this->body($request);
        $xref    = $this->str($body, 'xref');
        $forTree = (bool) ($body['forTree'] ?? false);

        if ($forTree && !Auth::isManager($tree)) {
            return $this->error(403, 'not-manager');
        }

        if ($xref !== '') {
            $individual = Registry::individualFactory()->make($xref, $tree);

            if (!$individual instanceof Individual || !$individual->canShow()) {
                return $this->error(404, 'not-found');
            }
        } elseif ($forTree) {
            return $this->error(400, 'missing-xref');
        }

        if ($forTree) {
            $tree->setPreference('PEDIGREE_ROOT_ID', $xref);
        } else {
            $tree->setUserPreference(Auth::user(), UserInterface::PREF_TREE_DEFAULT_XREF, $xref);
        }

        $person = $tree->significantIndividual(Auth::user());

        return response([
            'ok'              => true,
            'startXref'       => $person->canShow() && Registry::individualFactory()->make($person->xref(), $tree) !== null ? $person->xref() : '',
            'defaultXref'     => $tree->getUserPreference(Auth::user(), UserInterface::PREF_TREE_DEFAULT_XREF),
            'treeDefaultXref' => $tree->getPreference('PEDIGREE_ROOT_ID'),
        ]);
    }

    /**
     * Eigenes Konto aendern (ab Stufe 25): Rumpf { realName } - der angezeigte Name (hoechstens 64 Zeichen), wie unter
     * "Mein Konto" im Browser. Benutzername, E-Mail und Passwort bleiben dem Browser vorbehalten.
     */
    public function postMyAccountAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::check()) {
            return $this->error(403, 'not-logged-in');
        }

        // Zeilenumbrueche und andere Steuerzeichen werden zu Leerzeichen (das Browserfeld laesst sie auch nicht zu).
        $real_name = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $this->str($this->body($request), 'realName')));

        if ($real_name === '') {
            return $this->error(400, 'missing-real-name');
        }

        // Die Spalte real_name hat 64 Zeichen; laenger gaebe unter MySQL einen Serverfehler.
        if (mb_strlen($real_name) > 64) {
            return $this->error(400, 'real-name-too-long');
        }

        Auth::user()->setRealName($real_name);

        return response(['ok' => true, 'realName' => Auth::user()->realName()]);
    }

    private function written(GedcomRecord $record, array $extra = [], int $status = 200): ResponseInterface
    {
        $pending = DB::table('change')
            ->where('gedcom_id', '=', $record->tree()->id())
            ->where('xref', '=', $record->xref())
            ->where('status', '=', 'pending')
            ->exists();

        return response(['ok' => true, 'xref' => $record->xref(), 'pending' => $pending] + $extra)->withStatus($status);
    }
}
