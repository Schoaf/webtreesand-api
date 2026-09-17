<?php

declare(strict_types=1);

namespace WebtreesAnd\Api;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Date;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Elements\UnknownElement;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Http\RequestHandlers\ModuleAction;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Note;
use Fisharebest\Webtrees\Place;
use Fisharebest\Webtrees\PlaceLocation;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\CalendarService;
use Fisharebest\Webtrees\Services\LinkedRecordService;
use Fisharebest\Webtrees\Services\MediaFileService;
use Fisharebest\Webtrees\Services\PendingChangesService;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Services\SearchService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Webtrees;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function array_key_exists;
use function array_map;
use function bin2hex;
use function class_exists;
use function count;
use function explode;
use function hash;
use function http_build_query;
use function html_entity_decode;
use function in_array;
use function ini_get;
use function intdiv;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function min;
use function parse_url;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function preg_split;
use function random_bytes;
use function response;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function strtoupper;
use function time;
use function strip_tags;
use function strlen;
use function strrpos;
use function substr;
use function trim;
use function usort;

use const ENT_HTML5;
use const ENT_QUOTES;
use const PHP_URL_HOST;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Lesende JSON-Schnittstelle fuer die native WebtreesAnd-App.
 *
 * Alle Endpunkte laufen ueber die eingebaute Modul-Route von webtrees
 *   /module/_webtreesand-api_/<Action>[/<tree>]
 * (ohne URL-Rewriting: index.php?route=...). Eigene Routen werden bewusst
 * nicht registriert - die Routing-API aendert sich mit webtrees 2.3, die
 * Modul-Route bleibt gleich.
 *
 * Datenschutz: Es wird ausschliesslich ueber die webtrees-Objekte gelesen
 * (canShow(), facts(), children() ...). Damit gelten dieselben Regeln wie
 * auf den HTML-Seiten, fuer Gaeste wie fuer angemeldete Benutzer.
 */
class WebtreesAndApiModule extends AbstractModule implements ModuleCustomInterface, ModuleMenuInterface, MiddlewareInterface
{
    use ModuleCustomTrait;
    use ModuleMenuTrait;

    public const string MODULE_NAME = '_webtreesand-api_';
    // 6: Koppeln per Einmal-Code (Seiten App/Connect, Aktion Pair)
    // 5: Info.maxUpload, Moderation (Pending, Accept, Reject), trees[].canModerate/pending
    // 4: Anniversaries, DeleteRecord, Unlink; Ortskoordinaten auch aus der webtrees-Ortstabelle
    // 3: ?lang=<Sprache> fuer Beschriftungen und Datumsangaben der Antwort
    // 2: MediaList, Individual.relationship (relativeTo), Info.trees[].individuals, Pedigree.ancestors[].hasParents
    public const int    API_VERSION = 6;

    private const string DESCRIPTION = 'JSON-Schnittstelle für die native Android-App „webtreesAnd“ – liest und schreibt mit den Rechten des angemeldeten Benutzers.';

    // Eine Textdatei mit der neuesten Versionsnummer; webtrees zeigt damit in der Modulverwaltung einen Update-Hinweis.
    private const string LATEST_VERSION_URL = 'https://raw.githubusercontent.com/thobgg/webtreesand-api/main/latest-version.txt';
    private const string SUPPORT_URL        = 'https://github.com/thobgg/webtreesand-api';

    // Hier liegt die App zum Herunterladen (Seite "App" in webtrees).
    private const string APP_DOWNLOAD_URL   = 'https://github.com/thobgg/webtreesAnd/releases/latest';

    // Koppeln: der Einmal-Code gilt so viele Sekunden und genau einmal. Gespeichert wird nur sein Hash.
    private const int    PAIR_SECONDS       = 600;
    private const string PAIR_SETTING       = 'webtreesand_pair';

    private const int PAGE_SIZE           = 50;
    private const int MEDIA_PAGE_SIZE     = 60;
    private const int MAX_PEDIGREE_GEN    = 6;
    private const int MAX_DESCENDANTS_GEN = 4;

