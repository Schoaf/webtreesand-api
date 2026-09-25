<?php

declare(strict_types=1);

namespace Api4Webtrees;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Http\RequestHandlers\ModuleAction;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleFooterInterface;
use Fisharebest\Webtrees\Module\ModuleFooterTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

use function array_map;
use function basename;
use function explode;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function preg_match;
use function response;
use function str_contains;
use function str_starts_with;
use function strtolower;

/**
 * Einstieg des Moduls: Metadaten, Menue, Middleware (Baum-Freigabe, Sprache) und kleine Request-Helfer.
 *
 * Alle Endpunkte laufen ueber die eingebaute Modul-Route /module/_api4webtrees_/<Action>[/<tree>]
 * (ohne URL-Rewriting: index.php?route=...). Eigene Routen gibt es bewusst nicht - die Routing-API aendert
 * sich mit webtrees 2.3, die Modul-Route bleibt.
 *
 * Aufgeteilt nach Aufgabe:
 *   src/AppPages.php      Einstellungen, Seite "App", Koppeln per Einmal-Code
 *   src/ReadActions.php   lesende JSON-Endpunkte (GET)
 *   src/WriteActions.php  schreibende JSON-Endpunkte (POST)
 *   src/JsonBuilders.php  Bausteine der JSON-Antworten
 *   src/GedcomText.php    reine GEDCOM-Textfunktionen (bauen, pruefen, entschaerfen)
 */
class Api4WebtreesModule extends AbstractModule implements ModuleCustomInterface, ModuleConfigInterface, ModuleFooterInterface, MiddlewareInterface
{
    use ModuleCustomTrait;
    use ModuleFooterTrait;

    use AppPages;
    use ReadActions;
    use WriteActions;
    use JsonBuilders;


    // webtrees benennt ein eigenes Modul nach seinem Ordner ("_api4webtrees_") - was setName() im Modul sagt,
    // ueberschreibt ModuleService::customModules() gleich nach dem Laden. Bis 1.2 hiess der Ordner webtreesand-api;
    // unter diesem Namen liegen bei Bestandsinstallationen noch die Einstellungen (siehe boot()).
    public const string OLD_MODULE_NAME = '_webtreesand-api_';
    // 8: Places (Ortsvorschlaege), facts[].date.gedcom, media[].factId/primary, UnlinkMedia, PrimaryMedia, Link,
    //    AddIndividual.facts, Individuals?scope=all, Info.trees[].lastChange
    // 7: Verwalter legt fest, welche Stammbaeume die App erreicht (Fehler tree-disabled)
    // 6: Koppeln per Einmal-Code (Seiten App/Connect, Aktion Pair)
    // 5: Info.maxUpload, Moderation (Pending, Accept, Reject), trees[].canModerate/pending
    // 4: Anniversaries, DeleteRecord, Unlink; Ortskoordinaten auch aus der webtrees-Ortstabelle
    // 3: ?lang=<Sprache> fuer Beschriftungen und Datumsangaben der Antwort
    // 2: MediaList, Individual.relationship (relativeTo), Info.trees[].individuals, Pedigree.ancestors[].hasParents
    // 11: Bookmarks (Merkliste je Benutzer und Baum, Benutzereinstellung), given/surname je Person
    public const int    API_VERSION = 11;

    /** Benutzereinstellung je Baum: die Merkliste als Liste von Personenkennungen. */
    private const string BOOKMARKS_PREF = 'api4webtrees_bookmarks';

    public const string DESCRIPTION = 'JSON-Schnittstelle für die native Android-App „wtAnd“ – liest und schreibt mit den Rechten des angemeldeten Benutzers.';

    // Eine Textdatei mit der neuesten Versionsnummer; webtrees zeigt damit in der Modulverwaltung einen Update-Hinweis.
    private const string LATEST_VERSION_URL = 'https://raw.githubusercontent.com/thobgg/api4webtrees/main/latest-version.txt';
    private const string SUPPORT_URL        = 'https://github.com/thobgg/api4webtrees';

    // Hier liegt die App zum Herunterladen (Seite "App" in webtrees).
    private const string APP_DOWNLOAD_URL   = 'https://github.com/thobgg/app4webtrees/releases/latest';

