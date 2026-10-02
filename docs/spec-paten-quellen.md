# Spec: Paten, Trauzeugen, Heiratsart und Quellen-Lücken (api4webtrees 1.11)

Stand 01.10.2026. **Umgesetzt in 1.11.0 (Stufe 19): Abschnitt 1, 3 (nur Lesen), 5 (Lese-Seite), 6, 7, 8. In 1.12.0
(Stufe 20): Abschnitt 2 (POST Association, freie Paten als `_GODP`/`_WITN` statt Notiz) und `Fact.type`.** Offen: Quellen-Schreibteil
(Abschnitt 4, 5 Schreiben). Abweichungen beim Umsetzen: `1 ASSO` bleibt als Fakt `ASSO` in `facts[]` stehen (mit
`associates`), zusätzlich zur Taufe – nichts fällt für ältere Apps weg. `type` liefert wie bisher webtrees' kanonische
Form (`CIVIL`, `RELIGIOUS`), leer ohne TYPE; nur `typeLabel` ist neu. Gäste sehen lebende Paten gar nicht (nicht als
`private: true`), weil webtrees ihnen bei der Voreinstellung „Namen Lebender: Mitglieder“ nicht einmal den Namen zeigt
(`canShowName()`); Mitglieder sehen Vertrauliche als `private: true` ohne Namen. `noteKinds[]` parallel zu `notes[]`
(offene Frage 1). `associatedIn[].name` einer Familie ist `Family::fullName()` („Mann + Frau“) wie in `Family.name`.
Ursprünglich: nur Spec – kein Code. Gegenstück für die Apps: `app4webtrees/docs/paten-quellen-konzept.md`.
Testdaten: Demo-Stammbaum Falkenrath **Version 1.2** (`app4webtrees/demo-tree/falkenrath.ged`, Fundstellen in dessen
README). Maßstab ist **webtrees 2.2.6** – die API liefert, was webtrees speichert, und schreibt, was webtrees selbst
schreiben würde.

Vorschlag: Modul **1.11.0**, `API_VERSION = 19`. Alles hier ist **additiv** – bestehende Felder ändern sich nicht,
ältere Apps laufen weiter.

## Ausgangslage (Bestandsaufnahme 01.10.2026)

| Thema | Heute | Lücke |
| - | - | - |
| `2 _ASSO` in Ereignissen | unsichtbar | Paten/Zeugen fehlen in JSON |
| `1 ASSO` an der Person | erscheint als Fakt, `RELA` geht verloren | Rolle und Zuordnung fehlen |
| „Pate bei …“ (Gegenrichtung) | gibt es nicht | webtrees zeigt es, die API nicht |
| `2 NOTE Paten: …` | als normale Notiz | nicht als Personenliste |
| `MARR:TYPE` | roher Wert (`civil`) in `type` | keine Übersetzung, nicht schreibbar |
| SOUR `DATA` (EVEN/DATE/PLAC/AGNC) | fehlt | |
| `MEDI` unter `CALN` | fehlt; geht beim Schreiben der Quelle **verloren** | Datenverlust |
| Archiv: PHON, EMAIL, WWW, NOTE, Adressteile | fehlt | |
| Zitat `EVEN`/`ROLE` | fehlt (bleibt beim Schreiben erhalten) | |
| `NOTE`/`TEXT` mit `CONC` | nur `CONT` wird gelesen | Text abgeschnitten, beim Ersetzen verwaiste CONC-Zeilen |

## 1. Paten und Trauzeugen lesen

### 1.1 Formen im GEDCOM