    // Diese Tags sind Verknuepfungen oder Verwaltungsdaten, keine Ereignisse.
    // (HUSB/WIFE/CHIL sind die Verknuepfungen innerhalb eines Familien-Datensatzes.)
    private const array SKIP_FACTS = ['FAMS', 'FAMC', 'HUSB', 'WIFE', 'CHIL', 'OBJE', 'CHAN', '_UID', '_TODO', '_WT_OBJE_SORT'];

    // Verknuepfungen laufen ueber AddIndividual/Media - nicht ueber den Fakten-Editor.
    private const array LINK_TAGS = ['FAMS', 'FAMC', 'HUSB', 'WIFE', 'CHIL', 'OBJE', 'CHAN'];

    // Ereignisse, die ohne Datum/Ort als "1 TAG Y" geschrieben werden.
    private const array EVENT_TAGS = ['BIRT', 'CHR', 'BAPM', 'DEAT', 'BURI', 'CREM', 'MARR', 'DIV', 'ENGA'];

    private const array ADDABLE_TAGS = [
        'INDI' => ['BIRT', 'CHR', 'BAPM', 'CONF', 'DEAT', 'BURI', 'CREM', 'OCCU', 'RESI', 'EDUC', 'GRAD', 'RELI', 'NATI', 'TITL', 'EMIG', 'IMMI', 'NATU', 'CENS', 'RETI', 'EVEN', 'FACT', 'NOTE'],
        'FAM'  => ['MARR', 'ENGA', 'MARB', 'DIV', 'CENS', 'RESI', 'EVEN', 'NOTE'],
    ];

    public function __construct()
    {
        // Siehe Sammlungen-Modul: vom DI-Container erzeugte Instanzen haben sonst keinen Namen.
        $this->setName(self::MODULE_NAME);
    }

    public function title(): string
    {
        return 'WebtreesAnd API';
    }

    public function description(): string
    {
        return I18N::translate(self::DESCRIPTION);
    }

    /**
     * Quelltext-Sprache ist Deutsch (wie im Sammlungen-Modul); alle anderen Sprachen bekommen Englisch.
     *
     * @return array<string,string>
     */
    public function customTranslations(string $language): array
    {
        if (str_starts_with($language, 'de')) {
            return [];
        }

        return [
            'App'                                        => 'App',
            'webtreesAnd – die App für diesen Stammbaum' => 'webtreesAnd – the app for this family tree',
            'Mit webtreesAnd verbinden'                  => 'Connect with webtreesAnd',
            '1. App installieren'                        => '1. Install the app',
            'Lade die App auf dein Android-Handy oder -Tablet. Beim ersten Mal fragt Android, ob dein Browser Apps installieren darf – das einmal erlauben.' => 'Download the app to your Android phone or tablet. The first time, Android asks whether your browser may install apps – allow it once.',
            'App herunterladen'                          => 'Download the app',
            'Am Computer? Dann diesen Code mit der Handy-Kamera scannen:' => 'On a computer? Scan this code with your phone camera:',
            '2. Mit deinem Konto verbinden'              => '2. Connect your account',
            'Ein Tipp genügt – Adresse und Passwort musst du in der App nicht eintippen.' => 'One tap is enough – there is no need to type the address or a password into the app.',
            'Jetzt verbinden'                            => 'Connect now',
            'Der Code gilt %s Minuten und nur ein einziges Mal. Er verbindet die App mit deinem Konto – gib ihn nicht weiter.' => 'The code is valid for %s minutes and only once. It connects the app to your account – do not share it.',
            'Melde dich an, um die App mit deinem Konto zu verbinden.' => 'Sign in to connect the app to your account.',
            'Das Verbinden per Code ist nur über eine verschlüsselte Verbindung (https) möglich. In der App kannst du dich stattdessen mit Adresse, Benutzername und Passwort anmelden.' => 'Connecting with a code needs an encrypted connection (https). In the app you can sign in with address, user name and password instead.',
            'webtreesAnd öffnen'                         => 'Open webtreesAnd',
            'Die App ist noch nicht installiert?'        => 'The app is not installed yet?',
            'Nichts passiert? Dann ist die App noch nicht installiert oder der Code ist abgelaufen – öffne in webtrees die Seite „App“ erneut.' => 'Nothing happens? Then the app is not installed yet or the code has expired – open the “App” page in webtrees again.',
            self::DESCRIPTION => 'JSON interface for the native Android app “webtreesAnd” – reads and writes with the rights of the signed-in user.',
        ];
    }

