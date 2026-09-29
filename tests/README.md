# Tests

`python3 tests/test_api.py` baut ein frisches webtrees 2.2.6 mit SQLite unter `php -S` auf (nur 127.0.0.1),
spielt die Testbäume ein, legt je Rolle ein Konto an und prüft das Modul von außen wie eine App.

- **Lecktest:** Die Testdaten tragen Markierungswörter in allem, was eine Rolle nicht sehen darf. Jede Rolle ruft
  jede Leseroute auf, kein Wort darf in einer Antwort stehen.
- **Schreiben:** Rechte je Rolle, CSRF, ausstehende Änderungen, Sperrvermerke, Koppel-Code.

Braucht nur `php` (mit intl, pdo_sqlite, pdo_mysql, gd, zip) und Python 3. `WEBTREES_ZIP=…` nimmt eine vorhandene ZIP.
Läuft in GitHub Actions und vor jedem Release (`build-release.sh`).

## Leistungscheck

`python3 tests/leistung.py 10000 50000` erzeugt große Bäume (`grossbaum.py`, immer gleich), spielt sie ein und misst
jede Leseroute als Admin und Gast. Eine Messung, kein Test – nicht in GitHub Actions. Stand 27.09.2026 (webtrees 2.2.6,
PHP 8.5, SQLite): bei 50.000 Personen fast alle Routen unter 0,1 s, Volltextsuche 0,6 s, Export komplett 67 s
(240 Seiten); langsamste Route Anniversaries: 14 Tage 1,5 s, 60 Tage 6,3 s (seit 1.9.6 höchstens 100 Einträge, 45 KB).
Die Tabellen stehen in `docs/leistung.md`.