| Form | Herkunft | Behandlung |
| - | - | - |
| `2 _ASSO @I…@` + `3 RELA godparent` in `CHR`/`BAPM` | webtrees 2.2, Vereinbarung 2011 (GEDCOM-L) | Hauptform |
| `2 _ASSO` + `3 RELA witness` in `MARR` | dto. | Hauptform |
| `3 RELA godfather` / `godmother` / `Godparent` / `Pate` … | ältere Daten, Importe | lesen wie Hauptform |
| `1 ASSO @I…@` + `2 RELA Godfather` an der Person | GEDCOM 5.5.1, ältere Exporte | der Taufe zuordnen (s. 1.3) |
| `2 NOTE Paten: A, Beruf zu Ort; B, …` | Personen ohne eigenen Datensatz | als freie Einträge (s. 1.4) |
| `2 NOTE Trauzeugen: …` | dto. | dto. |
| `2 _GODP Friedrich Plate, Anbauer zu Celle` unter `CHR`/`BAPM`, je Pate eine Zeile | GEDCOM-L; webtrees kennt es (`CustomTags/GedcomL.php`), Demo-Baum ab 1.3 | als freier Eintrag, `name` bis zum ersten Komma, `detail` der Rest; Zeile mit `;` wie eine Notiz-Liste (ab 1.11.0) |
| `2 _WITN …` unter `MARR`, je Zeuge eine Zeile | dto. | dto., `role` witness |

### 1.2 Neues Feld `associates[]` je Fakt

```json
"associates": [
  {
    "xref": "I10",
    "name": "Ute Falkenrath",
    "sex": "F",
    "rela": "godparent",
    "role": "godparent",
    "label": "Patin",
    "private": false,
    "level1": false,
    "notes": ["Schwester des Vaters"],
    "sources": [ /* citationJson wie bei Fakten */ ]
  }
]
```

- **`rela`** = Rohwert aus der Datei, unverändert (für Prüfungen und Testfälle).
- **`role`** = normalisiert, **ohne Rücksicht auf Groß-/Kleinschreibung**:
  - `godparent`: godparent, godfather, godmother, pate, patin, taufpate, taufpatin, gevatter
  - `witness`: witness, trauzeuge, trauzeugin, zeuge, zeugin
  - sonst `other`
- **`label`** = Übersetzung über webtrees: `RelationIsDescriptor::values($sex)` mit dem Geschlecht der
  **verknüpften** Person (so liefert webtrees „Pate“/„Patin“ für `godparent`); bei unbekanntem Wert der Rohwert.
- **`private`**: Darf der Betrachter die verknüpfte Person nicht sehen (`canShow()`), dann `name: null`,
  `sex: null`, `private: true`, `xref` bleibt (wie bei verborgenen Kindern) – **kein Name darf durchsickern**.
  Ist die Person nicht einmal als Verweis zeigbar (`canShowName()` false), Eintrag ganz weglassen.
- **`notes`**, **`sources`**: Unterstrukturen von `_ASSO` (`3 NOTE`, `3 SOUR`), gleiche Form wie bei Fakten.

### 1.3 `1 ASSO` an der Person

- Hat die Person eine Taufe (`CHR`, sonst `BAPM`) und ist `role` = `godparent`, dann erscheint der Eintrag in
  `associates` **dieser Taufe** mit `level1: true`.
- Sonst (andere Rolle, keine Taufe) bleibt er ein eigener Fakt `ASSO` – neu mit `associates` (ein Eintrag), damit
  `RELA` nicht verloren geht.
- Die Datei wird beim Lesen **nicht** umgeschrieben.

### 1.4 Freie Einträge `freeAssociates[]`

Aus Notizen am Fakt, deren Text mit `Paten:`, `Taufpaten:`, `Gevattern:`, `Trauzeugen:` oder `Zeugen:` beginnt
(ohne Rücksicht auf Groß-/Kleinschreibung):

```json
"freeAssociates": [
  { "role": "godparent", "name": "Friedrich Plate", "detail": "Anbauer zu Celle", "text": "Friedrich Plate, Anbauer zu Celle" }
]
```

- Personen sind durch `;` getrennt; `name` = Text bis zum ersten Komma, `detail` = Rest.
- **Alte Schreibweise ohne `;`** (nur Kommas): ein einziger Eintrag mit `name: null` und dem ganzen `text` – nicht
  raten.