    public function customModuleAuthorName(): string
    {
        return 'Thomas Bugge';
    }

    public function customModuleVersion(): string
    {
        return '0.7.0';
    }

    public function customModuleLatestVersionUrl(): string
    {
        return self::LATEST_VERSION_URL;
    }

    public function customModuleSupportUrl(): string
    {
        return self::SUPPORT_URL;
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    // ───────────────────────────── Menue und Seiten fuer Menschen ─────────────────────────────

    public function defaultMenuOrder(): int
    {
        return 99;
    }

    /**
     * Menuepunkt "App" - nur fuer angemeldete Benutzer, denn dort wird das eigene Konto mit der App verbunden.
     * (Verwalter koennen ihn unter Verwaltung -> Module -> Menues verschieben oder abschalten.)
     */
    public function getMenu(Tree $tree): Menu|null
    {
        if (!Auth::check()) {
            return null;
        }

        return new Menu(I18N::translate('App'), $this->actionUrl('App', $tree->name()), 'menu-webtreesand', ['rel' => 'nofollow']);
    }

    /**
     * Seite "App": App installieren (Link + QR) und das eigene Konto mit der App verbinden (Knopf + QR).
     */
    public function getAppAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree     = $request->getAttribute('tree');
        $tree     = $tree instanceof Tree ? $tree : null;
        $user     = Auth::user();
        $base_url = Validator::attributes($request)->string('base_url');
        $host     = (string) parse_url($base_url, PHP_URL_HOST);

        // Der Einmal-Code ist so gut wie ein Passwort - er darf nur verschluesselt reisen (Ausnahme: der eigene Rechner).
        $secure = str_starts_with($base_url, 'https://') || in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        $connect_url = '';
        $deep_link   = '';

        if (Auth::check() && $secure) {
            $code = bin2hex(random_bytes(24));
            $user->setPreference(self::PAIR_SETTING, hash('sha256', $code) . '|' . (time() + self::PAIR_SECONDS) . '|' . ($tree?->name() ?? ''));

            $params      = ['code' => $code, 'tree' => $tree?->name() ?? '', 'user' => $user->userName()];
            $connect_url = $this->actionUrl('Connect', null, $params);
            $deep_link   = $this->deepLink($base_url, $params);
        }

        return $this->viewResponse($this->name() . '::app', [
            'title'        => I18N::translate('webtreesAnd – die App für diesen Stammbaum'),
            'tree'         => $tree,
            'logged_in'    => Auth::check(),
            'secure'       => $secure,
            'download_url' => self::APP_DOWNLOAD_URL,
            'download_qr'  => $this->qrSvg(self::APP_DOWNLOAD_URL),
            'connect_url'  => $connect_url,
            'connect_qr'   => $connect_url === '' ? '' : $this->qrSvg($connect_url),
            'deep_link'    => $deep_link,
            'minutes'      => intdiv(self::PAIR_SECONDS, 60),
        ]);
    }

    /**
     * Zielseite des Verbinden-QR-Codes: wird im Browser des HANDYS geoeffnet (dort ist man meist nicht angemeldet)
     * und reicht nur an die App weiter. Kameras oeffnen verlaesslich nur https-Adressen, keine App-Links - daher dieser Umweg.
     */
    public function getConnectAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = [
            'code' => Validator::queryParams($request)->string('code', ''),
            'tree' => Validator::queryParams($request)->string('tree', ''),
            'user' => Validator::queryParams($request)->string('user', ''),
        ];

