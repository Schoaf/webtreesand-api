<?php

declare(strict_types=1);

namespace Api4Webtrees;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\Exceptions\HttpTooManyRequestsException;
use Fisharebest\Webtrees\Http\RequestHandlers\VerifyEmail;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\NoReplyUser;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\EmailService;
use Fisharebest\Webtrees\Services\MessageService;
use Fisharebest\Webtrees\Services\RateLimitService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\SiteUser;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\TreeUser;
use Fisharebest\Webtrees\Validator;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function bin2hex;
use function class_exists;
use function date;
use function explode;
use function hash;
use function http_build_query;
use function implode;
use function in_array;
use function filter_var;
use function ini_get;
use function intdiv;
use function mb_substr;
use function min;
use function parse_url;
use function preg_match;
use function preg_quote;
use function random_bytes;
use function redirect;
use function response;
use function route;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;
use function time;
use function trim;
use function view;

use const FILTER_VALIDATE_URL;
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
            'download_url' => self::APP_DOWNLOAD_URL,
            'download_qr'  => $this->qrSvg(self::APP_DOWNLOAD_URL),
            'app2'         => $this->secondApp(),
            'app2_raw'     => [
                'name'    => $this->getPreference(self::APP2_NAME_SETTING),
                'android' => $this->getPreference(self::APP2_ANDROID_SETTING),
                'ios'     => $this->getPreference(self::APP2_IOS_SETTING),
                'scheme'  => $this->getPreference(self::APP2_SCHEME_SETTING),
            ],
            'version'      => $this->customModuleVersion(),
            'api'          => self::API_VERSION,
            'https'        => str_starts_with($base_url, 'https://'),
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

        // Zweite App: nur gueltige Werte werden gespeichert, alles andere wird verworfen und gemeldet.
        $body    = Validator::parsedBody($request);
        $name    = trim($body->string('app2_name', ''));
        $android = trim($body->string('app2_android_url', ''));
        $ios     = trim($body->string('app2_ios_url', ''));
        $scheme  = strtolower(trim($body->string('app2_scheme', '')));
        $rejected = [];

        // Download-Adressen: nur https und nur, was PHP als URL erkennt.
        $checkUrl = static function (string $url) use (&$rejected): string {
            if ($url === '' || (str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false)) {
                return $url;
            }

            $rejected[] = $url;

            return '';
        };
        $android = $checkUrl($android);
        $ios     = $checkUrl($ios);

        // Ein eigenes URL-Schema: Buchstaben, Ziffern, + . - ; nicht das von wtAnd und keins, das ein Browser selbst versteht.
        if ($scheme !== '' && (preg_match('/^[a-z][a-z0-9+.-]{1,30}$/', $scheme) !== 1 || in_array($scheme, ['webtreesand', 'http', 'https', 'javascript', 'data', 'file', 'intent'], true))) {
            $rejected[] = $scheme;
            $scheme     = '';
        }

        $this->setPreference(self::APP2_NAME_SETTING, mb_substr($name, 0, 60));
        $this->setPreference(self::APP2_ANDROID_SETTING, $android);
        $this->setPreference(self::APP2_IOS_SETTING, $ios);
        $this->setPreference(self::APP2_SCHEME_SETTING, $scheme);

        if ($rejected !== []) {
            FlashMessages::addMessage(I18N::translate('Nicht übernommen (nur https-Adressen und ein einfaches Schema wie „meineapp“ sind erlaubt): %s', implode(', ', $rejected)), 'warning');
        }

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
        $host     = (string) parse_url($base_url, PHP_URL_HOST);

        // Der Einmal-Code ist so gut wie ein Passwort - er darf nur verschluesselt reisen (Ausnahme: der eigene Rechner).
        $secure = str_starts_with($base_url, 'https://') || in_array($host, ['localhost', '127.0.0.1', '::1'], true);

        $connect_url = '';
        $deep_link   = '';
        $deep_link2  = '';
        $app2        = $this->secondApp();

        if (Auth::check() && $secure) {
            $code = bin2hex(random_bytes(24));
            $user->setPreference(self::PAIR_SETTING, hash('sha256', $code) . '|' . (time() + self::PAIR_SECONDS) . '|' . ($tree?->name() ?? ''));

            $params      = ['code' => $code, 'tree' => $tree?->name() ?? '', 'user' => $user->userName()];
            // Der Code reist im URL-Fragment (#...): das Fragment erreicht nie den Server und steht damit weder im
            // Zugriffsprotokoll des Webservers noch in dem eines Proxys. Die Verbinden-Seite liest es per JavaScript.
            $connect_url = $this->actionUrl('Connect', null) . '#' . http_build_query($params);
            $deep_link   = $this->deepLink('webtreesand', $base_url, $params);
            $deep_link2  = $app2 !== null && $app2['scheme'] !== '' ? $this->deepLink($app2['scheme'], $base_url, $params) : '';
        }

        return $this->viewResponse($this->name() . '::app', [
            'title'        => I18N::translate('wtAnd – die App für diesen Stammbaum'),
            'tree'         => $tree,
            'logged_in'    => Auth::check(),
            'secure'       => $secure,
            'download_url' => self::APP_DOWNLOAD_URL,
            'download_qr'  => $this->qrSvg(self::APP_DOWNLOAD_URL),
            'connect_url'  => $connect_url,
            'connect_qr'   => $connect_url === '' ? '' : $this->qrSvg($connect_url),
            'deep_link'    => $deep_link,
            'app2'         => $app2,
            'app2_qr'      => $app2 === null ? '' : $this->qrSvg($app2['android'] !== '' ? $app2['android'] : $app2['ios']),
            'deep_link2'   => $deep_link2,
            'minutes'      => intdiv(self::PAIR_SECONDS, 60),
        ]);
    }

    /**
     * "Nicht mehr anzeigen" im Hinweis auf die App: gilt fuer diesen Benutzer, auf allen Geraeten.
     */
    public function postHintOffAction(ServerRequestInterface $request): ResponseInterface
    {
        if (Auth::check()) {
            Auth::user()->setPreference(self::HINT_SETTING, 'dismissed');
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
        return $this->viewResponse($this->name() . '::connect', [
            'title'        => I18N::translate('Mit wtAnd verbinden'),
            'tree'         => null,
            'base_url'     => Validator::attributes($request)->string('base_url'),
            'download_url' => self::APP_DOWNLOAD_URL,
            'app2'         => $this->secondApp(),
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
        Log::addAuthenticationLog('Login (wtAnd, QR-Code): ' . $user->userName() . '/' . $user->realName());
        $user->setPreference(UserInterface::PREF_TIMESTAMP_ACTIVE, (string) time());
        $user->setPreference(self::HINT_SETTING, 'connected');

        return response(['ok' => true, 'tree' => $tree_name, 'user' => $user->userName()]);
    }

    /**
     * Neues Konto anlegen - Rumpf { username, email, realName, password, comments }. Entspricht inhaltlich dem
     * Webformular (RegisterAction): das Konto entsteht sofort, ist aber erst nach E-Mail-Bestaetigung UND
     * Freischaltung durch einen Verwalter nutzbar - nur der Weg dorthin ist jetzt die App statt des Browsers
     * (Apple-Vorgabe 5.1.1: Kontoerstellung muss in der App selbst moeglich sein). Das Webformular bremst Bots
     * mit einem JavaScript-Zeittrick, der fuer eine native App wirkungslos waere; hier greift stattdessen nur
     * die serverweite Rate-Begrenzung, die das Webformular ohnehin zusaetzlich hat.
     */
    public function postRegisterAction(ServerRequestInterface $request): ResponseInterface
    {
        if (Site::getPreference('USE_REGISTRATION_MODULE') !== '1') {
            return $this->error(403, 'registration-disabled');
        }

        $tree = Validator::attributes($request)->treeOptional();
        $body = $this->body($request);

        $username = trim($this->str($body, 'username'));
        $email    = trim($this->str($body, 'email'));
        $realname = trim($this->str($body, 'realName'));
        $password = $this->str($body, 'password');
        $comments = trim($this->str($body, 'comments'));

        if ($username === '' || $email === '' || $realname === '' || $comments === '' || $password === '') {
            return $this->error(400, 'missing-fields');
        }

        if (strlen($password) < 8) {
            return $this->error(400, 'weak-password');
        }

        $user_service = Registry::container()->get(UserService::class);

        if ($user_service->findByUserName($username) !== null) {
            return $this->error(400, 'username-taken');
        }

        if ($user_service->findByEmail($email) !== null) {
            return $this->error(400, 'email-taken');
        }

        $base_url = Validator::attributes($request)->string('base_url');

        // Kein Spam mit fremden Links im Kommentarfeld - wie im Webformular.
        if (preg_match('/(?!' . preg_quote($base_url, '/') . ')(((?:http|https):\/\/)[a-zA-Z0-9.-]+)/', $comments) === 1) {
            return $this->error(400, 'comments-link');
        }

        try {
            Registry::container()->get(RateLimitService::class)->limitRateForSite(5, 300, 'rate-limit-registration');
        } catch (HttpTooManyRequestsException) {
            return $this->error(429, 'rate-limited');
        }

        Log::addAuthenticationLog('User registration requested for: ' . $username);

        $user  = $user_service->create($username, $realname, $email, $password);
        $token = Str::random(32);

        $user->setPreference(UserInterface::PREF_LANGUAGE, I18N::languageTag());
        $user->setPreference(UserInterface::PREF_TIME_ZONE, Site::getPreference('TIMEZONE'));
        $user->setPreference(UserInterface::PREF_IS_EMAIL_VERIFIED, '');
        $user->setPreference(UserInterface::PREF_IS_ACCOUNT_APPROVED, '');
        $user->setPreference(UserInterface::PREF_TIMESTAMP_REGISTERED, date('U'));
        $user->setPreference(UserInterface::PREF_VERIFICATION_TOKEN, $token);
        $user->setPreference(UserInterface::PREF_CONTACT_METHOD, MessageService::CONTACT_METHOD_INTERNAL_AND_EMAIL);
        $user->setPreference(UserInterface::PREF_NEW_ACCOUNT_COMMENT, $comments);
        $user->setPreference(UserInterface::PREF_IS_VISIBLE_ONLINE, '1');
        $user->setPreference(UserInterface::PREF_AUTO_ACCEPT_EDITS, '');
        $user->setPreference(UserInterface::PREF_IS_ADMINISTRATOR, '');
        $user->setPreference(UserInterface::PREF_TIMESTAMP_ACTIVE, '0');

        $reply_to      = $tree instanceof Tree ? new TreeUser($tree) : new SiteUser();
        $email_service = Registry::container()->get(EmailService::class);

        $verify_url = route(VerifyEmail::class, [
            'username' => $user->userName(),
            'token'    => $token,
            'tree'     => $tree?->name(),
        ]);

        /* I18N: %s is a server name/URL */
        $email_service->send(
            new SiteUser(),
            $user,
            $reply_to,
            I18N::translate('Your registration at %s', $base_url),
            view('emails/register-user-text', ['user' => $user, 'base_url' => $base_url, 'verify_url' => $verify_url]),
            view('emails/register-user-html', ['user' => $user, 'base_url' => $base_url, 'verify_url' => $verify_url])
        );

        foreach ($user_service->administrators() as $administrator) {
            I18N::init($administrator->getPreference(UserInterface::PREF_LANGUAGE, 'en-US'));

            /* I18N: %s is a server name/URL */
            $subject = I18N::translate('New registration at %s', $base_url);

            $body_text = view('emails/register-notify-text', [
                'user'     => $user,
                'comments' => $comments,
                'base_url' => $base_url,
                'tree'     => $tree,
            ]);

            $body_html = view('emails/register-notify-html', [
                'user'     => $user,
                'comments' => $comments,
                'base_url' => $base_url,
                'tree'     => $tree,
            ]);

            $email_service->send(
                new SiteUser(),
                $administrator,
                new NoReplyUser(),
                $subject,
                $body_text,
                $body_html
            );

            $mail1_method = $administrator->getPreference(UserInterface::PREF_CONTACT_METHOD);
            if (
                $mail1_method !== MessageService::CONTACT_METHOD_EMAIL &&
                $mail1_method !== MessageService::CONTACT_METHOD_MAILTO &&
                $mail1_method !== MessageService::CONTACT_METHOD_NONE
            ) {
                DB::table('message')->insert([
                    'sender'     => $user->email(),
                    'ip_address' => $request->getAttribute('client-ip'),
                    'user_id'    => $administrator->id(),
                    'subject'    => $subject,
                    'body'       => $body_text,
                ]);
            }
        }

        return response(['ok' => true]);
    }

    /**
     * @param array<string,string> $params
     */
    private function deepLink(string $scheme, string $base_url, array $params): string
    {
        return $scheme . '://connect?' . http_build_query(['url' => $base_url] + $params);
    }

    /**
     * Die vom Verwalter eingetragene zweite App - oder null, wenn keine eingetragen ist.
     * Sie folgt derselben Schnittstelle und demselben Koppel-Link, nur mit eigenem Schema.
     *
     * @return array{name:string,android:string,ios:string,scheme:string}|null
     */
    private function secondApp(): ?array
    {
        $name = trim($this->getPreference(self::APP2_NAME_SETTING));

        if ($name === '') {
            return null;
        }

        return [
            'name'    => $name,
            'android' => $this->getPreference(self::APP2_ANDROID_SETTING),
            'ios'     => $this->getPreference(self::APP2_IOS_SETTING),
            'scheme'  => $this->getPreference(self::APP2_SCHEME_SETTING),
        ];
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
}
