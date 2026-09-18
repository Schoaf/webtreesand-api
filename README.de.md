# WebtreesAnd API

[English](README.md) · **Deutsch**

Ein Modul für [webtrees](https://webtrees.net/), das der nativen Android-App **[webtreesAnd](https://github.com/thobgg/WebtreesAnd)** eine
JSON-Schnittstelle gibt – zum Lesen **und** Schreiben. Es ist ein gewöhnliches Zusatzmodul: Es liegt
in `modules_v4/`, der webtrees-Kern wird nicht verändert.

**Wozu:** Es bringt zwei Welten zusammen. Die Person, die den Stammbaum am PC akribisch pflegt, arbeitet in webtrees
weiter wie bisher. Die Familie, die bisher nur eine am Handy mühsam bedienbare Adresse bekam, bekommt den Stammbaum auf
Handy und Tablet: Wer war das, wie sind wir verwandt, wer hat bald Geburtstag, welche Fotos gibt es. Und der Rückweg ist
offen: Das Foto vom Grabstein oder das korrigierte Datum aus der App landet als ausstehende Änderung bei der Person am
PC, in derselben Installation, unter denselben Rechten und Regeln.

| | |
| - | - |
| webtrees | 2.2.x (mit 2.2.6 getestet); für 2.3 vorbereitet |
| PHP | 8.3 oder neuer (wie von webtrees 2.2 verlangt) |
| Zugriff | lesen und schreiben – immer mit den Rechten des angemeldeten webtrees-Benutzers |
| Lizenz | GPL-3.0 |

## Die App: webtreesAnd

Das Modul hat einen Zweck: **[webtreesAnd](https://github.com/thobgg/WebtreesAnd)**, eine native Android-App (Kotlin,
kein WebView) für Handy und Tablet. [APK herunterladen](https://github.com/thobgg/WebtreesAnd/releases/latest) – signiert;
außerhalb des Play Store fragt Android einmalig, ob der Browser Apps installieren darf.

| Tablet: Baum und Profil nebeneinander | Handy: das Profil als Zeitleiste |
| - | - |
| ![Baum und Profil nebeneinander](docs/app-tablet.png) | ![Profil am Handy](docs/app-handy.png) |

- **Baum als Mittelpunkt:** Sanduhr-Ansicht mit Ahnen, Partnern, Kindern und Enkeln, auf Wunsch mit Geschwistern;
  frei verschieben und zoomen, Zweige nach oben aufklappen, jede Person zur Mittelperson machen.
- **Profil:** Lebenslauf als Zeitleiste (mit Heirat und Geburten der Kinder), Verwandtschaft zur eigenen Person
  („Großvater väterlicherseits“), Fotos, Familie, Karte der Lebensstationen.
- **Bearbeiten:** Ereignisse anlegen, ändern, löschen, Verwandte über das „+“ an jeder Karte anlegen, Fotos aufnehmen
  oder auswählen und einer Person zuordnen – passend zum Upload-Limit des Servers verkleinert.
- **Jahrestage** der nächsten Tage, auf Wunsch mit täglicher Erinnerung; Moderatoren nehmen ausstehende Änderungen in der App an oder verwerfen sie.
- **Verbinden mit einem Tipp:** Die Seite „App“ in webtrees übergibt Server, Baum und einen Einmal-Code an die App; nichts eintippen.

Deutsch und Englisch; die Beschriftungen des Servers kommen in der Sprache der App. Was noch nicht nativ geht, öffnet
die App als webtrees-Seite in derselben Sitzung. Alle Bilder zeigen den frei erfundenen Demo-Stammbaum „Familie Falkenrath“.

## Installation

1. Das ZIP aus dem [neuesten Release](../../releases/latest) laden.
2. Nach `modules_v4/` der webtrees-Installation entpacken, sodass `modules_v4/webtreesand-api/module.php` entsteht.
3. Fertig – das Modul ist aktiv und steht unter *Verwaltung → Module → Alle Module*.

Aktualisieren: Ordner ersetzen. Entfernen: Ordner löschen. Das Modul legt keine Datenbanktabellen an. Es
speichert eine Moduleinstellung (welche Bäume die App erreichen darf) und je Benutzer nur den Hash eines Einmal-Codes, solange er gilt.

## Einstellungen

*Verwaltung → Module → Alle Module → WebtreesAnd API → Schraubenschlüssel* (Tipp: im Suchfeld der Modulliste „API"
eintippen). Die Seite bietet den App-Download, den Status (https, Upload-Limit) und den wichtigsten Schalter:
**welche Stammbäume die App erreichen darf.** Nicht angekreuzte Bäume sind über dieses Modul gar nicht erreichbar –
für keinen Benutzer, unabhängig von seinen Rechten in webtrees. Standard: alle Bäume.

**Weitere App (optional):** Eine zweite App, die derselben Schnittstelle folgt – etwa für iPhone und iPad – lässt sich
mit Name, Download-Adressen (nur https) und dem Schema ihres Verbinden-Links eintragen. Sie erscheint dann neben
webtreesAnd auf der Seite „App“ und beim Verbinden. Bleiben die Felder leer, ändert sich nichts.
Siehe [Andere Apps koppeln](#andere-apps-koppeln).

![Einstellungsseite: App installieren, Stammbäume für die App, weitere App, Status](docs/einstellungen.png)

## Für Familienmitglieder: die Seite „App"

Angemeldete Benutzer finden im Menü den Eintrag **App**. Die Seite bietet zwei Schritte:

1. **App installieren** – ein Knopf und ein QR-Code führen zum Download.
2. **Mit dem eigenen Konto verbinden** – ein Tipp (oder ein QR-Code, wenn man am Computer sitzt) übergibt der App
   Serveradresse, Stammbaum und einen Einmal-Code. Es gibt nichts einzutippen, und das Passwort erreicht das Handy nie.

Der Einmal-Code wird nur dem angemeldeten Benutzer gezeigt, gilt 10 Minuten und genau einmal und wird nur über https
angeboten. Gespeichert wird nur sein Hash. Verwalter können den Menüpunkt unter *Verwaltung → Module → Menüs*
verschieben oder abschalten.

## Datenschutz und Rechte

Das Modul hat bewusst keine eigene Anmeldung und keine eigene Rechteverwaltung:

- Die App meldet sich mit dem normalen webtrees-Login an; jede Anfrage läuft als dieser Benutzer.
- Gelesen wird ausschließlich über die webtrees-Objekte (`canShow()`, `facts()`, `children()` …). Es
  gelten dieselben Datenschutzregeln wie auf den Webseiten: Lebende Personen erscheinen für Besucher
  als „Privat", gesperrte Ereignisse fehlen, Bäume mit Anmeldepflicht bleiben unsichtbar.
- Geschrieben wird ausschließlich über die webtrees-eigenen Funktionen (`createFact`, `updateFact`,
  `createIndividual`, `createFamily`, `MediaFileService`). Bearbeiterrechte, `RESN locked`,
  Änderungsprotokoll und Moderation („ausstehende Änderungen") wirken damit genau wie in der
  Weboberfläche. Jeder POST läuft durch die CSRF-Prüfung von webtrees.

Änderungen aus der App stehen **sofort in der webtrees-Datenbank** – die App hält keine eigene Kopie
der Daten. Hat der Benutzer „Änderungen automatisch annehmen", sind sie sofort gültig; sonst warten
sie wie jede andere Bearbeitung auf die Freigabe durch einen Moderator.

## Ein Medienordner je Stammbaum

Nutzen verschiedene Personengruppen verschiedene Bäume, sollte jeder Baum einen **eigenen Medienordner**
haben (*Verwaltung → Stammbäume → Einstellungen → Medienordner*, z. B. `media/mueller/`). Das ist eine
Eigenheit von webtrees, nicht dieses Moduls: webtrees bietet Bearbeitern alle Dateien des Medienordners an,
die der Baum noch nicht verwendet („unbenutzte Dateien") – bei einem gemeinsamen Ordner sehen Bearbeiter des
einen Baums die Dateien des anderen und können sie verknüpfen.

## Für Entwickler

Die vollständige Beschreibung der Schnittstelle (Adressen, Anmeldung, alle Aktionen, Fehlercodes)
steht in der [englischen README](README.md#for-developers).

**Andere Apps koppeln:** Jeder Client kann die Schnittstelle mit der normalen Anmeldung benutzen. Eine zweite App kann
zusätzlich am Verbinden per Tipp teilnehmen, wenn ein Verwalter sie unter *Einstellungen → Weitere App* mit eigenem
URL-Schema einträgt. Der Vertrag ist der von webtreesAnd: Die Seiten „App“ und „Verbinden“ öffnen
`<schema>://connect?url=<Basisadresse>&code=<48 Hex>&tree=<Baumname>&user=<Benutzername>` in der App. Die App holt sich
mit `GET …/Info` Sitzungs-Cookie und `csrf`, dann `POST …/Pair` mit Header `X-CSRF-TOKEN` und Rumpf `{"code": "…"}`;
Antwort `{"ok":true,"tree":"…","user":"…"}`, die Sitzung ist jetzt als dieser Benutzer angemeldet. Der Code gilt
10 Minuten und genau einmal, egal welche App ihn einlöst, und wird nur über https angeboten. Sonst ist nichts im Modul
an eine App gebunden: JSON-Endpunkte, Rechte und Datenschutz sind für jeden Client gleich.

Einstieg in den Quelltext ist der Kopf von `WebtreesAndApiModule.php`: dort steht, welcher Teil des Moduls in
welcher Datei unter `src/` liegt.

Releases: `./build-release.sh` baut `webtreesand-api-vX.Y.Z.zip` aus dem letzten Commit.
`latest-version.txt` auf dem Hauptzweig speist den Update-Hinweis in der webtrees-Verwaltung.