        return $this->viewResponse($this->name() . '::connect', [
            'title'        => I18N::translate('Mit webtreesAnd verbinden'),
            'tree'         => null,
            'deep_link'    => $this->deepLink(Validator::attributes($request)->string('base_url'), $params),
            'download_url' => self::APP_DOWNLOAD_URL,
        ]);
    }

    /**
     * Die App loest den Einmal-Code ein: Rumpf { code }. Danach ist ihre Sitzung als dieser Benutzer angemeldet -
     * ohne dass ein Passwort das Geraet je gesehen hat.
     */
    public function postPairAction(ServerRequestInterface $request): ResponseInterface
    {
        $code = $this->str($this->body($request), 'code');

        if (preg_match('/^[0-9a-f]{48}$/', $code) !== 1) {
            return $this->error(400, 'pair-invalid');
        }

        $row = DB::table('user_setting')
            ->where('setting_name', '=', self::PAIR_SETTING)
            ->where('setting_value', 'LIKE', hash('sha256', $code) . '|%')
            ->first();

        if ($row === null) {
            return $this->error(403, 'pair-invalid');
        }

        $user = Registry::container()->get(UserService::class)->find((int) $row->user_id);

        [, $expires, $tree_name] = explode('|', (string) $row->setting_value) + ['', '0', ''];

        // Einmal heisst einmal: der Code ist ab jetzt verbraucht - auch wenn er abgelaufen ist.
        $user?->setPreference(self::PAIR_SETTING, '');

        if ($user === null || (int) $expires < time()) {
            return $this->error(403, 'pair-expired');
        }

        if ($user->getPreference(UserInterface::PREF_IS_EMAIL_VERIFIED) !== '1' || $user->getPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED) !== '1') {
            return $this->error(403, 'pair-invalid');
        }

        Auth::login($user);
        Log::addAuthenticationLog('Login (webtreesAnd, QR-Code): ' . $user->userName() . '/' . $user->realName());
        $user->setPreference(UserInterface::PREF_TIMESTAMP_ACTIVE, (string) time());

        return response(['ok' => true, 'tree' => $tree_name, 'user' => $user->userName()]);
    }

    /**
     * @param array<string,string> $params
     */
    private function deepLink(string $base_url, array $params): string
    {
        return 'webtreesand://connect?' . http_build_query(['url' => $base_url] + $params);
    }

    /**
     * Adresse einer Aktion dieses Moduls. Die Route heisst in webtrees 2.2 "module", ab 2.3 traegt sie den Klassennamen.
     *
     * @param array<string,string> $params
     */
    private function actionUrl(string $action, string|null $tree, array $params = []): string
    {
        $all = ['module' => $this->name(), 'action' => $action, 'tree' => $tree] + $params;

        try {
            return route('module', $all);
        } catch (Throwable) {
            return route(ModuleAction::class, $all);
        }
    }

    /**
     * QR-Code als SVG. webtrees 2.2 bringt dafuer TCPDF mit, 2.3 tc-lib-barcode; fehlt beides, bleibt es beim Link.
     */
    private function qrSvg(string $data): string
    {
        try {
            if (class_exists('TCPDF2DBarcode')) {
                return (new \TCPDF2DBarcode($data, 'QRCODE,M'))->getBarcodeSVGcode(5, 5, 'black');
            }

            if (class_exists('Com\\Tecnick\\Barcode\\Barcode')) {
                return (new \Com\Tecnick\Barcode\Barcode())->getBarcodeObj('QRCODE,M', $data, -5, -5, 'black')->getSvgCode();
            }
        } catch (Throwable) {
            // dann eben ohne Bild
        }

        return '';
    }

    /**
     * Sprache der Antwort: ?lang=de (oder en-GB ...). Die App laeuft in der Sprache des Geraets, das
     * webtrees-Konto vielleicht in einer anderen - ohne diesen Schritt kaemen Beschriftungen ("Occupation")
     * und Datumsangaben in der Kontosprache und mischten sich mit den Texten der App.
     * Umgeschaltet wird nur fuer diese eine Antwort; Sitzung und Kontoeinstellung bleiben unberuehrt.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute('module') === self::MODULE_NAME) {
            $wanted = Validator::queryParams($request)->string('lang', '');
            $tag    = $wanted === '' ? null : $this->matchLanguage($wanted);

            if ($tag !== null && $tag !== I18N::languageTag()) {
                I18N::init($tag);
                // webtrees hat die Bezeichnungen der GEDCOM-Tags ("Geburt", "Beruf" ...) schon in der alten
                // Sprache aufgebaut - nach dem Wechsel neu registrieren, sonst bleiben sie stehen.
                (new Gedcom())->registerTags(Registry::elementFactory(), true);
            }
        }

        return $handler->handle($request);
    }

    /**
     * "de-DE" -> "de", "en" -> "en-US" ... - nur Sprachen, die in dieser webtrees-Installation aktiv sind.
     */
    private function matchLanguage(string $wanted): string|null
    {
        $tags    = array_map(static fn ($locale): string => $locale->languageTag(), I18N::activeLocales());
        $wanted  = strtolower($wanted);
        $primary = explode('-', $wanted)[0];

        foreach ([
            static fn (string $tag): bool => strtolower($tag) === $wanted,
            static fn (string $tag): bool => strtolower($tag) === $primary,
            static fn (string $tag): bool => $tag === 'en-US' && $primary === 'en',
            static fn (string $tag): bool => str_starts_with(strtolower($tag), $primary . '-'),
        ] as $rule) {
            foreach ($tags as $tag) {
                if ($rule($tag)) {
                    return $tag;
                }
            }
        }

        return null;
    }

    // ───────────────────────────── Endpunkte ─────────────────────────────

    /**
     * Einstieg fuer die App: Versionen, angemeldeter Benutzer, sichtbare Baeume.
     * Liefert auch das CSRF-Token - die App braucht es fuer den POST auf /login.
     */
    public function getInfoAction(ServerRequestInterface $request): ResponseInterface
    {
        $user  = Auth::user();
        $trees = [];

        foreach (Registry::container()->get(TreeService::class)->all() as $tree) {
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
        $today = Registry::timestampFactory()->now()->julianDay();

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

    // ───────────────────────────── Moderation ─────────────────────────────

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
            $record = Registry::gedcomRecordFactory()->make((string) $xref, $tree);

            if ($record === null) {
                continue;
            }

            $data[] = [
                'xref'    => (string) $xref,
                'type'    => $record->tag(),
                'name'    => $this->plain($record->fullName()),
                // neu: vor der ersten Aenderung gab es den Datensatz nicht; geloescht: nach der letzten gibt es ihn nicht mehr
                'kind'    => $changes->first()->old_gedcom === '' ? 'new' : ($changes->last()->new_gedcom === '' ? 'deleted' : 'changed'),
                'changes' => $changes->count(),
                'users'   => $changes->pluck('real_name')->unique()->values()->all(),
                'time'    => (string) $changes->last()->change_time,
            ];
        }

        return response(['data' => $data]);
    }

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

    // ───────────────────────────── Schreiben ─────────────────────────────
    //
    // Alle POST-Aktionen laufen durch die CSRF-Pruefung von webtrees: die App schickt
    // das Token aus "Info" im Header X-CSRF-TOKEN. Der Rumpf ist JSON (oder ein Formular).
    // Geschrieben wird nur ueber createFact/updateFact/createIndividual ... - damit gelten
    // Bearbeiterrechte, RESN-Sperren, Aenderungsprotokoll und die Moderation ("ausstehende
    // Aenderungen") genau wie in der Weboberflaeche.

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
                'isEvent' => in_array($tag, self::EVENT_TAGS, true),
            ];
        }

        return response(['type' => $type, 'data' => $data]);
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

        if ($this->str($body, 'gedcom') !== '') {
            $gedcom = trim(str_replace("\r", '', $this->str($body, 'gedcom')));
        } else {
            $gedcom = $this->buildFactGedcom($body, $old?->gedcom() ?? '');
        }

        if (preg_match('/^1 ([A-Z_][A-Z0-9_]*)/', $gedcom, $match) !== 1) {
            return $this->error(400, 'invalid-gedcom');
        }

        if (in_array($match[1], self::LINK_TAGS, true)) {
            return $this->error(400, 'link-tag-not-allowed');
        }

        if (preg_match('/\n2 DATE (.+)/', $gedcom, $date_match) === 1 && !(new Date($date_match[1]))->isOK()) {
            return $this->error(400, 'invalid-date');
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

                if (in_array($this->shortTag($fact->tag()), self::LINK_TAGS, true)) {
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

        $given   = $this->line($this->str($body, 'given'));
        $surname = $this->line($this->str($body, 'surname'));
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
        $gedcom .= $this->eventGedcom('BIRT', $this->str($body, 'birthDate'), $this->str($body, 'birthPlace'), false);
        $gedcom .= $this->eventGedcom('DEAT', $this->str($body, 'deathDate'), $this->str($body, 'deathPlace'), ($body['dead'] ?? false) === true);

        $new = $tree->createIndividual($gedcom);

        $marriage = $this->eventGedcom('MARR', $this->str($body, 'marriageDate'), $this->str($body, 'marriagePlace'), false);

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
     * multipart/form-data: file, title?, note?, folder?
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
        // auto=1: Dateiname wird der SHA1 des Inhalts - keine Kollisionen, keine Sonderzeichen.
        $upload_request = $request->withParsedBody([
            'file_location' => 'upload',
            'folder'        => $this->str($body, 'folder'),
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

    // ───────────────────────────── JSON-Bausteine ─────────────────────────────

    /**
     * Kurzform einer Person. Fuer nicht sichtbare Personen liefert webtrees selbst
     * "Privat" als Namen und leere Daten - hier wird nichts zusaetzlich preisgegeben.
     *
     * @return array<string,mixed>
     */
    private function personSummary(Individual $individual): array
    {
        $thumb = null;

        try {
            $media_file = $individual->findHighlightedMediaFile();
            if ($media_file !== null && $media_file->isImage()) {
                $thumb = $media_file->imageUrl(200, 200, 'crop');
            }
        } catch (Throwable) {
            // Fehlende Datei o. ae. - dann eben kein Bild.
        }

        return [
            'xref'     => $individual->xref(),
            'name'     => $this->plain($individual->fullName()),
            'sortName' => $individual->sortName(),
            'sex'      => $individual->sex(),
            'isDead'   => $individual->isDead(),
            'private'  => !$individual->canShow(),
            'lifespan' => $this->plain($individual->lifespan()),
            'birth'    => $this->eventJson($individual->getBirthDate(), $individual->getBirthPlace()),
            'death'    => $this->eventJson($individual->getDeathDate(), $individual->getDeathPlace()),
            'thumb'    => $thumb,
            'url'      => $individual->url(),
        ];
    }

    /**
     * @param Individual|null $relative_to bei Partnerfamilien: die Person, deren Partner gesucht wird
     *
     * @return array<string,mixed>
     */
    private function familyJson(Family $family, Individual|null $relative_to): array
    {
        $husband = $family->husband();
        $wife    = $family->wife();
        $spouse  = $relative_to instanceof Individual ? $family->spouse($relative_to) : null;

        $children = [];
        foreach ($family->children() as $child) {
            $children[] = $this->personSummary($child);
        }

        return [
            'xref'     => $family->xref(),
            'name'     => $this->plain($family->fullName()),
            'url'      => $family->url(),
            'husband'  => $husband instanceof Individual ? $this->personSummary($husband) : null,
            'wife'     => $wife instanceof Individual ? $this->personSummary($wife) : null,
            'spouse'   => $spouse instanceof Individual ? $this->personSummary($spouse) : null,
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
                'date'    => $this->dateJson($fact->date()),
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

        foreach ($record->facts(['OBJE']) as $fact) {
            $media = $fact->target();

            if (!$media instanceof Media || !$media->canShow()) {
                continue;
            }

            foreach ($this->mediaFilesJson($media) as $file) {
                $data[] = $file;
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

            try {
                $thumb = $is_image ? $media_file->imageUrl(400, 400, 'contain') : null;
                $full  = $media_file->isExternal() ? $media_file->filename() : $media_file->downloadUrl('inline');
            } catch (Throwable) {
                continue;
            }

            $data[] = [
                'xref'    => $media->xref(),
                'title'   => $media_file->title() !== '' ? $media_file->title() : $this->plain($media->fullName()),
                'mime'    => $media_file->mimeType(),
                'isImage' => $is_image,
                'thumb'   => $thumb,
                'file'    => $full,
                'url'     => $media->url(),
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

        try {
            return $this->plain(Registry::container()->get(RelationshipService::class)->getCloseRelationshipName($other, $individual));
        } catch (Throwable) {
            return '';
        }
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
    private function dateJson(Date $date): array|null
    {
        if (!$date->isOK()) {
            return null;
        }

        return [
            'text' => $this->plain($date->display()),
            'year' => $date->gregorianYear(),
            'jd'   => $date->minimumJulianDay(),
        ];
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
            try {
                $location  = new PlaceLocation($place->gedcomName());
                $latitude  = $location->latitude();
                $longitude = $location->longitude();
            } catch (Throwable) {
                $latitude = $longitude = null;
            }
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

        try {
            return $this->plain(Registry::elementFactory()->make($fact->tag())->value($value, $tree));
        } catch (Throwable) {
            return $value;
        }
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

    // ───────────────────────────── Schreib-Hilfen ─────────────────────────────

    /**
     * Kleinster der PHP-Werte upload_max_filesize und post_max_size in Bytes ("2M" -> 2097152). 0 = unbekannt.
     */
    private function maxUploadBytes(): int
    {
        $limits = [];

        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $value = trim((string) ini_get($setting));

            if ($value === '' || $value === '0' || $value === '-1') {
                continue;
            }

            $number = (float) $value;
            $unit   = strtoupper(substr($value, -1));
            $factor = ['K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3][$unit] ?? 1;

            $limits[] = (int) ($number * $factor);
        }

        return $limits === [] ? 0 : min($limits);
    }

    /**
     * Neues Ereignis bauen oder ein bestehendes gezielt aendern.
     *
     * @param array<string,mixed> $body
     */
    private function buildFactGedcom(array $body, string $old): string
    {
        if ($old === '') {
            $tag    = strtoupper($this->line($this->str($body, 'tag')));
            $value  = $this->multiline($this->str($body, 'value'), 2);
            $gedcom = '1 ' . $tag . ($value === '' ? '' : ' ' . $value);
        } else {
            [$gedcom] = explode("\n", $old, 2);
            $tag      = preg_match('/^1 (\S+)/', $gedcom, $match) === 1 ? $match[1] : '';
            $rest     = substr($old, strlen($gedcom));

            if (array_key_exists('value', $body)) {
                $value  = $this->multiline($this->str($body, 'value'), 2);
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
            $gedcom = $this->insertAfterFirstLine($gedcom, $insert);
        }

        if (array_key_exists('place', $body)) {
            $place   = $this->line($this->str($body, 'place'));
            $current = preg_match('/\n2 PLAC (.*)/', $gedcom, $match) === 1 ? trim($match[1]) : '';

            // Unveraenderter Ort: Koordinaten (3 MAP ...) behalten.
            if ($place !== $current) {
                $gedcom = (string) preg_replace('/\n2 PLAC.*(\n[3-9] .*)*/', '', $gedcom);
                $gedcom = $this->insertAfterFirstLine($gedcom, $place === '' ? '' : "\n2 PLAC " . $place);
            }
        }

        if (array_key_exists('date', $body)) {
            $date   = strtoupper($this->line($this->str($body, 'date')));
            $gedcom = (string) preg_replace('/\n2 DATE.*(\n[3-9] .*)*/', '', $gedcom);
            $gedcom = $this->insertAfterFirstLine($gedcom, $date === '' ? '' : "\n2 DATE " . $date);
        }

        if (array_key_exists('note', $body)) {
            $note   = $this->multiline($this->str($body, 'note'), 3);
            // Nur die erste eingebettete Notiz ersetzen; Verweise auf Notiz-Datensaetze bleiben.
            $gedcom = (string) preg_replace('/\n2 NOTE (?!@)[^\n]*(\n3 CONT[^\n]*)*/', '', $gedcom, 1);
            $gedcom .= $note === '' ? '' : "\n2 NOTE " . $note;
        }

        // "1 BIRT" ohne alles waere leer - GEDCOM schreibt dafuer "1 BIRT Y".
        if (in_array($tag, self::EVENT_TAGS, true) && preg_match('/^1 ' . preg_quote($tag, '/') . '$/', $gedcom) === 1) {
            $gedcom .= ' Y';
        } elseif (in_array($tag, self::EVENT_TAGS, true)) {
            $gedcom = (string) preg_replace('/^(1 ' . preg_quote($tag, '/') . ') Y(?=\n)/', '$1', $gedcom);
        }

        return $gedcom;
    }

    /**
     * Zeilen hinter die Ebene-1-Zeile (samt deren CONT-Fortsetzungen) setzen.
     */
    private function insertAfterFirstLine(string $gedcom, string $insert): string
    {
        if ($insert === '') {
            return $gedcom;
        }

        preg_match('/^[^\n]*(\n2 CONT[^\n]*)*/', $gedcom, $match);

        return $match[0] . $insert . substr($gedcom, strlen($match[0]));
    }

    private function eventGedcom(string $tag, string $date, string $place, bool $happened): string
    {
        $date  = strtoupper($this->line($date));
        $place = $this->line($place);

        if ($date === '' && $place === '') {
            return $happened ? "\n1 " . $tag . ' Y' : '';
        }

        return "\n1 " . $tag . ($date === '' ? '' : "\n2 DATE " . $date) . ($place === '' ? '' : "\n2 PLAC " . $place);
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

    /**
     * Rumpf als Array - Formular/Multipart oder JSON.
     *
     * @return array<string,mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }

        $json = json_decode((string) $request->getBody(), true);

        return is_array($json) ? $json : [];
    }

    /**
     * @param array<string,mixed> $body
     */
    private function str(array $body, string $key, string $default = ''): string
    {
        $value = $body[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * Einzeiliger GEDCOM-Wert: keine Zeilenumbrueche, sonst liessen sich Zeilen einschleusen.
     */
    private function line(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Mehrzeiliger GEDCOM-Wert: Folgezeilen werden zu "<level> CONT ...".
     */
    private function multiline(string $value, int $cont_level): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));

        return str_replace("\n", "\n" . $cont_level . ' CONT ', $value);
    }

    // ───────────────────────────── Hilfen ─────────────────────────────

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

    private function xref(ServerRequestInterface $request): string
    {
        return Validator::queryParams($request)->isXref()->string('xref');
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

    /**
     * Fachliche Fehler kommen bewusst mit HTTP 200: viele Webserver (z. B. Synology Web Station,
     * nginx mit fastcgi_intercept_errors) ersetzen bei 4xx/5xx den Antwortinhalt durch ihre eigene
     * Fehlerseite - der Fehlercode kaeme nie in der App an. "status" nennt den gemeinten Code.
     */
    private function error(int $status, string $code): ResponseInterface
    {
        return response(['ok' => false, 'error' => $code, 'status' => $status]);
    }
}
