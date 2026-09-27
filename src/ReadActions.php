<?php

declare(strict_types=1);

namespace Api4Webtrees;

use Fisharebest\Algorithm\Dijkstra;
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
use Fisharebest\Webtrees\Module\ModuleChartInterface;
use Fisharebest\Webtrees\Module\RelationshipsChartModule;
use Fisharebest\Webtrees\Place;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\CalendarService;
use Fisharebest\Webtrees\Services\LinkedRecordService;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Services\PendingChangesService;
use Fisharebest\Webtrees\Services\RelationshipService;
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

use function array_filter;
use function array_flip;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function max;
use function mb_stripos;
use function min;
use function preg_match;
use function preg_quote;
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

        // Eine angemeldete App fragt beim Start hier nach: dann braucht dieser Benutzer den Hinweis auf die App nicht mehr.
        $hint = self::hintKey($request->getHeaderLine('User-Agent'));
        if (Auth::check() && $user->getPreference($hint) === '') {
            $user->setPreference($hint, 'connected');
        }

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
            $parents[] = $this->familyJson($family, null, true);
        }

        $spouses = [];
        foreach ($individual->spouseFamilies() as $family) {
            $spouses[] = $this->familyJson($family, $individual, true);
        }

        // Familien der Eltern mit anderen Partnern (ab Stufe 12): ihre Kinder sind die Halbgeschwister. Wie der Reiter
        // "Familien" in webtrees; "parent" ist der gemeinsame Elternteil, "spouse" dessen anderer Partner.
        $own_parents = $individual->childFamilies()->flatMap(static fn (Family $family) => $family->spouses())
            ->map(static fn (Individual $parent): string => $parent->xref());
        $step = [];
        foreach ($individual->childStepFamilies() as $family) {
            $parent = $family->spouses()->first(static fn (Individual $spouse): bool => $own_parents->contains($spouse->xref()));
            $step[] = ['parent' => $parent?->xref()] + $this->familyJson($family, $parent, true);
        }

        return response([
            'person'         => $this->personSummary($individual, true),
            'relationship'   => $this->relationship($request, $individual),
            'canEdit'        => $individual->canEdit(),
            'facts'          => $this->factsJson($individual),
            'parentFamilies' => $parents,
            'spouseFamilies' => $spouses,
            'stepFamilies'   => $step,
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
    /**
     * Merkliste (ab Stufe 11): Personen, die sich der angemeldete Benutzer in diesem Baum gemerkt hat. Liegt als
     * Benutzereinstellung je Baum in webtrees (kein Modul, kein GEDCOM, keine Freigabe) und gilt fuer alle Clients.
     */
    public function getBookmarksAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!Auth::check()) {
            return $this->error(403, 'not-logged-in');
        }

        $tree = Validator::attributes($request)->tree();
        $data = [];

        foreach ($this->bookmarkXrefs($tree) as $xref) {
            $individual = Registry::individualFactory()->make($xref, $tree);

            if ($individual instanceof Individual && $individual->canShow()) {
                $data[] = $this->personSummary($individual);
            }
        }

        return response(['data' => $data]);
    }

    /** @return list<string> */
    private function bookmarkXrefs(Tree $tree): array
    {
        $raw = $tree->getUserPreference(Auth::user(), self::BOOKMARKS_PREF);

        return $raw === '' ? [] : array_values(array_filter(explode(',', $raw)));
    }

    public function getAnniversariesAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree  = Validator::attributes($request)->tree();
        $days  = min(60, max(1, Validator::queryParams($request)->integer('days', 14)));
        // In webtrees 2.2 liefert timestampFactory()->now() ein Timestamp, das julianDay() kennt;
        // ab 2.3 kommt direkt ein CarbonImmutable, und dort wirft der Aufruf ("Method julianDay does
        // not exist."). Der Umweg ueber die Gregorianische Kalenderklasse, die webtrees ohnehin
        // mitbringt und im Kern selbst benutzt, laeuft auf beiden Fassungen.
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
     * &siblings=1 (ab Stufe 15): je Vorfahr seine Geschwister aus derselben Elternfamilie, als Kurzfassung.
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

        $with_siblings = Validator::queryParams($request)->boolean('siblings', false);
        // Bei Ahnenschwund steht dieselbe Person mehrfach in der Tafel - ihre Geschwister nur einmal zusammenstellen.
        $siblings = [];

        // hasParents: damit die App an der obersten Reihe ein "weiter nach oben"-Symbol zeigen kann.
        $data = [];
        foreach ($ancestors as $n => $individual) {
            $family = $individual->canShow() ? $individual->childFamilies()->first() : null;
            $entry  = [
                'n'          => $n,
                'person'     => $this->personSummary($individual),
                'hasParents' => $family instanceof Family && ($family->husband() instanceof Individual || $family->wife() instanceof Individual),
            ];

            if ($with_siblings) {
                // Dieselbe Familie wie fuer die Eltern oben - Halbgeschwister gehoeren nicht dazu.
                $entry['siblings'] = $siblings[$individual->xref()] ??= $family instanceof Family
                    ? $family->children()
                        ->filter(static fn (Individual $child): bool => $child->xref() !== $individual->xref())
                        ->map(fn (Individual $child): array => $this->personSummary($child))
                        ->values()
                        ->all()
                    : [];
            }

            $data[] = $entry;
        }

        return response([
            'root'        => $root->xref(),
            'generations' => $generations,
            'ancestors'   => $data,
        ]);
    }

    /**
     * Verwandtschaft zweier Personen (ab Stufe 13): ?xref1=I1&xref2=I2[&ancestors=1]
     *
     * Wie das Diagramm "Verwandtschaft" von webtrees: kuerzeste Wege ueber die Familien (Dijkstra ueber FAMS/FAMC),
     * hoechstens MAX_RELATIONSHIP_PATHS davon. Bei Ahnenschwund gibt es mehrere gleich kurze Wege. Je Weg die Schritte
     * von xref1 nach xref2 - relation: was die Person des Schritts fuer die vorige ist -, die Bezeichnung ("Cousine"),
     * wie webtrees sie fuer xref2 aus Sicht von xref1 bildet, und die gemeinsamen Vorfahren am Scheitel des Wegs.
     *
     * Datenschutz wie im Diagramm: das Diagramm muss fuer den Benutzer freigegeben sein, beide Personen sichtbar (oder
     * die Baumeinstellung "private Verwandtschaften zeigen" an). Personen unterwegs erscheinen, wie im Diagramm, als
     * Kurzfassung - fuer nicht sichtbare also "Privat" ohne Daten. "Nur ueber Vorfahren" des Baums gilt auch hier.
     */
    public function getRelationshipAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $params = Validator::queryParams($request);
        $first  = Registry::individualFactory()->make($params->isXref()->string('xref1'), $tree);
        $second = Registry::individualFactory()->make($params->isXref()->string('xref2'), $tree);

        $chart = Registry::container()->get(ModuleService::class)
            ->findByComponent(ModuleChartInterface::class, $tree, Auth::user())
            ->first(static fn ($module): bool => $module instanceof RelationshipsChartModule);

        if ($chart === null) {
            return $this->error(403, 'chart-disabled');
        }

        if ($first === null || $second === null) {
            return $this->error(404, 'not-found');
        }

        $show_private = $tree->getPreference('SHOW_PRIVATE_RELATIONSHIPS') === '1';

        if (!$show_private && (!$first->canShow() || !$second->canShow())) {
            return $this->error(403, 'private');
        }

        $ancestors = $tree->getPreference('RELATIONSHIP_ANCESTORS', RelationshipsChartModule::DEFAULT_ANCESTORS) === '1'
            || $params->boolean('ancestors', false);

        $all   = $first->xref() === $second->xref() ? [[$first->xref()]] : $this->relationshipPaths($first, $second, $ancestors);
        $paths = [];

        foreach ($all as $path) {
            $json = $this->relationshipPathJson($tree, $path);

            // Wie im Diagramm: ein Weg, dessen Glieder sich nicht zuordnen lassen, faellt weg.
            if ($json !== null) {
                $paths[] = $json;
            }

            if (count($paths) === self::MAX_RELATIONSHIP_PATHS) {
                break;
            }
        }

        return response([
            'xref1'     => $first->xref(),
            'xref2'     => $second->xref(),
            'ancestors' => $ancestors,
            'paths'     => $paths,
            'more'      => count($all) > count($paths),
        ]);
    }

    /**
     * Die kuerzesten Wege von $first zu $second als abwechselnde Liste Person, Familie, Person ... - der Graph wie in
     * RelationshipsChartModule::calculateRelationships() (dort privat, deshalb hier nachgebaut, ohne die Umwege).
     *
     * @return list<list<string>>
     */
    private function relationshipPaths(Individual $first, Individual $second, bool $ancestors): array
    {
        $tree_id = $first->tree()->id();
        $rows    = DB::table('link')
            ->where('l_file', '=', $tree_id)
            ->whereIn('l_type', ['FAMS', 'FAMC'])
            ->select(['l_from', 'l_to'])
            ->get();

        $keep    = $ancestors ? array_flip($this->relationshipAncestors($first->xref(), $second->xref(), $tree_id)) : [];
        $exclude = $ancestors ? array_flip($this->commonSpouseFamilies($first->xref(), $second->xref(), $tree_id)) : [];

        $graph = [];

        foreach ($rows as $row) {
            if (!$ancestors || isset($keep[$row->l_from]) && !isset($exclude[$row->l_to])) {
                $graph[$row->l_from][$row->l_to] = 1;
                $graph[$row->l_to][$row->l_from] = 1;
            }
        }

        if (!isset($graph[$first->xref()], $graph[$second->xref()])) {
            return [];
        }

        $paths = [];

        foreach ((new Dijkstra($graph))->shortestPaths($first->xref(), $second->xref()) as $path) {
            // Die Bibliothek macht aus Kennungen wie "123" Zahlen.
            $path = array_map(static fn ($xref): string => (string) $xref, $path);

            $paths[implode('-', $path)] = $path;
        }

        return array_values($paths);
    }

    /**
     * Beide Personen und alle ihre Vorfahren (wie allAncestors() im Diagramm).
     *
     * @return list<string>
     */
    private function relationshipAncestors(string $xref1, string $xref2, int $tree_id): array
    {
        $found = [$xref1 => true, $xref2 => true];
        $queue = [$xref1, $xref2];

        while ($queue !== []) {
            $parents = DB::table('link AS l1')
                ->join('link AS l2', static function (JoinClause $join): void {
                    $join
                        ->on('l1.l_to', '=', 'l2.l_to')
                        ->on('l1.l_file', '=', 'l2.l_file');
                })
                ->where('l1.l_file', '=', $tree_id)
                ->where('l1.l_type', '=', 'FAMC')
                ->where('l2.l_type', '=', 'FAMS')
                ->whereIn('l1.l_from', $queue)
                ->pluck('l2.l_from');

            $queue = [];

            foreach ($parents as $parent) {
                if (!isset($found[$parent])) {
                    $found[$parent] = true;
                    $queue[]        = $parent;
                }
            }
        }

        return array_map(static fn ($xref): string => (string) $xref, array_keys($found));
    }

    /**
     * Familien, in denen beide Personen Partner sind (wie excludeFamilies() im Diagramm).
     *
     * @return list<string>
     */
    private function commonSpouseFamilies(string $xref1, string $xref2, int $tree_id): array
    {
        return DB::table('link AS l1')
            ->join('link AS l2', static function (JoinClause $join): void {
                $join
                    ->on('l1.l_to', '=', 'l2.l_to')
                    ->on('l1.l_type', '=', 'l2.l_type')
                    ->on('l1.l_file', '=', 'l2.l_file');
            })
            ->where('l1.l_file', '=', $tree_id)
            ->where('l1.l_type', '=', 'FAMS')
            ->where('l1.l_from', '=', $xref1)
            ->where('l2.l_from', '=', $xref2)
            ->pluck('l1.l_to')
            ->map(static fn ($xref): string => (string) $xref)
            ->all();
    }

    /**
     * Ein Weg als JSON. null, wenn eine Familie des Wegs ihre Glieder nicht (mehr) enthaelt.
     *
     * @param list<string> $path Person, Familie, Person ...
     *
     * @return array<string,mixed>|null
     */
    private function relationshipPathJson(Tree $tree, array $path): array|null
    {
        $codes = [
            'HUSB-HUSB' => ['husband', 'wife', 'spouse'], 'HUSB-WIFE' => ['husband', 'wife', 'spouse'],
            'WIFE-HUSB' => ['husband', 'wife', 'spouse'], 'WIFE-WIFE' => ['husband', 'wife', 'spouse'],
            'HUSB-CHIL' => ['son', 'daughter', 'child'],  'WIFE-CHIL' => ['son', 'daughter', 'child'],
            'CHIL-HUSB' => ['father', 'mother', 'parent'], 'CHIL-WIFE' => ['father', 'mother', 'parent'],
            'CHIL-CHIL' => ['brother', 'sister', 'sibling'],
        ];

        $first  = Registry::individualFactory()->make($path[0], $tree);
        $nodes  = [$first];
        $steps  = [['person' => $this->personSummary($first), 'relation' => null, 'family' => null]];
        // Gemeinsame Vorfahren: wo der Weg vom Hinauf (Eltern) ins Hinab (Kinder, Geschwister) wechselt. Nur bei
        // Blutsverwandtschaft - geht der Weg ueber einen Ehepartner, gibt es keine gemeinsamen Vorfahren.
        $peak   = [];
        $upward = false;
        $inlaw  = false;

        for ($i = 1, $count = count($path); $i < $count; $i += 2) {
            $family = Registry::familyFactory()->make($path[$i], $tree);
            $next   = Registry::individualFactory()->make($path[$i + 1], $tree);

            if (!$family instanceof Family || !$next instanceof Individual) {
                return null;
            }

            $role1 = $this->familyRole($family, $path[$i - 1]);
            $role2 = $this->familyRole($family, $path[$i + 1]);
            $set   = $codes[$role1 . '-' . $role2] ?? null;

            if ($set === null) {
                return null;
            }

            $relation = $set[match ($next->sex()) { 'M' => 0, 'F' => 1, default => 2 }];

            if ($set[0] === 'father') {
                $upward = true;
            } elseif ($set[0] === 'husband') {
                $inlaw = true;
            } elseif ($set[0] === 'brother' && $peak === []) {
                // Geschwister: die Eltern der gemeinsamen Familie
                $peak   = array_values(array_filter([$this->familyMember($family, 'HUSB'), $this->familyMember($family, 'WIFE')]));
                $upward = false;
            } elseif ($set[0] === 'son' && $upward && $peak === []) {
                // hinauf bis zu einer Person, von dort in einer anderen Familie hinab (Halbgeschwister-Linie)
                $peak   = [$path[$i - 1]];
                $upward = false;
            }

            $nodes[] = $family;
            $nodes[] = $next;
            $steps[] = ['person' => $this->personSummary($next), 'relation' => $relation, 'family' => $family->xref()];
        }

        // Nur hinauf: xref2 ist selbst Vorfahr von xref1.
        if ($peak === [] && $upward) {
            $peak = [$path[count($path) - 1]];
        }

        // Nur hinab: xref1 ist Vorfahr von xref2.
        if ($peak === [] && count($path) > 1 && !$inlaw && !$upward) {
            $peak = [$path[0]];
        }

        return [
            'name'            => $this->plain(Registry::container()->get(RelationshipService::class)->nameFromPath($nodes, I18N::language())),
            'commonAncestors' => $inlaw ? [] : $peak,
            'steps'           => $steps,
        ];
    }

    /** HUSB, WIFE oder CHIL - wie die Person in der Familie steht ('' wenn gar nicht). */
    private function familyRole(Family $family, string $xref): string
    {
        return preg_match('/\n1 (HUSB|WIFE|CHIL) @' . preg_quote($xref, '/') . '@/', $family->gedcom(), $match) === 1 ? $match[1] : '';
    }

    private function familyMember(Family $family, string $tag): string|null
    {
        return preg_match('/\n1 ' . $tag . ' @([^@]+)@/', $family->gedcom(), $match) === 1 ? $match[1] : null;
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
     * Der ganze sichtbare Baum fuer Listen und Buecher (ab Stufe 17): ?page=<n>
     *
     * Erst alle Personen, dann alle Familien, je Seite EXPORT_PAGE_SIZE Datensaetze. Verknuepft wird nur ueber
     * Kennungen (famc, fams, husband, wife, children) - der Client setzt den Baum selbst zusammen. total nennt die
     * Datensaetze insgesamt (fuer eine Fortschrittsanzeige); eine Seite kann weniger enthalten, wenn der Benutzer
     * einzelne nicht sehen darf. lastChange wie in Info: aendert er sich waehrend des Abrufs, von vorn beginnen.
     *
     * Datenschutz macht webtrees selbst: wer in einer Familie erscheint und welche Familien einer Person
     * sichtbar sind, kommt aus husband(), wife(), children(), childFamilies(), spouseFamilies() - mit der
     * Baumeinstellung "private Verwandtschaften zeigen" also wie in den Diagrammen. Personen und Familien, die nur
     * so verknuepft, aber nicht sichtbar sind, kommen als Platzhalter ohne Fakten und Medien (private: true).
     */
    public function getExportAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree   = Validator::attributes($request)->tree();
        $page   = max(1, Validator::queryParams($request)->integer('page', 1));
        $offset = ($page - 1) * self::EXPORT_PAGE_SIZE;

        // Dieselbe Stufe, die webtrees fuer Verknuepfungen nimmt (siehe Individual::childFamilies()).
        $link_level = $tree->getPreference('SHOW_PRIVATE_RELATIONSHIPS') === '1' ? Auth::PRIV_HIDE : Auth::accessLevel($tree);

        $individual_count = DB::table('individuals')->where('i_file', '=', $tree->id())->count();
        $family_count     = DB::table('families')->where('f_file', '=', $tree->id())->count();

        $individuals = [];
        $families    = [];

        if ($offset < $individual_count) {
            $rows = DB::table('individuals')
                ->where('i_file', '=', $tree->id())
                ->orderBy('i_id')
                ->offset($offset)
                ->limit(self::EXPORT_PAGE_SIZE)
                ->get()
                ->map(Registry::individualFactory()->mapper($tree));

            foreach ($rows as $individual) {
                if ($individual instanceof Individual && $individual->canShowName($link_level)) {
                    $individuals[] = $this->exportIndividualJson($individual);
                }
            }
        }

        $family_offset = max(0, $offset - $individual_count);
        $family_limit  = self::EXPORT_PAGE_SIZE - max(0, min(self::EXPORT_PAGE_SIZE, $individual_count - $offset));

        if ($family_limit > 0 && $family_offset < $family_count) {
            $rows = DB::table('families')
                ->where('f_file', '=', $tree->id())
                ->orderBy('f_id')
                ->offset($family_offset)
                ->limit($family_limit)
                ->get()
                ->map(Registry::familyFactory()->mapper($tree));

            foreach ($rows as $family) {
                if ($family instanceof Family && $family->canShow($link_level)) {
                    $families[] = $this->exportFamilyJson($family);
                }
            }
        }

        return response([
            'lastChange'  => (int) DB::table('change')->where('gedcom_id', '=', $tree->id())->max('change_id'),
            'page'        => $page,
            'nextPage'    => $offset + self::EXPORT_PAGE_SIZE < $individual_count + $family_count ? $page + 1 : null,
            'total'       => ['individuals' => $individual_count, 'families' => $family_count],
            'individuals' => $individuals,
            'families'    => $families,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function exportIndividualJson(Individual $individual): array
    {
        $visible = $individual->canShow();

        return $this->personSummary($individual) + [
            'famc'  => $individual->childFamilies()->map(static fn (Family $family): string => $family->xref())->values()->all(),
            'fams'  => $individual->spouseFamilies()->map(static fn (Family $family): string => $family->xref())->values()->all(),
            'facts' => $visible ? $this->factsJson($individual) : [],
            'media' => $visible ? $this->mediaJson($individual) : [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function exportFamilyJson(Family $family): array
    {
        $visible = $family->canShow();

        return [
            'xref'     => $family->xref(),
            'private'  => !$visible,
            'husband'  => $family->husband()?->xref(),
            'wife'     => $family->wife()?->xref(),
            'children' => $family->children()->map(static fn (Individual $child): string => $child->xref())->values()->all(),
            'marriage' => $visible ? $this->eventJson($family->getMarriageDate(), $family->getMarriagePlace()) : null,
            'facts'    => $visible ? $this->factsJson($family) : [],
            'media'    => $visible ? $this->mediaJson($family) : [],
        ];
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
