<?php

declare(strict_types=1);

namespace Api4Webtrees;

use function array_filter;
use function array_keys;
use function array_merge;
use function array_values;
use function implode;
use function in_array;
use function is_file;
use function preg_match;
use function str_starts_with;

/**
 * Alle Apps, die das Modul kennt: Seite "App", Verbinden-Seite, Hinweis und Fusszeile zeigen sie, der Verwalter
 * kann jede einzelne abschalten (Einstellungen). Die JSON-Schnittstelle selbst kennt keine App - jeder Client
 * benutzt sie mit der normalen Anmeldung.
 *
 * Eine neue App kommt per Pull Request hierher: ein Eintrag, sonst nichts. Bedingung: Die App ist oeffentlich
 * installierbar (Store oder Release), und sie nimmt den Koppel-Link <scheme>://connect?url=…&code=…&tree=…&user=…
 * an (siehe README, "Connecting other apps"). Fuehrt sie in einen Store, gehoert das Store-Badge dazu (resources/img).
 *
 * Felder je Eintrag:
 *   name      Anzeigename
 *   author    GitHub-Konto des Autors
 *   kind      phone (Handy und Tablet) oder pc (Programm fuer den Computer)
 *   devices   Geraete, fuer die es die App gibt: android, ios, windows, linux, mac
 *   scheme    Teil vor "://" des Koppel-Links; leer = kein Verbinden per Tipp, nur der Download
 *   download  Download-Adresse je Geraet (https)
 *   badge     Store-Badge je Geraet, Dateiname unter resources/img (leer = Textknopf)
 *   asset     nur pc: Muster des Dateinamens im GitHub-Release (die Seite "App" sucht die neueste Datei per API)
 *   always    auf jedem Geraet aufgeklappt zeigen (sonst nur auf den passenden, anderswo eingeklappt)
 *
 * Reihenfolge auf der Seite "App": Apps fuer das Geraet des Besuchers zuerst, dabei die eigenen (OWNER) vor
 * fremden; danach die uebrigen. Die Reihenfolge hier in der Liste bleibt innerhalb jeder Gruppe erhalten.
 */
final class Apps
{
    /** Eigene Apps stehen bei gleichem Geraet vorn - das Modul ist ihr Server-Teil. */
    public const string OWNER = 'thobgg';

    public const array DEVICES = ['android', 'ios', 'windows', 'linux', 'mac'];

    /** @var array<string,array<string,mixed>> */
    public const array ALL = [
        'wtand' => [
            'name'     => 'wtAnd',
            'author'   => 'thobgg',
            'kind'     => 'phone',
            'devices'  => ['android'],
            'scheme'   => 'webtreesand',
            'download' => ['android' => 'https://github.com/thobgg/app4webtrees/releases/latest'],
            'badge'    => [],
            'asset'    => '',
            'always'   => true,
        ],
        'wtwin' => [
            'name'     => 'wtWin',
            'author'   => 'thobgg',
            'kind'     => 'pc',
            'devices'  => ['windows'],
            'scheme'   => 'wtwin',
            'download' => ['windows' => 'https://github.com/thobgg/app4webtrees/releases/latest'],
            'badge'    => [],
            'asset'    => '\\.exe$',
            'always'   => true,
        ],
        'wttux' => [
            'name'     => 'wtTux',
            'author'   => 'thobgg',
            'kind'     => 'pc',
            'devices'  => ['linux'],
            'scheme'   => 'wttux',
            'download' => ['linux' => 'https://github.com/thobgg/app4webtrees/releases/latest'],
            'badge'    => [],
            'asset'    => '_amd64\\.deb$',
            'always'   => false,
        ],
        // Testfassung: baut wie wtWin/wtTux aus demselben Code, ist aber noch nicht auf einem echten Mac geprueft.
        'wtmac' => [
            'name'     => 'wtMac',
            'author'   => 'thobgg',
            'kind'     => 'pc',
            'devices'  => ['mac'],
            'scheme'   => 'wtmac',
            'download' => ['mac' => 'https://github.com/thobgg/app4webtrees/releases/latest'],
            'badge'    => [],
            'asset'    => '-arm64\\.dmg$',
            'always'   => false,
        ],
        'webtrees-mobile' => [
            'name'     => 'webtrees mobile',
            'author'   => 'Schoaf',
            'kind'     => 'phone',
            'devices'  => ['ios'],
            'scheme'   => 'webtreesmobile',
            'download' => ['ios' => 'https://apps.apple.com/app/id6815108154'],
            'badge'    => ['ios' => 'app-store.svg'],
            'asset'    => '',
            'always'   => false,
        ],
    ];

    /**
     * Alle Apps ausser den abgeschalteten, jede mit ihrer Kennung unter 'id'.
     *
     * @param list<string> $off Kennungen, die der Verwalter abgeschaltet hat
     *
     * @return list<array<string,mixed>>
     */
    public static function enabled(array $off): array
    {
        $apps = [];

        foreach (self::ALL as $id => $app) {
            if (!in_array($id, $off, true)) {
                $apps[] = ['id' => $id] + $app;
            }
        }

        return $apps;
    }