- Die Notiz bleibt zusätzlich in `notes[]`, bekommt dort aber `"kind": "associates"`, damit die Apps sie nicht
  doppelt zeigen. (Heute ist `notes` ein Array von Strings – daher besser ein neues Feld `noteKinds[]` parallel zu
  `notes[]`, um nichts zu brechen.)

### 1.5 Gegenrichtung `associatedIn[]` an der Person

`GET Individual` liefert zusätzlich, wo die Person Pate oder Zeuge ist – dieselbe Logik wie webtrees
`IndividualFactsService` (verknüpfte Personen und Familien über `ASSO` und `_ASSO`):

```json
"associatedIn": [
  {
    "record": "I21", "recordType": "INDI", "name": "Heinrich Falkenrath",
    "tag": "CHR", "label": "Taufe", "factId": "…",
    "date": { "text": "1. Mai 1897", "year": 1897 }, "place": { "short": "Celle" },
    "rela": "godparent", "role": "godparent", "label2": "Pate"
  }
]
```

- Für Familien (`MARR`) `recordType: "FAM"` und `name` = „Mann & Frau“.
- **Nur sichtbare** Datensätze und Fakten (`canShow()` auf Datensatz und Fakt); verborgene fallen weg, ohne Hinweis.
- Auch `1 ASSO` zählt (webtrees zeigt die heute **nicht** beim Paten – die API soll es tun).
- Sortiert nach Datum.

## 2. Paten und Trauzeugen schreiben

Neue Route **`POST Association`** (Moderation und Änderungsprotokoll wie `POST Fact`):

```json
{ "xref": "I21", "factId": "…", "action": "add|change|delete|move",
  "index": 0, "moveTo": 1,
  "associate": "I62", "role": "godparent", "note": "Bruder der Mutter" }
```

- Schreibt immer die **webtrees-Form**: `2 _ASSO @I62@` + `3 RELA godparent` bzw. `witness` – **klein**.
- `change` ersetzt nur `associate`/`RELA`/`NOTE`; weitere Unterzeilen (z. B. `3 SOUR`) bleiben.
- Freie Einträge: `{ "xref", "factId", "free": ["Friedrich Plate, Anbauer zu Celle", …], "role": "godparent" }`
  schreibt/ersetzt **die eine** Notiz `Paten: A; B` (bzw. `Trauzeugen:`); leere Liste löscht sie.
- Optional `"convertLevel1": true`: verschiebt `1 ASSO`-Paten der Person in die Taufe (wie
  `falkenrath/werkzeuge/paten_umsetzen.py`) – erst nach Rückfrage in der App.
- Antwort `{ factId }` (neu, da sich die Fakt-ID ändert).

`POST Fact` muss `_ASSO` samt Unterzeilen weiter unverändert lassen (heute so – mit Test absichern).

## 3. Heiratsart (`MARR:TYPE`)

- Lesen: neues Feld **`typeLabel`** über das webtrees-Element `FAM:MARR:TYPE` (`MarriageType::values()`):
  civil → „Standesamtliche Heirat“, religious → „Kirchliche Trauung“ (je nach Sprache), common law, partners; sonst
  Rohwert. `type` bleibt roh.
- Schreiben: `FactRequest` bekommt **`type`** (leer = Zeile entfernen). Nur die `2 TYPE`-Zeile wird ersetzt.
- Mehrere `MARR` je Familie sind normal (Standesamt **und** Kirche); jede hat ihre eigene `factId`.

## 4. Quellen-Lücken schließen