    // Koppeln: der Einmal-Code gilt so viele Sekunden und genau einmal. Gespeichert wird nur sein Hash.
    private const int    PAIR_SECONDS       = 600;
    private const string PAIR_SETTING       = 'webtreesand_pair';

    // Moduleinstellung: fuer welche Stammbaeume die App freigegeben ist. '*' (Standard) = alle, sonst Namen mit Komma.
    private const string TREES_SETTING      = 'app_trees';

    // Benutzereinstellung: Hinweis auf die App nicht mehr zeigen - 'connected' (App verbunden) oder 'dismissed'.
    private const string HINT_SETTING       = 'webtreesand_hint';

    // Eine zweite App, die derselben Schnittstelle folgt (z. B. fuer iOS): der Verwalter traegt sie in den
    // Einstellungen ein, dann erscheint sie neben wtAnd auf der Seite "App" und beim Koppeln.
    // Leerer Name = keine zweite App. Das Schema ist der Teil vor "://" des Koppel-Links (wtAnd: "webtreesand").
    private const string APP2_NAME_SETTING    = 'app2_name';
    private const string APP2_ANDROID_SETTING = 'app2_android_url';
    private const string APP2_IOS_SETTING     = 'app2_ios_url';
    private const string APP2_SCHEME_SETTING  = 'app2_scheme';

    private const int PAGE_SIZE           = 50;
    private const int MEDIA_PAGE_SIZE     = 60;
    private const int PLACES_LIMIT        = 20;
    private const int MAX_PEDIGREE_GEN    = 7;
    private const int MAX_DESCENDANTS_GEN = 10;

    // Diese Tags sind Verknuepfungen oder Verwaltungsdaten, keine Ereignisse.
    // (HUSB/WIFE/CHIL sind die Verknuepfungen innerhalb eines Familien-Datensatzes.)
    private const array SKIP_FACTS = ['FAMS', 'FAMC', 'HUSB', 'WIFE', 'CHIL', 'OBJE', 'CHAN', '_UID', '_TODO', '_WT_OBJE_SORT'];

    private const array ADDABLE_TAGS = [
        'INDI' => ['BIRT', 'CHR', 'BAPM', 'CONF', 'DEAT', 'BURI', 'CREM', 'OCCU', 'RESI', 'EDUC', 'GRAD', 'RELI', 'NATI', 'TITL', 'EMIG', 'IMMI', 'NATU', 'CENS', 'RETI', 'EVEN', 'FACT', 'NOTE'],
        'FAM'  => ['MARR', 'ENGA', 'MARB', 'DIV', 'CENS', 'RESI', 'EVEN', 'NOTE'],
    ];

    public function __construct()
    {
        // Siehe Sammlungen-Modul: vom DI-Container erzeugte Instanzen haben sonst keinen Namen.
        // Derselbe Name, den webtrees vergibt: der Ordner, in dem das Modul liegt.
        $this->setName('_' . basename(__DIR__) . '_');
    }

    /**
     * Einstellungen aus der Zeit vor der Umbenennung (1.3.0) uebernehmen: webtrees legt sie unter dem Modulnamen ab,
     * und der hat sich mit dem Ordner geaendert. Ohne diesen Schritt staende nach dem Update wieder "alle Baeume"
     * und eine zweite App waere vergessen. Laeuft einmal; danach steht unter dem neuen Namen mindestens ein Eintrag.
     */
    private function takeOverOldSettings(): void
    {
        if ($this->name() === self::OLD_MODULE_NAME) {
            return;
        }

        // Ein Fehler hier darf nie die ganze webtrees-Seite lahmlegen - boot() laeuft bei jedem Aufruf.
        try {
            foreach (['module_setting', 'module_privacy'] as $table) {
                $exists = DB::table($table)->where('module_name', '=', $this->name())->exists();

                if (!$exists) {
                    $rows = DB::table($table)->where('module_name', '=', self::OLD_MODULE_NAME)->get();

                    foreach ($rows as $row) {
                        $values = (array) $row;
                        // module_privacy hat eine Auto-ID; die alte mitzukopieren gaebe einen doppelten Schluessel.
                        unset($values['id']);
                        DB::table($table)->insert(['module_name' => $this->name()] + $values);
                    }
                }
            }

            // Auch bei einer Neuinstallation: ab jetzt gibt es einen Eintrag, die Pruefung oben faellt kuenftig kurz aus.
            if ($this->getPreference('settings_from') === '') {
                $this->setPreference('settings_from', self::OLD_MODULE_NAME);
            }
        } catch (Throwable) {
            // Dann eben ohne die alten Einstellungen; der Verwalter setzt sie neu.
        }
    }

