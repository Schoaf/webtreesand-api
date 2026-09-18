<?php

declare(strict_types=1);

namespace WebtreesAnd\Api;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\MediaFileService;
use Fisharebest\Webtrees\Services\PendingChangesService;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function array_key_exists;
use function class_exists;
use function explode;
use function in_array;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function response;
use function str_replace;
use function strlen;
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

        // Ein Wert der Form @X@ waere fuer GEDCOM ein Verweis auf einen Datensatz, kein Text.
        foreach (['value', 'place', 'note'] as $key) {
            if (GedcomText::looksLikePointer($this->str($body, $key))) {
                return $this->error(400, 'invalid-value');
            }
        }

        if ($this->str($body, 'gedcom') !== '') {
            $gedcom = trim(str_replace("\r", '', $this->str($body, 'gedcom')));
        } else {
            $gedcom = $this->buildFactGedcom($body, $old?->gedcom() ?? '');
        }

        $problem = GedcomText::factGedcomProblem($gedcom);

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
     *          marriageDate?, marriagePlace? }
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
        $family = null;

        if ($relation === 'child') {
            $family_xref = $this->str($body, 'family');
            $families    = $relative->spouseFamilies();

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

        $gedcom = "0 @@ INDI\n1 NAME " . trim($given . ' /' . $surname . '/');
        $gedcom .= $given === '' ? '' : "\n2 GIVN " . $given;
        $gedcom .= $surname === '' ? '' : "\n2 SURN " . $surname;
        $gedcom .= "\n1 SEX " . $sex;
        $gedcom .= GedcomText::eventGedcom('BIRT', $this->str($body, 'birthDate'), $this->str($body, 'birthPlace'), false);
        $gedcom .= GedcomText::eventGedcom('DEAT', $this->str($body, 'deathDate'), $this->str($body, 'deathPlace'), ($body['dead'] ?? false) === true);

        $new = $tree->createIndividual($gedcom);

        $marriage = GedcomText::eventGedcom('MARR', $this->str($body, 'marriageDate'), $this->str($body, 'marriagePlace'), false);

        switch ($relation) {
            case 'child':
                if ($family instanceof Family) {
                    $family->createFact('1 CHIL @' . $new->xref() . '@', true);
                } else {
                    $link   = $relative->sex() === 'F' ? 'WIFE' : 'HUSB';
                    $family = $tree->createFamily("0 @@ FAM\n1 " . $link . ' @' . $relative->xref() . "@\n1 CHIL @" . $new->xref() . '@');
                    $relative->createFact('1 FAMS @' . $family->xref() . '@', true);
                }
                $new->createFact('1 FAMC @' . $family->xref() . '@', false);
                break;

            case 'spouse':
                $relative_link = $relative->sex() === 'F' ? 'WIFE' : 'HUSB';
                $new_link      = $relative_link === 'HUSB' ? 'WIFE' : 'HUSB';
                $family        = $tree->createFamily("0 @@ FAM\n1 " . $relative_link . ' @' . $relative->xref() . "@\n1 " . $new_link . ' @' . $new->xref() . '@' . $marriage);
                $relative->createFact('1 FAMS @' . $family->xref() . '@', true);
                $new->createFact('1 FAMS @' . $family->xref() . '@', false);
                break;

            case 'father':
            case 'mother':
                $link = $relation === 'father' ? 'HUSB' : 'WIFE';
                if ($family instanceof Family) {
                    $family->createFact('1 ' . $link . ' @' . $new->xref() . '@', true);
                } else {
                    $family = $tree->createFamily("0 @@ FAM\n1 " . $link . ' @' . $new->xref() . "@\n1 CHIL @" . $relative->xref() . '@');
                    $relative->createFact('1 FAMC @' . $family->xref() . '@', true);
                }
                $new->createFact('1 FAMS @' . $family->xref() . '@', false);
                break;
        }

        return $this->written($new, ['family' => $family instanceof GedcomRecord ? $family->xref() : null], 201);
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

        if (!Auth::canUploadMedia($tree, Auth::user())) {
            return $this->error(403, 'upload-not-allowed');
        }

        $record = Registry::gedcomRecordFactory()->make($this->xref($request), $tree);
        $denied = $this->denyEdit($record);

        if ($denied !== null) {
            return $denied;
        }

        $body  = $this->body($request);
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
        } catch (Throwable) {
            $file = '';
        }

        if ($file === '') {
            return $this->error(400, 'upload-failed');
        }

        $gedcom = "0 @@ OBJE\n" . Registry::container()->get(MediaFileService::class)->createMediaFileGedcom($file, 'photo', $title, $note);
        $media  = $tree->createMediaObject($gedcom);

        // Wie webtrees selbst: das Medienobjekt sofort annehmen, damit Dateisystem und Baum zusammenpassen.
        // Die Verknuepfung zur Person bleibt eine normale (ggf. ausstehende) Aenderung.
        Registry::container()->get(PendingChangesService::class)->acceptRecord($media);

        $record->createFact('1 OBJE @' . $media->xref() . '@', true);

        return $this->written($record, ['media' => $media->xref()], 201);
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

                if ($tag === 'NAME') {
                    $rest = (string) preg_replace('/\n2 (GIVN|SURN|NPFX|NSFX|SPFX|NICK) .*/', '', $rest);
                }
            }

            $gedcom .= $rest;
        }

        if ($tag === 'NAME' && array_key_exists('value', $body) && preg_match('#^([^/]*)/([^/]*)/#', $this->str($body, 'value'), $match) === 1) {
            $insert = (trim($match[1]) === '' ? '' : "\n2 GIVN " . trim($match[1])) . (trim($match[2]) === '' ? '' : "\n2 SURN " . trim($match[2]));
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

        if (array_key_exists('note', $body)) {
            $note   = GedcomText::multiline($this->str($body, 'note'), 3);
            // Nur die erste eingebettete Notiz ersetzen; Verweise auf Notiz-Datensaetze bleiben.
            $gedcom = (string) preg_replace('/\n2 NOTE (?!@)[^\n]*(\n3 CONT[^\n]*)*/', '', $gedcom, 1);
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
