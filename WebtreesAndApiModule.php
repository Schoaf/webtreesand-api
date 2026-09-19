<?php

declare(strict_types=1);

namespace WebtreesAnd\Api;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Http\RequestHandlers\ModuleAction;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleFooterInterface;
use Fisharebest\Webtrees\Module\ModuleFooterTrait;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
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
use function explode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function response;
use function str_contains;
use function str_starts_with;
use function strtolower;

/**
 * Einstieg des Moduls: Metadaten, Menue, Middleware (Baum-Freigabe, Sprache) und kleine Request-Helfer.
 *
 * Alle Endpunkte laufen ueber die eingebaute Modul-Route /module/_webtreesand-api_/<Action>[/<tree>]
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
class WebtreesAndApiModule extends AbstractModule implements ModuleCustomInterface, ModuleConfigInterface, ModuleMenuInterface, ModuleFooterInterface, MiddlewareInterface
{
    use ModuleCustomTrait;
    use ModuleMenuTrait;
    use ModuleFooterTrait;

    use AppPages;
    use ReadActions;
    use WriteActions;
    use JsonBuilders;


    public const string MODULE_NAME = '_webtreesand-api_';
    // 8: Places (Ortsvorschlaege), facts[].date.gedcom, media[].factId/primary, UnlinkMedia, PrimaryMedia, Link,
    //    AddIndividual.facts, Individuals?scope=all, Info.trees[].lastChange
    // 7: Verwalter legt fest, welche Stammbaeume die App erreicht (Fehler tree-disabled)
    // 6: Koppeln per Einmal-Code (Seiten App/Connect, Aktion Pair)
    // 5: Info.maxUpload, Moderation (Pending, Accept, Reject), trees[].canModerate/pending
    // 4: Anniversaries, DeleteRecord, Unlink; Ortskoordinaten auch aus der webtrees-Ortstabelle
    // 3: ?lang=<Sprache> fuer Beschriftungen und Datumsangaben der Antwort
    // 2: MediaList, Individual.relationship (relativeTo), Info.trees[].individuals, Pedigree.ancestors[].hasParents
    public const int    API_VERSION = 8;

    public const string DESCRIPTION = 'JSON-Schnittstelle für die native Android-App „webtreesAnd“ – liest und schreibt mit den Rechten des angemeldeten Benutzers.';

    // Eine Textdatei mit der neuesten Versionsnummer; webtrees zeigt damit in der Modulverwaltung einen Update-Hinweis.
    private const string LATEST_VERSION_URL = 'https://raw.githubusercontent.com/thobgg/webtreesand-api/main/latest-version.txt';
    private const string SUPPORT_URL        = 'https://github.com/thobgg/webtreesand-api';

    // Hier liegt die App zum Herunterladen (Seite "App" in webtrees).
    private const string APP_DOWNLOAD_URL   = 'https://github.com/thobgg/webtreesAnd/releases/latest';

    // Koppeln: der Einmal-Code gilt so viele Sekunden und genau einmal. Gespeichert wird nur sein Hash.
    private const int    PAIR_SECONDS       = 600;
    private const string PAIR_SETTING       = 'webtreesand_pair';

    // Moduleinstellung: fuer welche Stammbaeume die App freigegeben ist. '*' (Standard) = alle, sonst Namen mit Komma.
    private const string TREES_SETTING      = 'app_trees';

    // Moduleinstellung: Menuepunkt "App" im Hauptmenue zeigen. Standard aus - der Weg zur Seite "App" ist der Link
    // in der Fusszeile. (Das Haekchen unter Verwaltung -> Module -> Menues schaltet das GANZE Modul ab, auch die API.)
    private const string MENU_SETTING       = 'app_menu';

    // Eine zweite App, die derselben Schnittstelle folgt (z. B. fuer iOS): der Verwalter traegt sie in den
    // Einstellungen ein, dann erscheint sie neben webtreesAnd auf der Seite "App" und beim Koppeln.
    // Leerer Name = keine zweite App. Das Schema ist der Teil vor "://" des Koppel-Links (webtreesAnd: "webtreesand").
    private const string APP2_NAME_SETTING    = 'app2_name';
    private const string APP2_ANDROID_SETTING = 'app2_android_url';
    private const string APP2_IOS_SETTING     = 'app2_ios_url';
    private const string APP2_SCHEME_SETTING  = 'app2_scheme';

    private const int PAGE_SIZE           = 50;
    private const int MEDIA_PAGE_SIZE     = 60;
    private const int PLACES_LIMIT        = 20;
    private const int MAX_PEDIGREE_GEN    = 6;
    private const int MAX_DESCENDANTS_GEN = 4;

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

        return require __DIR__ . '/resources/lang/en.php';
    }

    public function customModuleAuthorName(): string
    {
        return 'Thomas Bugge';
    }

    public function customModuleVersion(): string
    {
        return '1.2.0';
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

    public function defaultMenuOrder(): int
    {
        return 99;
    }

    /**
     * Menuepunkt "App" - nur wenn in den Moduleinstellungen eingeschaltet und nur fuer angemeldete Benutzer,
     * denn dort wird das eigene Konto mit der App verbunden.
     */
    public function getMenu(Tree $tree): Menu|null
    {
        if ($this->getPreference(self::MENU_SETTING, '0') !== '1' || !Auth::check() || !$this->treeEnabled($tree)) {
            return null;
        }

        return new Menu(I18N::translate('App'), $this->actionUrl('App', $tree->name()), 'menu-webtreesand', ['rel' => 'nofollow']);
    }

    /**
     * Unauffaelliger Weg zur Seite "App": ein kleiner Link in der Fusszeile, nur fuer angemeldete Benutzer
     * in freigegebenen Baeumen.
     */
    public function getFooter(ServerRequestInterface $request): string
    {
        $tree = Validator::attributes($request)->treeOptional();

        if ($tree === null || !Auth::check() || !$this->treeEnabled($tree)) {
            return '';
        }

        return '<div class="wt-footer wt-footer-webtreesand text-center my-2 small"><a href="' . e($this->actionUrl('App', $tree->name())) . '" rel="nofollow">'
            . I18N::translate('App für Android') . '</a></div>';
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
        if ($request->getAttribute('module') === self::MODULE_NAME) {
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