    public function title(): string
    {
        return 'api4webtrees';
    }

    public function description(): string
    {
        return I18N::translate(self::DESCRIPTION);
    }

    /**
     * Quelltext-Sprache ist Deutsch (wie im Sammlungen-Modul): fuer Deutsch gibt es nichts zu uebersetzen.
     *
     * Sonst wird resources/lang/<Sprache>.php genommen - erst die genaue Kennung ("nl-BE"), dann die Sprache
     * allein ("nl"), zuletzt Englisch. Englisch ist damit eine Datei unter vielen und zugleich der Rueckfall
     * fuer Sprachen, fuer die es (noch) keine Uebersetzung gibt.
     *
     * @return array<string,string>
     */
    public function customTranslations(string $language): array
    {
        if (str_starts_with($language, 'de')) {
            return [];
        }

        foreach ([$language, explode('-', $language)[0], 'en'] as $tag) {
            // Die Kennung kommt aus webtrees, nicht aus der Anfrage - der Vergleich haelt trotzdem alles
            // fern, was kein Sprachkuerzel ist ("../", absolute Pfade).
            if (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $tag) !== 1) {
                continue;
            }

            $file = __DIR__ . '/resources/lang/' . $tag . '.php';

            if (is_file($file)) {
                return require $file;
            }
        }

        return [];
    }

    public function customModuleAuthorName(): string
    {
        return 'Thomas Bugge';
    }

    public function customModuleVersion(): string
    {
        return '1.8.0';
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
        $this->takeOverOldSettings();
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    /**
     * Der Weg zur Seite "App" fuer angemeldete Benutzer in freigegebenen Baeumen: ein deutlicher Hinweis oben auf der
     * Seite, bis die App verbunden oder der Hinweis weggeklickt ist - danach nur noch der Link in der Fusszeile.
     * (Ein Menuepunkt waere fuer etwas, das man einmal braucht, zu viel.)
     */
    public function getFooter(ServerRequestInterface $request): string
    {
        $tree = Validator::attributes($request)->treeOptional();

        if ($tree === null || !Auth::check() || !$this->treeEnabled($tree)) {
            return '';
        }

        $action = (string) $request->getAttribute('action');

        return view($this->name() . '::footer', [
            'app_url'   => $this->actionUrl('App', $tree->name()),
            'hint'      => Auth::user()->getPreference(self::HINT_SETTING) === '' && $action !== 'App' && $action !== 'Connect',
            'hint_url'  => $this->actionUrl('HintOff', $tree->name()),
            'page_url'  => (string) $request->getUri(),
        ]);
    }

    /**
     * Darf die App diesen Stammbaum erreichen? Ohne gespeicherte Einstellung: ja (wie vor Version 0.8).
     */
    private function treeEnabled(Tree $tree): bool
    {
        $setting = $this->getPreference(self::TREES_SETTING, '*');

        return $setting === '*' || in_array($tree->name(), explode(',', $setting), true);
    }

    /**
     * Sprache der Antwort: ?lang=de (oder en-GB ...). Die App laeuft in der Sprache des Geraets, das
     * webtrees-Konto vielleicht in einer anderen - ohne diesen Schritt kaemen Beschriftungen ("Occupation")
     * und Datumsangaben in der Kontosprache und mischten sich mit den Texten der App.
     * Umgeschaltet wird nur fuer diese eine Antwort; Sitzung und Kontoeinstellung bleiben unberuehrt.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute('module') === $this->name()) {
            // Vom Verwalter nicht fuer die App freigegebene Baeume sind ueber dieses Modul gar nicht erreichbar -
            // unabhaengig davon, was das Konto in webtrees selbst duerfte.
            $tree = $request->getAttribute('tree');

            if ($tree instanceof Tree && !$this->treeEnabled($tree) && !str_contains(strtolower((string) $request->getAttribute('action')), 'admin')) {
                return $this->error(403, 'tree-disabled');
            }

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

    private function xref(ServerRequestInterface $request): string
    {
        return Validator::queryParams($request)->isXref()->string('xref');
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