| Feld | Route | JSON |
| - | - | - |
| SOUR `DATA` | `GET Source`, `Sources` | `data: { events: [{ types: "BIRT, CHR", date: "FROM 1812 TO 1845", place: "Eschede, …" }], agency: "…", notes: [] }` |
| `MEDI` | `GET Source` | in `repositories[]`: `medium: "book"` |
| alle `TEXT` | `GET Source` | `texts: [...]` (bisheriges `text` = erster bleibt) |
| Archiv-Details | `GET Repositories`, neu `GET Repository` | `address: { lines, adr1, city, post, country }`, `phone`, `email`, `www`, `notes` |
| Zitat `EVEN`/`ROLE` | alle Zitate | `event: "CHR"`, `eventLabel: "Taufe"`, `role: "(Pate)"` |

Schreiben:
- `POST Source`: **`MEDI` beim Ersetzen von REPO/CALN erhalten**; neues Feld `medium`. (Bugfix, Datenverlust.)
- `POST Citation`: Felder `event`, `role`; Ändern von `date` lässt alle `TEXT` stehen. (Bugfix.)
- `POST Repository`: `phone`, `email`, `www`, `address`, `note`.

## 5. CONT/CONC

`NOTE` und `TEXT` überall nach GEDCOM 5.5.1 lesen: `CONT` = Zeilenumbruch, `CONC` = ohne Leerzeichen anhängen.
Beim Ersetzen einer Notiz **alle** Fortsetzungszeilen mit entfernen. Prüfen, ob webtrees beim Import `CONC` schon
zusammenfügt (Demo-Baum: Abschriften bei I276, F8) – dann reicht die Lese-Seite für fremde Altdaten.

## 6. Datenschutz

- Lecktest (`tests/test_api.py`, Klasse `Lecktest`) erweitern: Namen privater Paten in `associates`, `associatedIn`
  auf private Datensätze, freie Paten an Fakten privater Personen – für Gast, Mitglied, Redakteur.
- Fixture: I1 (lebend) mit lebender Patin I10.

## 7. Tests

- `tests/testbaum.ged` um je einen Fall jeder Form aus 1.1 ergänzen, plus `_ASSO` mit `NOTE`/`SOUR`, privater Pate,
  freie Paten alt (nur Kommas) und neu (`;`).
- Gegenprobe mit dem Demo-Baum 1.2:

| Fall | Fundstelle | Erwartung |
| - | - | - |
| nur verlinkt | I22 CHR | 2 × `associates`, `role` godparent |
| nur Text | I52 CHR | 2 × `freeAssociates` (Eggers, Lüders) |
| gemischt | I21 CHR | 2 verlinkt + 1 frei; I65 mit `notes` und `sources` |
| lebende Patin | I1 CHR | als Gast: `private: true`, kein Name |
| `godfather`/`godmother` | I38, I41 | `role` godparent, `label` Pate/Patin |
| `Godparent` groß | I57 | `role` godparent, `rela` "Godparent" |
| `1 ASSO` | I58 | in `associates` der Taufe, `level1: true` |
| Gegenrichtung | I377 | `associatedIn` mit allen Taufen |
| Trauzeugen gemischt | F3, F6 | `associates` witness + `freeAssociates` |
| zwei Heiraten | F8 | 2 × MARR, `typeLabel` standesamtlich/kirchlich |
| Heirat ohne TYPE | F10 | `type` null |
| Zitat EVEN/ROLE | I62 (Zitat an der Person) | `event` CHR, `role` (Patin) |
| SOUR DATA/AGNC | S8 | `data.agency` |
| zwei Archive, MEDI | S5 | 2 × `repositories`, `medium` book/electronic |
| Archiv voll | R5 | phone, email, www |

## 8. Doku

`docs/API.md` und `docs/openapi.json` neu erzeugen (`tests/manifest.py`) – stehen noch auf 1.9.6. CHANGELOG 1.11.0.

## Offene Fragen

1. `noteKinds[]` parallel zu `notes[]` oder `notes` auf Objekte umstellen (bricht ältere Apps)?
2. Soll `POST Association` `1 ASSO` automatisch in die Taufe verschieben, wenn die Person ohnehin bearbeitet wird?
3. Sollen freie Paten auch an Personen ohne Taufe (nur `BIRT`) erlaubt sein?
