<?php

declare(strict_types=1);

namespace WebtreesAnd\Api;

use Fisharebest\ExtCalendar\GregorianCalendar;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Http\Exceptions\HttpServiceUnavailableException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Place;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\CalendarService;
use Fisharebest\Webtrees\Services\LinkedRecordService;
use Fisharebest\Webtrees\Services\PendingChangesService;
use Fisharebest\Webtrees\Services\SearchService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\Webtrees;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use InvalidArgumentException;

use function array_map;
use function explode;
use function implode;
use function in_array;
use function max;
use function mb_stripos;
use function min;
use function preg_match;
use function preg_split;
use function response;
use function str_replace;
use function trim;
use function usort;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Lesende JSON-Endpunkte (GET). Gelesen wird ausschliesslich ueber die webtrees-Objekte (canShow(), facts(),
 * children() ...), damit dieselben Datenschutzregeln gelten wie auf den HTML-Seiten.
 */
trait ReadActions
{
    /**
     * Einstieg fuer die App: Versionen, angemeldeter Benutzer, sichtbare Baeume.
     * Liefert auch das CSRF-Token - die App braucht es fuer den POST auf /login.
     */
    public function getInfoAction(ServerRequestInterface $request): ResponseInterface
    {
        $user  = Auth::user();
        $trees = [];

        foreach (Registry::container()->get(TreeService::class)->all() as $tree) {
            if (!$this->treeEnabled($tree)) {
                continue;
            }

            $trees[] = [
                'name'        => $tree->name(),
                'title'       => $tree->title(),
                'individuals' => DB::table('individuals')->where('i_file', '=', $tree->id())->count(),
                'role'        => $this->role($tree, $user),
                'canEdit'     => Auth::isEditor($tree, $user),
                'canUpload'   => Auth::canUploadMedia($tree, $user),
                'canModerate' => Auth::isModerator($tree, $user),
                // Anzahl der Datensaetze mit ausstehenden Aenderungen - nur fuer die, die sie freigeben duerfen
                'pending'     => Auth::isModerator($tree, $user) ? Registry::container()->get(PendingChangesService::class)->pendingXrefs($tree)->count() : 0,
                'autoAccept'  => $user->getPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS) === '1',
                'userXref'    => $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF),
                'defaultXref' => $tree->getUserPreference($user, UserInterface::PREF_TREE_DEFAULT_XREF),
                // Nummer der letzten Aenderung im Baum (auch ausstehende, angenommene, verworfene). Ein anderer Wert
                // als beim letzten Mal heisst: neu laden. Nur auf Gleichheit vergleichen - ein neuer GEDCOM-Import
                // loescht die Aenderungsliste, dann wird die Zahl kleiner.
                'lastChange'  => (int) DB::table('change')->where('gedcom_id', '=', $tree->id())->max('change_id'),
            ];
        }

        return response([
            'api'         => self::API_VERSION,
            'module'      => $this->customModuleVersion(),
            'webtrees'    => Webtrees::VERSION,
            'baseUrl'     => Validator::attributes($request)->string('base_url'),
            'rewriteUrls' => Validator::attributes($request)->boolean('rewrite_urls', false),
            'csrf'        => Session::getCsrfToken(),
            // Groesste Datei, die dieser Server beim Hochladen annimmt (PHP: upload_max_filesize / post_max_size).
            // Clients verkleinern Fotos so weit, dass sie hineinpassen.
            'maxUpload'   => $this->maxUploadBytes(),
            'user'        => [
                'loggedIn' => Auth::check(),
                'userName' => $user->userName(),
                'realName' => $user->realName(),
                'isAdmin'  => Auth::isAdmin($user),
            ],
            'trees'       => $trees,
        ]);
    }

    /**
     * Personenliste, optional gefiltert: ?q=<Suchworte>&page=<n>
     * &scope=all: die Suchworte muessen nicht im Namen stehen, sondern irgendwo in den sichtbaren Angaben der Person
     * (Ort, Jahr, Beruf ...) - "Huber Wien" findet die Hubers mit Wien in Geburt, Wohnort usw.
     */
    public function getIndividualsAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree  = Validator::attributes($request)->tree();
        $query = trim(Validator::queryParams($request)->string('q', ''));
        $page  = max(1, Validator::queryParams($request)->integer('page', 1));

        $words  = preg_split('/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $offset = ($page - 1) * self::PAGE_SIZE;

        // Eine Zeile mehr holen, um zu wissen, ob es eine weitere Seite gibt.
        if ($words === []) {
            // Reine Liste: nur der Hauptname (n_num = 0). Sonst stuenden Frauen zusaetzlich
            // unter ihrem Ehenamen (_MARNM) und waeren nach diesem einsortiert.
            $rows = DB::table('individuals')
                ->join('name', static function (JoinClause $join): void {
                    $join
                        ->on('name.n_file', '=', 'individuals.i_file')
                        ->on('name.n_id', '=', 'individuals.i_id');
                })
                ->where('i_file', '=', $tree->id())
                ->where('n_num', '=', 0)
                ->orderBy('n_sort')
                ->orderBy('i_id')
                ->offset($offset)
                ->limit(self::PAGE_SIZE + 1)
                ->select(['individuals.*'])
                ->get()
                ->map(Registry::individualFactory()->mapper($tree));
        } elseif (Validator::queryParams($request)->string('scope', '') === 'all') {
            $rows = $this->searchAllFacts($tree, $words);

            if ($rows === null) {
                return $this->error(400, 'too-many-results');
            }

            $rows = $rows->slice($offset, self::PAGE_SIZE + 1);
        } else {
            // Suche: auch Ehe- und Zweitnamen sollen treffen.
            $rows = Registry::container()->get(SearchService::class)
                ->searchIndividualNames([$tree], $words, $offset, self::PAGE_SIZE + 1);
        }

        $has_more = $rows->count() > self::PAGE_SIZE;

        // Personen mit mehreren Namen tauchen mehrfach auf - je Seite nur einmal ausgeben.
        $seen = [];
        $data = [];

        foreach ($rows->slice(0, self::PAGE_SIZE) as $individual) {
            if (!isset($seen[$individual->xref()]) && $individual->canShowName()) {
                $seen[$individual->xref()] = true;
                $data[]                    = $this->personSummary($individual);
            }
        }

        return response([
            'query'    => $query,
            'page'     => $page,
            'nextPage' => $has_more ? $page + 1 : null,
            'data'     => $data,
        ]);
    }

    /**
     * Personen, bei denen jedes Suchwort in einer sichtbaren Angabe vorkommt, nach Namen sortiert.
     * Die allgemeine Suche von webtrees vergleicht mit dem rohen GEDCOM - auch mit Angaben, die der Benutzer nicht sehen
     * darf. Deshalb wird hier gegen die sichtbaren Ereignisse nachgeprueft. null: zu viele Treffer fuer webtrees.
     *
     * @param array<string> $words
     *
     * @return Collection<int,Individual>|null
     */
    private function searchAllFacts(Tree $tree, array $words): Collection|null
    {
        try {
            $found = Registry::container()->get(SearchService::class)->searchIndividuals([$tree], $words);
        } catch (HttpServiceUnavailableException) {
            return null;
        }

        $words = array_map(I18N::language()->normalize(...), $words);

        return $found
            ->filter(static function (Individual $individual) use ($words): bool {
                if (!$individual->canShowName()) {
                    return false;
                }

                $text = I18N::language()->normalize(implode("\n", $individual->facts()->map(static fn (Fact $fact): string => $fact->gedcom())->all()));

                foreach ($words as $word) {
                    if (mb_stripos($text, $word) === false) {
                        return false;
                    }
                }

                return true;
            })
            ->sort(static fn (Individual $a, Individual $b): int => [$a->sortName(), $a->xref()] <=> [$b->sortName(), $b->xref()])
            ->values();
    }

    /**
     * Eine Person mit Ereignissen, Familien und Medien: ?xref=I123
     */
    public function getIndividualAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree       = Validator::attributes($request)->tree();
        $individual = Registry::individualFactory()->make($this->xref($request), $tree);

        if ($individual === null) {
            return $this->error(404, 'not-found');
        }

        if (!$individual->canShow()) {
            return $this->error(403, 'private');
        }

        $parents = [];
        foreach ($individual->childFamilies() as $family) {
            $parents[] = $this->familyJson($family, null);
        }

        $spouses = [];
        foreach ($individual->spouseFamilies() as $family) {
            $spouses[] = $this->familyJson($family, $individual);
        }

        return response([
            'person'         => $this->personSummary($individual),
            'relationship'   => $this->relationship($request, $individual),
            'canEdit'        => $individual->canEdit(),
            'facts'          => $this->factsJson($individual),
            'parentFamilies' => $parents,
            'spouseFamilies' => $spouses,
            'media'          => $this->mediaJson($individual),
        ]);
    }

    /**
     * Alle Medienobjekte des Baums, neueste zuerst: ?page=<n>
     * Je Eintrag die verknuepften Personen (hoechstens drei Namen) - fuer die Fotouebersicht der App.
     */
    public function getMediaListAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $page   = max(1, Validator::queryParams($request)->integer('page', 1));
        $offset = ($page - 1) * self::MEDIA_PAGE_SIZE;

        // Eine Zeile mehr holen, um zu wissen, ob es eine weitere Seite gibt.
        $rows = DB::table('media')
            ->where('m_file', '=', $tree->id())
            ->orderByDesc('m_id')
            ->offset($offset)
            ->limit(self::MEDIA_PAGE_SIZE + 1)
            ->get()
            ->map(Registry::mediaFactory()->mapper($tree));

        $linked = Registry::container()->get(LinkedRecordService::class);
        $data   = [];

        foreach ($rows->slice(0, self::MEDIA_PAGE_SIZE) as $media) {
            if (!$media instanceof Media || !$media->canShow()) {
                continue;
            }

            $people = [];
            foreach ($linked->linkedIndividuals($media)->take(3) as $individual) {
                if ($individual->canShowName()) {
                    $people[] = ['xref' => $individual->xref(), 'name' => $this->plain($individual->fullName())];
                }
            }

            foreach ($this->mediaFilesJson($media) as $file) {
                $data[] = $file + ['people' => $people];
            }
        }

        return response([
            'page'     => $page,
            'nextPage' => $rows->count() > self::MEDIA_PAGE_SIZE ? $page + 1 : null,
            'data'     => $data,
        ]);
    }

    /**
     * Jahrestage der naechsten Tage: ?days=<1..60> (Standard 14) - Geburts-, Heirats- und Todestage.
     * Nutzt den Kalenderdienst von webtrees; es erscheint nur, was der Benutzer sehen darf.
     */
    public function getAnniversariesAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree  = Validator::attributes($request)->tree();
        $days  = min(60, max(1, Validator::queryParams($request)->integer('days', 14)));
        // CarbonImmutable (what timestampFactory()->now() actually returns) has
        // no real julianDay() method - it resolves to a Carbon macro that
        // throws "Method julianDay does not exist." at runtime. Convert via the
        // Gregorian-calendar library webtrees itself ships instead, which
        // works on every supported webtrees version.
        $now   = Registry::timestampFactory()->now();
        $today = (new GregorianCalendar())->ymdToJd((int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'));

        $facts = Registry::container()->get(CalendarService::class)
            ->getEventsList($today, $today + $days - 1, 'BIRT MARR DEAT', false, 'anniv', $tree);

        $data = [];

        foreach ($facts as $fact) {
            $record = $fact->record();

            if (!$record->canShow() || !$fact->canShow() || $fact->anniv <= 0) {
                continue;
            }

            $person = $record instanceof Individual ? $record : null;
            $couple = [];

            if ($record instanceof Family) {
                foreach ($record->spouses() as $spouse) {
                    $couple[] = $this->personSummary($spouse);
                }
            }

            $data[] = [
                'inDays'  => $fact->jd - $today,
                'tag'     => $this->shortTag($fact->tag()),
                'label'   => $this->factLabel($fact),
                'years'   => $fact->anniv,
                'date'    => $this->dateJson($fact->date()),
                'xref'    => $record->xref(),
                'name'    => $this->plain($record->fullName()),
                'person'  => $person instanceof Individual ? $this->personSummary($person) : null,
                'couple'  => $couple,
            ];
        }

        usort($data, static fn (array $a, array $b): int => [$a['inDays'], $a['name']] <=> [$b['inDays'], $b['name']]);

        return response(['days' => $days, 'data' => $data]);
    }

    /**
     * Eine Familie: ?xref=F123
     */
    public function getFamilyAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $family = Registry::familyFactory()->make($this->xref($request), $tree);

        if ($family === null) {
            return $this->error(404, 'not-found');
        }

        if (!$family->canShow()) {
            return $this->error(403, 'private');
        }

        return response($this->familyJson($family, null) + ['media' => $this->mediaJson($family)]);
    }

    /**
     * Ahnentafel: ?xref=I123&generations=4  (Kekule-Nummern: 1 = Proband, 2 = Vater, 3 = Mutter ...)
     */
    public function getPedigreeAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree        = Validator::attributes($request)->tree();
        $generations = min(self::MAX_PEDIGREE_GEN, max(1, Validator::queryParams($request)->integer('generations', 4)));
        $root        = Registry::individualFactory()->make($this->xref($request), $tree);

        if ($root === null) {
            return $this->error(404, 'not-found');
        }

        if (!$root->canShow()) {
            return $this->error(403, 'private');
        }

        /** @var array<int,Individual> $ancestors */
        $ancestors = [1 => $root];
        $last      = 2 ** $generations - 1;

        for ($n = 1; $n * 2 <= $last; $n++) {
            if (!isset($ancestors[$n])) {
                continue;
            }

            $family = $ancestors[$n]->childFamilies()->first();

            if ($family instanceof Family) {
                if ($family->husband() instanceof Individual) {
                    $ancestors[$n * 2] = $family->husband();
                }
                if ($family->wife() instanceof Individual) {
                    $ancestors[$n * 2 + 1] = $family->wife();
                }
            }
        }

        // hasParents: damit die App an der obersten Reihe ein "weiter nach oben"-Symbol zeigen kann.
        $data = [];
        foreach ($ancestors as $n => $individual) {
            $family = $individual->canShow() ? $individual->childFamilies()->first() : null;
            $data[] = [
                'n'          => $n,
                'person'     => $this->personSummary($individual),
                'hasParents' => $family instanceof Family && ($family->husband() instanceof Individual || $family->wife() instanceof Individual),
            ];
        }

        return response([
            'root'        => $root->xref(),
            'generations' => $generations,
            'ancestors'   => $data,
        ]);
    }

    /**
     * Nachkommen als Baum: ?xref=I123&generations=3
     */
    public function getDescendantsAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree        = Validator::attributes($request)->tree();
        $generations = min(self::MAX_DESCENDANTS_GEN, max(1, Validator::queryParams($request)->integer('generations', 3)));
        $root        = Registry::individualFactory()->make($this->xref($request), $tree);

        if ($root === null) {
            return $this->error(404, 'not-found');
        }

        if (!$root->canShow()) {
            return $this->error(403, 'private');
        }

        return response([
            'root'        => $root->xref(),
            'generations' => $generations,
            'tree'        => $this->descendantsJson($root, $generations),
        ]);
    }

    /**
     * Datensaetze mit ausstehenden Aenderungen - nur fuer Moderatoren und Verwalter.
     */
    public function getPendingAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        if (!Auth::isModerator($tree)) {
            return $this->error(403, 'not-moderator');
        }

        $rows = DB::table('change')
            ->join('user', 'user.user_id', '=', 'change.user_id')
            ->where('gedcom_id', '=', $tree->id())
            ->where('status', '=', 'pending')
            ->orderBy('change_id')
            ->select(['xref', 'real_name', 'change_time', 'old_gedcom', 'new_gedcom'])
            ->get()
            ->groupBy('xref');

        $data = [];

        foreach ($rows as $xref => $changes) {
            try {
                $record = Registry::gedcomRecordFactory()->make((string) $xref, $tree);
            } catch (InvalidArgumentException) {
                // Angelegt und gleich wieder geloescht, beides noch ausstehend: webtrees kann daraus kein Objekt
                // bauen ("Invalid GEDCOM record"). Der Moderator soll den Eintrag trotzdem sehen und wegraeumen koennen.
                $record = null;
            }

            $gedcom = (string) ($changes->last()->old_gedcom ?: $changes->first()->new_gedcom);

            $data[] = [
                'xref'    => (string) $xref,
                'type'    => $record?->tag() ?? (preg_match('/^0 @[^@]+@ (\w+)/', $gedcom, $match) === 1 ? $match[1] : ''),
                'name'    => $record !== null ? $this->plain($record->fullName()) : $this->nameFromGedcom($gedcom),
                // neu: vor der ersten Aenderung gab es den Datensatz nicht; geloescht: nach der letzten gibt es ihn nicht mehr
                'kind'    => $changes->first()->old_gedcom === '' ? 'new' : ($changes->last()->new_gedcom === '' ? 'deleted' : 'changed'),
                'changes' => $changes->count(),
                'users'   => $changes->pluck('real_name')->unique()->values()->all(),
                'time'    => (string) $changes->last()->change_time,
            ];
        }

        return response(['data' => $data]);
    }

    //
    // Alle POST-Aktionen laufen durch die CSRF-Pruefung von webtrees: die App schickt
    // das Token aus "Info" im Header X-CSRF-TOKEN. Der Rumpf ist JSON (oder ein Formular).
    // Geschrieben wird nur ueber createFact/updateFact/createIndividual ... - damit gelten
    // Bearbeiterrechte, RESN-Sperren, Aenderungsprotokoll und die Moderation ("ausstehende
    // Aenderungen") genau wie in der Weboberflaeche.

    /**
     * Notname aus dem Rohtext, wenn webtrees kein Objekt liefern kann: die erste NAME-Zeile ohne die Schraegstriche.
     */
    private function nameFromGedcom(string $gedcom): string
    {
        return preg_match('/\n1 NAME (.+)/', $gedcom, $match) === 1 ? trim(str_replace('/', '', $match[1])) : '';
    }

    /**
     * Beschriftete Liste der Ereignisse, die die App zum Hinzufuegen anbietet: ?type=INDI|FAM
     */
    public function getTagsAction(ServerRequestInterface $request): ResponseInterface
    {
        Validator::attributes($request)->tree();
        $type = Validator::queryParams($request)->isInArray(['INDI', 'FAM'])->string('type', 'INDI');
        $data = [];

        foreach (self::ADDABLE_TAGS[$type] as $tag) {
            $data[] = [
                'tag'     => $tag,
                'label'   => $this->plain(Registry::elementFactory()->make($type . ':' . $tag)->label()),
                'isEvent' => in_array($tag, GedcomText::EVENT_TAGS, true),
            ];
        }

        return response(['type' => $type, 'data' => $data]);
    }

    /**
     * Ortsvorschlaege beim Tippen: ?q=<Anfang oder Teil des Ortsnamens>
     * Wie die Autovervollstaendigung von webtrees selbst: nur fuer Bearbeiter, Suche ueber die Ortstabelle des Baums.
     * "Berlin, Deu" sucht je Ebene: "Berlin" im Ort, "Deu" in der Ebene darueber.
     */
    public function getPlacesAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();

        if (!Auth::isEditor($tree)) {
            return $this->error(403, 'not-editor');
        }

        $query = trim(Validator::queryParams($request)->string('q', ''));
        // Leerzeichen nach dem Komma gehoeren nicht zum Suchwort der naechsten Ebene.
        $search = implode(',', array_map(trim(...), explode(',', $query)));

        $data = Registry::container()->get(SearchService::class)
            ->searchPlaces($tree, $search, 0, self::PLACES_LIMIT)
            ->map(static fn (Place $place): string => $place->gedcomName())
            ->values()
            ->all();

        return response(['query' => $query, 'data' => $data]);
    }

    private function role(Tree $tree, UserInterface $user): string
    {
        return match (true) {
            Auth::isManager($tree, $user)   => 'manager',
            Auth::isModerator($tree, $user) => 'moderator',
            Auth::isEditor($tree, $user)    => 'editor',
            Auth::isMember($tree, $user)    => 'member',
            default                         => 'visitor',
        };
    }
}
