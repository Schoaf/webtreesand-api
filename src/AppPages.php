<?php

declare(strict_types=1);

namespace Api4Webtrees;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Fisharebest\Webtrees\Http\Exceptions\HttpTooManyRequestsException;
use Fisharebest\Webtrees\Services\RateLimitService;
use Fisharebest\Webtrees\Services\RegistrationService;
use Throwable;

use function bin2hex;
use function class_exists;
use function explode;
use function hash;
use function http_build_query;
use function implode;
use function in_array;
use function filter_var;
use function array_keys;
use function ini_get;
use function intdiv;
use function min;
use function parse_url;
use function preg_match;
use function random_bytes;
use function redirect;
use function response;
use function str_starts_with;
use function strtolower;
use function strtoupper;
use function substr;
use function time;
use function trim;
use function strlen;

use const PHP_URL_HOST;

/**
 * Seiten fuer Menschen: Einstellungen in der Verwaltung, die Seite "App" mit den QR-Codes und das Koppeln
 * per Einmal-Code. Alles, was ein Browser aufruft - die JSON-Endpunkte stehen in ReadActions und WriteActions.
 */
trait AppPages
{
    /**
     * Schraubenschluessel in der Modulliste. (Aktionen mit "Admin" im Namen laesst webtrees nur Administratoren ausfuehren.)
     */
    public function getConfigLink(): string
    {
        return $this->actionUrl('Admin', null);
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        $base_url = Validator::attributes($request)->string('base_url');
        $trees    = [];
        $off      = $this->appsOff();
        $apps     = [];

        foreach (Apps::ALL as $id => $app) {
            $apps[] = ['id' => $id, 'enabled' => !in_array($id, $off, true)] + $app;
        }

        // Der Download-Knopf mit QR-Code gilt der Handy-App des Modulautors.
        $download = Apps::download(Apps::ALL['wtand'], 'android');

        foreach (Registry::container()->get(TreeService::class)->all() as $tree) {
            $trees[] = [
                'name'    => $tree->name(),
                'title'   => $tree->title(),
                'enabled' => $this->treeEnabled($tree),
                'app_url' => $this->actionUrl('App', $tree->name()),
            ];
        }

        return $this->viewResponse($this->name() . '::admin', [
            'title'        => $this->title(),
            'trees'        => $trees,
            'save_url'     => $this->actionUrl('Admin', null),
            'download_url' => $download,
            'download_qr'  => $this->qrSvg($download),
            'apps'         => $apps,
            'icons'        => $this->iconUrls($apps),
            'device_names' => self::deviceNames(),
            'version'      => $this->customModuleVersion(),
            'api'          => self::API_VERSION,
            'https'        => str_starts_with($base_url, 'https://'),
            'home'         => self::homeNetworkUrl($base_url),
            'max_upload'   => $this->maxUploadBytes(),
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $chosen = Validator::parsedBody($request)->array('trees');
        $names  = [];

        foreach (Registry::container()->get(TreeService::class)->all() as $tree) {
            if (in_array($tree->name(), $chosen, true)) {
                $names[] = $tree->name();
            }
        }

        // '-' statt leer: eine leere Einstellung hiesse "nie gespeichert" und damit "alle".
        $this->setPreference(self::TREES_SETTING, $names === [] ? '-' : implode(',', $names));

        // Apps: angekreuzt = an. Gespeichert werden nur die abgeschalteten, damit eine neue App in der Liste von selbst an ist.
        $on  = Validator::parsedBody($request)->array('apps');
        $off = [];

        foreach (array_keys(Apps::ALL) as $id) {
            if (!in_array($id, $on, true)) {
                $off[] = $id;
            }
        }

        $this->setPreference(self::APPS_OFF_SETTING, implode(',', $off));

        FlashMessages::addMessage(I18N::translate('Die Einstellungen wurden gespeichert.'), 'success');

        return redirect($this->actionUrl('Admin', null));
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

        // Der Einmal-Code ist so gut wie ein Passwort - er darf nur verschluesselt reisen. Ausnahme: das Heimnetz
        // (etwa eine NAS, die nur per http:// erreichbar ist) - dieselbe Regel, nach der die Apps http:// zulassen.
        $home   = self::homeNetworkUrl($base_url);
        $secure = str_starts_with($base_url, 'https://') || $home;

        $device      = self::requestDevice($request);
        $apps        = Apps::forDevice($this->apps(), $device);
        $connect_url = '';
        $deep        = [];

        if (Auth::check() && $secure) {
            $code = bin2hex(random_bytes(self::PAIR_CODE_BYTES));
            $user->setPreference(self::PAIR_SETTING, hash('sha256', $code) . '|' . (time() + self::PAIR_SECONDS) . '|' . ($tree?->name() ?? ''));

            $params      = ['code' => $code, 'tree' => $tree?->name() ?? '', 'user' => $user->userName()];
            // Der Code reist im URL-Fragment (#...): das Fragment erreicht nie den Server und steht damit weder im
            // Zugriffsprotokoll des Webservers noch in dem eines Proxys. Die Verbinden-Seite liest es per JavaScript.
            $connect_url = $this->actionUrl('Connect', null) . '#' . http_build_query($params);

            // Derselbe Link je App mit ihrem Schema. Handy-Apps oeffnen ihn per Tipp; wtWin/wtTux bekommen ihn in die
            // Zwischenablage (das Programm liest sie, solange es auf eine Verbindung wartet) und zusaetzlich geoeffnet -
            // das Programm meldet sich dafuer beim ersten Start selbst als Empfaenger an.
            foreach ($apps as $app) {
                if ($app['scheme'] !== '') {
                    $deep[$app['id']] = $this->deepLink($app['scheme'], $base_url, $params);
                }
            }
        }

        $qr = [];

        // Der QR-Code wird mit dem Handy gescannt: bei einer App fuer Android und iOS zeigt er den Android-Download,
        // sonst den einzigen, den es gibt.
        foreach ($apps as $app) {
            if ($app['kind'] === 'phone') {
                $qr[$app['id']] = $this->qrSvg(Apps::download($app, 'android'));
            }
        }

        return $this->viewResponse($this->name() . '::app', [
            'title'        => I18N::translate('Dein Stammbaum am PC und auf dem Handy'),
            'device'       => $device,
            'base_url'     => $base_url,
            'tree'         => $tree,
            'logged_in'    => Auth::check(),
            'secure'       => $secure,
            'home'         => $home,
            'apps'         => $apps,
            'device_names' => self::deviceNames(),
            'badges'       => $this->badgeUrls($apps),
            'icons'        => $this->iconUrls($apps),
            'download_qr'  => $qr,
            'connect_url'  => $connect_url,
            'connect_qr'   => $connect_url === '' ? '' : $this->qrSvg($connect_url),
            'deep'         => $deep,
            'minutes'      => intdiv(self::PAIR_SECONDS, 60),
        ]);
    }

    /**
     * "Nicht mehr anzeigen" im Hinweis auf die App: gilt fuer diesen Benutzer, auf allen Geraeten derselben Art (Handy bzw. PC).
     */
    public function postHintOffAction(ServerRequestInterface $request): ResponseInterface
    {
        if (Auth::check()) {
            Auth::user()->setPreference(self::hintKey($request), self::HINT_DISMISSED);
        }

        return redirect(Validator::parsedBody($request)->isLocalUrl()->string('url', $this->actionUrl('App', null)));
    }

    /**
     * Zielseite des Verbinden-QR-Codes: wird im Browser des HANDYS geoeffnet (dort ist man meist nicht angemeldet)
     * und reicht nur an die App weiter. Kameras oeffnen verlaesslich nur https-Adressen, keine App-Links - daher dieser Umweg.
     * Code, Baum und Benutzer stehen im URL-Fragment und kommen nie beim Server an; die Seite baut den App-Link per JavaScript.
     */
    public function getConnectAction(ServerRequestInterface $request): ResponseInterface
    {
        $device = self::requestDevice($request);
        $apps   = Apps::forDevice(Apps::kind($this->apps(), 'phone'), $device);

        return $this->viewResponse($this->name() . '::connect', [
            'title'    => I18N::translate('Mit der App verbinden'),
            'tree'     => null,
            'base_url' => Validator::attributes($request)->string('base_url'),
            'device'   => $device,
            'apps'     => $apps,
            'badges'   => $this->badgeUrls($apps),
            'icons'    => $this->iconUrls($apps),
        ]);
    }

    /**
     * Die App loest den Einmal-Code ein: Rumpf { code }. Danach ist ihre Sitzung als dieser Benutzer angemeldet -
     * ohne dass ein Passwort das Geraet je gesehen hat.
     */
    public function postPairAction(ServerRequestInterface $request): ResponseInterface
    {
        $code = $this->str($this->body($request), 'code');

        if (preg_match('/^[0-9a-f]{' . (2 * self::PAIR_CODE_BYTES) . '}$/', $code) !== 1) {
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
        Log::addAuthenticationLog('Login (App, Einmal-Code): ' . $user->userName() . '/' . $user->realName());
        $user->setPreference(UserInterface::PREF_TIMESTAMP_ACTIVE, (string) time());
        $user->setPreference(self::hintKey($request), self::HINT_CONNECTED);

        return response(['ok' => true, 'tree' => $tree_name, 'user' => $user->userName()]);
    }

    /**
     * Heimnetz wie in den Apps (Heimnetz.kt): private, Loopback- und Link-Local-Adressen (IPv4 10/8, 172.16/12,
     * 192.168/16, 127/8, 169.254/16; IPv6 ::1, fc00::/7, fe80::/10), Namen ohne Punkt ("diskstation") und die
     * Endungen .local, .lan, .home, .home.arpa, .internal, .fritz.box, .box. Der Server loest keine Namen auf.
     */
    /** Laeuft diese Installation unverschluesselt im Heimnetz (etwa eine NAS unter http://)? */
    private static function homeNetworkUrl(string $base_url): bool
    {
        return !str_starts_with($base_url, 'https://') && self::homeNetwork((string) parse_url($base_url, PHP_URL_HOST));
    }

    public static function homeNetwork(string $host): bool
    {
        $host = strtolower(rtrim(trim($host, '[]'), '.'));

        if ($host === '') {
            return false;
        }

        $packed = filter_var($host, FILTER_VALIDATE_IP) !== false ? inet_pton($host) : false;

        if ($packed !== false) {
            $b = array_values(unpack('C*', $packed));

            if (count($b) === 4) {
                return $b[0] === 10 || $b[0] === 127 || ($b[0] === 172 && $b[1] >= 16 && $b[1] <= 31)
                    || ($b[0] === 192 && $b[1] === 168) || ($b[0] === 169 && $b[1] === 254);
            }

            return $packed === inet_pton('::1') || ($b[0] & 0xFE) === 0xFC || ($b[0] === 0xFE && ($b[1] & 0xC0) === 0x80);
        }

        if (!str_contains($host, '.') && !str_contains($host, ':')) {
            return true;
        }

        foreach (['.local', '.lan', '.home', '.home.arpa', '.internal', '.fritz.box', '.box'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Welches Geraet ruft die Seite auf? Nur fuer die Reihenfolge auf der Seite "App" - das Passende zuerst.
     * Android meldet sich auch als "Linux", darum zuerst. Mac (auch iPads im Desktop-Modus melden sich so) bekommt einen
     * eigenen Hinweis - fuer Apple gibt es noch kein Programm; ChromeOS und Unbekanntes die Handy-Ansicht.
     */
    public static function device(string $user_agent): string
    {
        $ua = strtolower($user_agent);

        return match (true) {
            str_contains($ua, 'android')                  => 'android',
            preg_match('/iphone|ipad|ipod/', $ua) === 1   => 'ios',
            str_contains($ua, 'macintosh')                => 'mac',
            str_contains($ua, 'windows')                  => 'windows',
            str_contains($ua, 'cros')                     => 'other',
            str_contains($ua, 'linux')                    => 'linux',
            default                                       => 'other',
        };
    }

    /**
     * Der Hinweis nach dem Anmelden merkt sich Handy und PC getrennt: wer wtAnd verbunden hat, soll trotzdem von wtWin
     * erfahren (und umgekehrt). Die Apps melden sich mit wtAnd/…, wtWin/… (Windows) bzw. wtTux/… (Linux).
     */
    private static function hintKey(ServerRequestInterface $request): string
    {
        return in_array(self::requestDevice($request), ['windows', 'linux'], true) ? self::HINT_DESK_SETTING : self::HINT_SETTING;
    }

    /** Das Geraet, von dem diese Anfrage kommt - siehe device(). */
    private static function requestDevice(ServerRequestInterface $request): string
    {
        return self::device($request->getHeaderLine('User-Agent'));
    }

    /**
     * @param array<string,string> $params
     */
    private function deepLink(string $scheme, string $base_url, array $params): string
    {
        return $scheme . '://connect?' . http_build_query(['url' => $base_url] + $params);
    }

    /**
     * Kennungen der Apps, die der Verwalter abgeschaltet hat.
     *
     * @return list<string>
     */
    private function appsOff(): array
    {
        $setting = trim($this->getPreference(self::APPS_OFF_SETTING));

        return $setting === '' ? [] : explode(',', $setting);
    }

    /**
     * Geraetenamen fuer die Seiten, in der Sprache des Benutzers.
     *
     * @return array<string,string>
     */
    private static function deviceNames(): array
    {
        return [
            'android' => I18N::translate('Android-Handy und -Tablet'),
            'ios'     => I18N::translate('iPhone und iPad'),
            'windows' => I18N::translate('Windows-PC'),
            'linux'   => I18N::translate('Linux-PC'),
            'mac'     => I18N::translate('Mac'),
        ];
    }

    /**
     * Adressen der Store-Badges je App und Geraet (Dateien unter resources/img).
     *
     * @param list<array<string,mixed>> $apps
     *
     * @return array<string,array<string,string>>
     */
    private function badgeUrls(array $apps): array
    {
        $urls = [];

        foreach ($apps as $app) {
            foreach ($app['badge'] as $device => $file) {
                $urls[$app['id']][$device] = $this->assetUrl('img/' . $file);
            }
        }

        return $urls;
    }

    /**
     * Adressen der App-Logos (Dateien unter resources/img), nur fuer Apps, die eines haben.
     *
     * @param list<array<string,mixed>> $apps
     *
     * @return array<string,string> App-Kennung => Adresse
     */
    private function iconUrls(array $apps): array
    {
        $urls = [];

        foreach ($apps as $app) {
            if ($app['icon'] !== '') {
                $urls[$app['id']] = $this->assetUrl('img/' . $app['icon']);
            }
        }

        return $urls;
    }

    /**
     * Die Apps, die dieses webtrees zeigt: alle aus src/Apps.php ausser den abgeschalteten.
     *
     * @return list<array<string,mixed>>
     */
    private function apps(): array
    {
        return Apps::enabled($this->appsOff());
    }

    /**
     * QR-Code als SVG. webtrees 2.2 bringt dafuer TCPDF mit, 2.3 tc-lib-barcode; fehlt beides, bleibt es beim Link.
     */
    private function qrSvg(string $data): string
    {
        try {
            // 5 Pixel je Modul (Kaestchen); tc-lib-barcode versteht negative Masse als Modulgroesse statt Gesamtgroesse.
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
     * Neues Konto anlegen - Rumpf { username, email, realName, password, comments }. Entspricht dem Webformular
     * (RegisterAction), weil das hier tatsaechlich derselbe Code ist: RegistrationService (webtrees-Kern) prueft
     * die Angaben und legt das Konto an - dasselbe Ergebnis wie im Webformular (sofort angelegt, aber erst nach
     * E-Mail-Bestaetigung UND Freischaltung durch einen Verwalter nutzbar), nur der Weg dorthin ist jetzt die App
     * statt der Browser (Apple-Vorgabe 5.1.1: Kontoerstellung muss in der App selbst moeglich sein). Das
     * Webformular bremst Bots zusaetzlich mit einem JavaScript-Zeittrick, der fuer eine native App wirkungslos
     * waere; hier greift stattdessen nur die serverweite Rate-Begrenzung, die das Webformular ohnehin schon hat.
     */
    public function postRegisterAction(ServerRequestInterface $request): ResponseInterface
    {
        $registration_service = Registry::container()->get(RegistrationService::class);

        if (!$registration_service->registrationAllowed()) {
            return $this->error(403, 'registration-disabled');
        }

        $tree = Validator::attributes($request)->treeOptional();
        $body = $this->body($request);

        $username = trim($this->str($body, 'username'));
        $email    = trim($this->str($body, 'email'));
        $realname = trim($this->str($body, 'realName'));
        $password = $this->str($body, 'password');
        $comments = trim($this->str($body, 'comments'));
        $base_url = Validator::attributes($request)->string('base_url');

        // RegistrationService selbst prueft keine Mindestlaenge - im Webformular macht das nur ein
        // HTML-Attribut (pattern=".{8,}"), das eine native App gar nicht erst durchlaeuft.
        if ($password !== '' && strlen($password) < 8) {
            return $this->error(400, 'weak-password');
        }

        $error = $registration_service->checkRegistrationDetails($username, $email, $realname, $comments, $password, $base_url);

        if ($error !== null) {
            return $this->error(400, match (true) {
                $error === I18N::translate('Duplicate username. A user with that username already exists. Please choose another username.') => 'username-taken',
                $error === I18N::translate('Duplicate email address. A user with that email already exists.') => 'email-taken',
                $error === I18N::translate('All fields must be completed.') => 'missing-fields',
                default => 'comments-link',
            });
        }

        try {
            Registry::container()->get(RateLimitService::class)->limitRateForSite(5, 300, 'rate-limit-registration');
        } catch (HttpTooManyRequestsException) {
            return $this->error(429, 'rate-limited');
        }

        Log::addAuthenticationLog('User registration requested for: ' . $username);

        $registration_service->register(
            $username,
            $email,
            $realname,
            $password,
            $comments,
            $base_url,
            $tree,
            Validator::attributes($request)->string('client-ip')
        );

        return response(['ok' => true]);
    }
}