    /**
     * Fuer ein Geraet sortiert: passende Apps zuerst (eigene vor fremden), dann die uebrigen. 'matches' sagt je App,
     * ob sie zum Geraet passt, 'open', ob sie aufgeklappt gezeigt wird.
     *
     * @param list<array<string,mixed>> $apps
     *
     * @return list<array<string,mixed>>
     */
    public static function forDevice(array $apps, string $device): array
    {
        $groups = [[], [], [], []];

        foreach ($apps as $app) {
            $matches = in_array($device, $app['devices'], true);
            $own     = $app['author'] === self::OWNER;
            $app    += ['matches' => $matches, 'open' => $matches || $app['always']];

            $groups[($matches ? 0 : 2) + ($own ? 0 : 1)][] = $app;
        }

        return array_merge(...$groups);
    }

    /**
     * Die Apps einer Art (phone/pc), die zum Geraet passen - leer, wenn es fuer dieses Geraet nichts gibt.
     *
     * @param list<array<string,mixed>> $apps
     *
     * @return list<array<string,mixed>>
     */
    public static function matching(array $apps, string $device, string|null $kind = null): array
    {
        return array_values(array_filter(
            self::forDevice($apps, $device),
            static fn (array $app): bool => $app['matches'] && ($kind === null || $app['kind'] === $kind)
        ));
    }

    /**
     * Die Apps einer Art (phone/pc), in Listenreihenfolge.
     *
     * @param list<array<string,mixed>> $apps
     *
     * @return list<array<string,mixed>>
     */
    public static function kind(array $apps, string $kind): array
    {
        return array_values(array_filter($apps, static fn (array $app): bool => $app['kind'] === $kind));
    }

    /**
     * Download-Adresse fuer ein Geraet - sonst die erste, die es gibt.
     *
     * @param array<string,mixed> $app
     */
    public static function download(array $app, string $device): string
    {
        return $app['download'][$device] ?? array_values($app['download'])[0] ?? '';
    }

    /**
     * Prueft die Liste - fuer den Test (tests/test_api.py), damit ein Pull Request mit einem fehlerhaften Eintrag
     * nicht durchgeht. Liefert die Beanstandungen, leer = alles in Ordnung.
     *
     * @return list<string>
     */
    public static function check(): array
    {
        $errors  = [];
        $schemes = [];

        foreach (self::ALL as $id => $app) {
            if (preg_match('/^[a-z][a-z0-9-]{1,30}$/', $id) !== 1) {
                $errors[] = "$id: Kennung nur aus Kleinbuchstaben, Ziffern und -";
            }

            foreach (['name', 'author', 'kind', 'devices', 'scheme', 'download', 'badge', 'asset', 'always'] as $key) {
                if (!isset($app[$key])) {
                    $errors[] = "$id: Feld $key fehlt";
                }
            }

            if (array_keys($app) !== ['name', 'author', 'kind', 'devices', 'scheme', 'download', 'badge', 'asset', 'always']) {
                $errors[] = "$id: unbekannte oder fehlende Felder";
                continue;
            }

            if (!in_array($app['kind'], ['phone', 'pc'], true)) {
                $errors[] = "$id: kind muss phone oder pc sein";
            }

            if ($app['devices'] === [] || array_filter($app['devices'], static fn ($d): bool => !in_array($d, self::DEVICES, true)) !== []) {
                $errors[] = "$id: devices nur aus " . implode(', ', self::DEVICES);
            }

            if ($app['scheme'] !== '') {
                if (preg_match('/^[a-z][a-z0-9+.-]{1,30}$/', $app['scheme']) !== 1 || in_array($app['scheme'], ['http', 'https', 'javascript', 'data', 'file', 'intent'], true)) {
                    $errors[] = "$id: scheme ungueltig";
                }

                if (in_array($app['scheme'], $schemes, true)) {
                    $errors[] = "$id: scheme {$app['scheme']} schon vergeben";
                }

                $schemes[] = $app['scheme'];
            }

            if ($app['download'] === []) {
                $errors[] = "$id: mindestens eine Download-Adresse";
            }

            foreach ($app['download'] as $device => $url) {
                if (!in_array($device, $app['devices'], true)) {
                    $errors[] = "$id: Download fuer $device, aber $device steht nicht in devices";
                }

                if (!str_starts_with($url, 'https://')) {
                    $errors[] = "$id: Download fuer $device nicht https";
                }
            }

            foreach ($app['badge'] as $device => $file) {
                if (!isset($app['download'][$device])) {
                    $errors[] = "$id: Badge fuer $device ohne Download";
                }

                if (preg_match('/^[a-z0-9-]+\.(svg|png)$/', $file) !== 1 || !is_file(__DIR__ . '/../resources/img/' . $file)) {
                    $errors[] = "$id: Badge $file fehlt unter resources/img";
                }
            }
        }

        return $errors;
    }
}
