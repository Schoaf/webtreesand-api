# Tests

`python3 tests/test_api.py` baut ein frisches webtrees 2.2.6 mit SQLite unter `php -S` auf (nur 127.0.0.1),
spielt die Testbäume ein, legt je Rolle ein Konto an und prüft das Modul von außen wie eine App.

- **Lecktest:** Die Testdaten tragen Markierungswörter in allem, was eine Rolle nicht sehen darf. Jede Rolle ruft
  jede Leseroute auf, kein Wort darf in einer Antwort stehen.
- **Schreiben:** Rechte je Rolle, CSRF, ausstehende Änderungen, Sperrvermerke, Koppel-Code.

Braucht nur `php` (mit intl, pdo_sqlite, pdo_mysql, gd, zip) und Python 3. `WEBTREES_ZIP=…` nimmt eine vorhandene ZIP.
Läuft in GitHub Actions und vor jedem Release (`build-release.sh`).
