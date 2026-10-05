#!/usr/bin/env python3
"""Tests fuer api4webtrees gegen ein echtes webtrees (siehe umgebung.py). Aufruf: python3 tests/test_api.py

Kern ist der Leck-Test: Die Testdaten tragen Markierungswoerter in allem, was eine Rolle NICHT sehen darf (lebende
Person, vertrauliche Person, Ereignis mit RESN privacy, privater Baum). Jede Rolle ruft jede Leseroute auf - kein
Markierungswort darf in irgendeiner Antwort stehen. So faellt ein Leck auf, egal ueber welches Feld es kaeme.
"""
import datetime
import json
import os
import re
import subprocess
import urllib.parse
import urllib.request
import sys
import unittest

import manifest
import umgebung

U = None

# Was welche Rolle nicht sehen darf (webtrees: lebende Personen fuer Gaeste verborgen, RESN privacy fuer Mitglieder
# sichtbar, RESN confidential nur fuer Verwalter, privater Baum nur mit Rolle darin).
LEBEND = "Markerlebend"        # Lebhart /Markerlebend/, geboren 1990, auch sein Geburtsort Markerlebendort
BERUF = "Markerberuf"          # OCCU mit 2 RESN privacy bei Theodor (I1)
KONFIDENZ = "Markerkonfidenz"  # Daten von Konrad (I5, 1 RESN confidential): Notiz und Geburtsort
# Den NAMEN einer privaten Person zeigt webtrees selbst angemeldeten Mitgliedern (Baumeinstellung SHOW_LIVING_NAMES,
# Voreinstellung "Mitglieder") - nur die Daten bleiben verborgen. Gaeste sehen auch den Namen nicht.
NAME_PRIVAT = "Markername"
GEHEIMBAUM = "Markerbaum"      # einzige Person im privaten Baum "geheim"

VERBOTEN = {
    None: [LEBEND, BERUF, KONFIDENZ, NAME_PRIVAT, GEHEIMBAUM],
    "mitglied": [KONFIDENZ, GEHEIMBAUM],
    "bearbeiter": [KONFIDENZ, GEHEIMBAUM],
}


def setUpModule():
    global U
    U = umgebung.Umgebung().aufbauen()


def tearDownModule():
    if U:
        U.abbauen()


def leseaufrufe(baum="testbaum"):
    """Alle Leserouten mit allen Personen und Familien des Testbaums."""
    personen = ["I1", "I2", "I3", "I4", "I5"]
    aufrufe = [("Info", None, {})]
    for q in ["", LEBEND, KONFIDENZ, NAME_PRIVAT, BERUF, GEHEIMBAUM, "Offen"]:
        aufrufe.append(("Individuals", baum, {"q": q}))
        aufrufe.append(("Individuals", baum, {"q": q, "scope": "all"}))
    for x in personen:
        aufrufe += [
            ("Individual", baum, {"xref": x}),
            ("Pedigree", baum, {"xref": x, "generations": 5, "siblings": 1}),
            ("Descendants", baum, {"xref": x, "generations": 5}),
            ("Relationship", baum, {"xref1": "I1", "xref2": x}),
            ("Relationship", baum, {"xref1": x, "xref2": "I2"}),
        ]
    for f in ["F1", "F2"]:
        aufrufe.append(("Family", baum, {"xref": f}))
    aufrufe.append(("Sources", baum, {}))
    aufrufe.append(("Repositories", baum, {}))
    for q in ["S1", "S2"]:
        aufrufe.append(("Source", baum, {"xref": q}))
    aufrufe += [
        ("Export", baum, {"page": 1}),
        ("MediaList", baum, {}),
        ("Bookmarks", baum, {}),
        ("Anniversaries", baum, {"days": 60}),
        ("Pending", baum, {}),
        ("Tags", baum, {"type": "INDI"}),
        ("Tags", baum, {"type": "FAM"}),
        ("Places", baum, {"q": ""}),
        ("Places", baum, {"q": "Marker"}),
        ("Places", baum, {"list": 1}),
    ]
    for ort in ["Offenbach", "Bieber, Offenbach", "Markerlebendort", "Markerkonfidenzort", "Gibtesnicht"]:
        aufrufe.append(("Place", baum, {"name": ort}))
    return aufrufe


class Lecktest(unittest.TestCase):
    def pruefen(self, benutzer):
        s = U.sitzung(benutzer)
        verboten = VERBOTEN[benutzer]
        for baum in ("testbaum", "geheim"):
            for aktion, b, params in leseaufrufe(baum):
                a = s.get(aktion, b, **params)
                self.assertLess(a.status, 500, f"{benutzer or 'Gast'}: {aktion} {b} {params} -> {a}")
                # Jede Antwort muss zum Manifest passen (docs/openapi.json, neu erzeugen: tests/manifest.py).
                if a.json is not None and a.json.get("ok") is not False:
                    abweichung = manifest.pruefen(a.json, manifest.schema_fuer("get", aktion))
                    self.assertEqual([], abweichung[:5], f"Manifest veraltet? {aktion} {params} (tests/manifest.py)")
                # Die Suche nennt das Suchwort in der Antwort - das ist kein Leck.
                text = a.text.replace(json.dumps(params.get("q", "")), '""')
                # Place nennt den erfragten Namen nicht, wenn es den Ort nicht zu sehen gibt (not-found).
                for wort in verboten:
                    # Ortsnamen schuetzt webtrees nicht (Ortsliste, Ortsvorschlaege: SearchService::searchPlaces) -
                    # die Route Places nutzt dieselbe Funktion, nur fuer Bearbeiter. Kein Leck des Moduls.
                    if aktion == "Places" and "q" in params and wort == KONFIDENZ:
                        continue
                    self.assertNotIn(wort, text, f"LECK fuer {benutzer or 'Gast'}: {aktion} {b} {params} zeigt {wort}")

    def test_gast(self):
        self.pruefen(None)

    def test_mitglied(self):
        self.pruefen("mitglied")

    def test_bearbeiter(self):
        self.pruefen("bearbeiter")

    def test_verwalter_sieht_alles(self):
        """Gegenprobe: Die Markierungen stehen wirklich in den Daten - sonst waere der Lecktest wertlos."""
        s = U.sitzung("verwalter")
        self.assertIn(LEBEND, s.get("Individual", "testbaum", xref="I3").text)
        self.assertIn(KONFIDENZ, s.get("Individual", "testbaum", xref="I5").text)
        self.assertIn(BERUF, s.get("Individual", "testbaum", xref="I1").text)
        m = U.sitzung("mitglied")
        self.assertIn(LEBEND, m.get("Individual", "testbaum", xref="I3").text)
        self.assertIn(BERUF, m.get("Individual", "testbaum", xref="I1").text)

    def test_geheimer_baum_fuer_gast_unsichtbar(self):
        info = U.sitzung().info()
        self.assertEqual(["testbaum"], [t["name"] for t in info["trees"]])


class Paten(unittest.TestCase):
    """Stufe 19: Paten und Trauzeugen lesen (docs/spec-paten-quellen.md, Abschnitt 1, 3, 5, 6)."""

    def fakt(self, benutzer, xref, tag, art="Individual"):
        fakten = U.sitzung(benutzer).get(art, "testbaum", xref=xref).json["facts"]
        return next(f for f in fakten if f["tag"] == tag)

    def test_verlinkte_paten_mit_notiz_und_quelle(self):
        taufe = self.fakt("verwalter", "I1", "CHR")
        paten = {a["xref"]: a for a in taufe["associates"]}
        self.assertEqual(["I2", "I3", "I5", "I4"], list(paten), "drei 2 _ASSO, dann der 1 ASSO der Person (level1)")
        anna = paten["I2"]
        self.assertEqual(("Anna Offen", "F", "godparent", "godparent", False, False), (anna["name"], anna["sex"], anna["rela"], anna["role"], anna["private"], anna["level1"]))
        self.assertIn(anna["label"], ("Patin", "Godmother"), "Beschriftung nach dem Geschlecht der verknuepften Person")
        self.assertEqual(["Schwester der Mutter"], anna["notes"])
        self.assertEqual(("S1", "Taufen 1800, Nr. 4"), (anna["sources"][0]["xref"], anna["sources"][0]["page"]))
        # Gross-/Kleinschreibung und aeltere Werte: Godparent, godfather -> role godparent, rela bleibt roh
        self.assertEqual(("Godparent", "godparent"), (paten["I3"]["rela"], paten["I3"]["role"]))
        self.assertEqual(("godfather", "godparent"), (paten["I5"]["rela"], paten["I5"]["role"]))
        self.assertIn(paten["I5"]["label"], ("Pate", "Godfather"))
        # 1 ASSO an der Person: in der Taufe mit level1, der Fakt ASSO bleibt daneben stehen (aeltere Clients)
        self.assertEqual(("Godfather", "godparent", True), (paten["I4"]["rela"], paten["I4"]["role"], paten["I4"]["level1"]))
        asso = self.fakt("verwalter", "I1", "ASSO")
        self.assertEqual(["I4"], [a["xref"] for a in asso["associates"]])
        self.assertTrue(asso["associates"][0]["level1"])
        # Taufen-Datum und -Ort bleiben wie bisher
        self.assertEqual(1800, taufe["date"]["year"])

    def test_asso_ohne_taufe_bleibt_eigener_fakt(self):
        asso = self.fakt("verwalter", "I2", "ASSO")
        a = asso["associates"][0]
        self.assertEqual(("I1", "friend", "other", True), (a["xref"], a["rela"], a["role"], a["level1"]))
        self.assertIn(a["label"], ("Freund", "Friend"))

    def test_freie_paten_neu_und_alt(self):
        taufe = self.fakt("verwalter", "I1", "CHR")
        self.assertEqual(["associates", "note"], taufe["noteKinds"])
        self.assertEqual(2, len(taufe["notes"]), "die Patennotiz bleibt in notes")
        self.assertEqual([("godparent", "Friedrich Plate", "Anbauer zu Celle", "Friedrich Plate, Anbauer zu Celle"),
                          ("godparent", "Marie Offen", "Witwe", "Marie Offen, Witwe")],
                         [(p["role"], p["name"], p["detail"], p["text"]) for p in taufe["freeAssociates"]])
        heirat = self.fakt("verwalter", "F1", "MARR", "Family")
        self.assertEqual([("witness", "Fritz Krause", "Schneider", "Fritz Krause, Schneider"), ("witness", None, None, "Hans Müller, Bauer, Offenbach")],
                         [(p["role"], p["name"], p["detail"], p["text"]) for p in heirat["freeAssociates"]],
                         "erst _WITN, dann die Notiz; alte Form ohne ';': nicht raten")

    def test_godp_zeilen(self):
        # GEDCOM-L: "2 _GODP <Text>" unter CHR/BAPM, je Zeile eine Person (Name bis zum ersten Komma); mit ";" eine Liste
        taufe = self.fakt("verwalter", "I4", "CHR")
        self.assertEqual([], taufe["notes"])
        self.assertEqual([("godparent", "Hans Meier", "Bauer"), ("godparent", "Grete Meier", "Witwe"), ("godparent", "Peter Schulz und Paul Schulz", None)],
                         [(p["role"], p["name"], p["detail"]) for p in taufe["freeAssociates"]])
        self.assertEqual("Peter Schulz und Paul Schulz", taufe["freeAssociates"][2]["text"])

    def test_heiratsart_und_trauzeuge(self):
        heirat = self.fakt("verwalter", "F1", "MARR", "Family")
        self.assertEqual("CIVIL", heirat["type"], "type wie bisher: webtrees' kanonische Form (Fact::attribute)")
        self.assertIn(heirat["typeLabel"], ("Standesamtliche Heirat", "Civil marriage"))
        zeuge = heirat["associates"][0]
        self.assertEqual(("I4", "witness", "witness"), (zeuge["xref"], zeuge["rela"], zeuge["role"]))
        self.assertIn(zeuge["label"], ("Zeuge", "Witness"))
        self.assertIsNone(self.fakt("verwalter", "I1", "BIRT")["typeLabel"])

    def test_cont_und_conc(self):
        # Beim Import fuegt webtrees CONC schon zusammen ...
        heirat = self.fakt("verwalter", "F1", "MARR", "Family")
        self.assertIn("Trauung in der Stadtkirche\nzweite Zeile", heirat["notes"])
        # ... eine ausstehende Aenderung mit CONC liest die API selbst richtig (Bearbeiter sehen ihre eigenen)
        s = U.sitzung("bearbeiter")
        a = s.post("Fact", "testbaum", {"gedcom": "1 EVEN\n2 TYPE Conctest\n2 NOTE Erste Zei\n3 CONC le\n3 CONT zweite Zeile"}, xref="I4")
        self.assertEqual(True, a.json["ok"], a)
        even = next(f for f in s.get("Individual", "testbaum", xref="I4").json["facts"] if f.get("type") == "Conctest")
        self.assertEqual(["Erste Zeile\nzweite Zeile"], even["notes"])
        self.assertEqual("Conctest", even["typeLabel"])

    def test_gegenrichtung(self):
        wo = U.sitzung("verwalter").get("Individual", "testbaum", xref="I1").json["associatedIn"]
        self.assertEqual([("I5", "INDI", "CHR", "godparent", False), ("I3", "INDI", "CHR", "godparent", False), ("I2", "INDI", "ASSO", "other", True)],
                         [(e["record"], e["recordType"], e["tag"], e["role"], e["level1"]) for e in wo], "nach Datum, ohne Datum zuletzt")
        self.assertEqual(("godmother", 1990), (wo[1]["rela"], wo[1]["date"]["year"]))
        self.assertIn(wo[0]["label2"], ("Pate", "Godfather"), "godparent: Beschriftung nach dem Geschlecht des Paten selbst")
        self.assertIn(wo[1]["label2"], ("Patin", "Godmother"), "godmother: webtrees kennt den Wert, er bleibt wie er ist")
        # Karl: Pate ueber 1 ASSO (-> die Taufe von I1) und Trauzeuge der Eltern (Familie)
        wo = U.sitzung("verwalter").get("Individual", "testbaum", xref="I4").json["associatedIn"]
        self.assertEqual([("I1", "INDI", "CHR", True, 1800), ("F1", "FAM", "MARR", False, 1828)],
                         [(e["record"], e["recordType"], e["tag"], e["level1"], e["date"]["year"]) for e in wo])
        self.assertEqual([(None, None), ("I1", "I2")], [(e["husband"], e["wife"]) for e in wo], "Partner der Familie zum Oeffnen")
        self.assertIn("Theodor", wo[0]["name"])
        self.assertIn("Anna", wo[1]["name"])

    def test_datenschutz_paten(self):
        # Gast: lebender und vertraulicher Pate ganz weg (webtrees zeigt Gaesten nicht einmal den Namen -
        # SHOW_LIVING_NAMES steht auf "Mitglieder"); Gegenrichtung ohne verborgene Datensaetze
        taufe = self.fakt(None, "I1", "CHR")
        self.assertEqual(["I2", "I4"], [a["xref"] for a in taufe["associates"]])
        self.assertEqual([False, False], [a["private"] for a in taufe["associates"]])
        wo = U.sitzung().get("Individual", "testbaum", xref="I1").json["associatedIn"]
        self.assertEqual(["I2"], [e["record"] for e in wo])
        # Mitglied: sieht Lebende, den Vertraulichen nur als Verweis ohne Namen
        taufe = self.fakt("mitglied", "I1", "CHR")
        paten = {a["xref"]: a for a in taufe["associates"]}
        self.assertEqual(["I2", "I3", "I5", "I4"], list(paten))
        self.assertEqual("Lebhart Markerlebend", paten["I3"]["name"])
        self.assertEqual((None, None, True), (paten["I5"]["name"], paten["I5"]["sex"], paten["I5"]["private"]))
        wo = U.sitzung("mitglied").get("Individual", "testbaum", xref="I1").json["associatedIn"]
        self.assertEqual(["I3", "I2"], [e["record"] for e in wo])


class Orte(unittest.TestCase):
    """Stufe 21: Ortsliste und ein Ort (PlaceActions)."""

    def test_ortsliste(self):
        orte = {o["name"]: o for o in U.sitzung("verwalter").get("Places", "testbaum", list=1).json["places"]}
        self.assertEqual(["Bieber, Gelnhausen", "Bieber, Offenbach", "Hof Nr. 1, Offenbach", "Hof Nr. 2, Offenbach", "Markerkonfidenzort", "Markerlebendort", "Offenbach"], sorted(orte))
        off = orte["Offenbach"]
        self.assertEqual((3, 2, 0, "L1", "OFFACHJO40BC", "location", None), (off["events"], off["individuals"], off["families"], off["location"], off["gov"], off["coordSource"], off["type"]))
        self.assertAlmostEqual(50.1, off["lat"])
        self.assertIsNone(orte["Bieber, Offenbach"]["location"], "Blattname Bieber hat keinen _LOC")
        self.assertEqual("L2", orte["Markerkonfidenzort"]["location"], "Blattname eindeutig: _LOC gefunden")
        # Stufe 27: Hof mit Bewohner ueber 3 _LOC, Hof ohne Ereignis nur ueber die _LOC-Hierarchie
        self.assertEqual((1, 1, "L3", "Hof", 50.11), tuple(orte["Hof Nr. 1, Offenbach"][k] for k in ("events", "individuals", "location", "type", "lat")))
        self.assertEqual((0, 0, "L4", "Hof", None), tuple(orte["Hof Nr. 2, Offenbach"][k] for k in ("events", "individuals", "location", "type", "lat")))
        gast = [o["name"] for o in U.sitzung().get("Places", "testbaum", list=1).json["places"]]
        self.assertEqual(["Bieber, Offenbach", "Hof Nr. 1, Offenbach", "Hof Nr. 2, Offenbach", "Offenbach"], gast, "Orte verborgener Personen fehlen (F2 hat ein lebendes Kind)")

    def test_ein_ort(self):
        o = U.sitzung("verwalter").get("Place", "testbaum", name="offenbach").json
        self.assertEqual(("Offenbach", ["Offenbach"], None, 3), (o["name"], o["levels"], o["parent"], o["events"]))
        self.assertEqual([("Bieber, Offenbach", None, None), ("Hof Nr. 1, Offenbach", "L3", "Hof"), ("Hof Nr. 2, Offenbach", "L4", "Hof")],
                         [(c["name"], c["location"], c["type"]) for c in o["children"]], "Unterorte aus Ortstabelle und _LOC-Hierarchie")
        self.assertEqual({"birth": 1, "marriage": 0, "death": 0, "other": 2}, o["eventCounts"], "Geburt I1; Taufe I1 und Wohnort I2")
        self.assertEqual(["I2", "I1"], [p["xref"] for p in o["individuals"]], "nach Namen: Anna vor Theodor")
        self.assertEqual(["BIRT", "CHR"], [f["tag"] for f in o["individuals"][1]["facts"]])
        self.assertEqual(1800, o["individuals"][1]["facts"][0]["date"]["year"])
        loc = o["location"]
        self.assertEqual(("L1", "Offenbach", "OFFACHJO40BC", ["Stadt am Main"]), (loc["xref"], loc["name"], loc["gov"], loc["notes"]))
        self.assertEqual([(None, "https://de.wikipedia.org/wiki/Offenbach_am_Main"), ("S1", "Ortsbeschreibung")], [(q["xref"], q["page"] or q["title"]) for q in loc["sources"]])
        self.assertEqual(("63065", "Hessen", None), (loc["postalCode"], loc["region"], loc["country"]))
        self.assertAlmostEqual(8.766667, loc["lng"], places=5)
        b = U.sitzung("verwalter").get("Place", "testbaum", name="Bieber, Offenbach").json
        self.assertEqual(("Offenbach", ["I4"], None), (b["parent"], [p["xref"] for p in b["individuals"]], b["location"]))

    def test_hof(self):
        """Stufe 27: _LOC-Hierarchie (1 _LOC), Art (TYPE) und Ereignisse am Ort (EVEN) - Hoefe fuer Ortsfamilienbuecher."""
        h = U.sitzung("verwalter").get("Place", "testbaum", name="Hof Nr. 1, Offenbach").json
        self.assertEqual(("Offenbach", 1, ["I4"], "L3"), (h["parent"], h["events"], [p["xref"] for p in h["individuals"]], h["location"]["xref"]))
        loc = h["location"]
        self.assertEqual("Hof", loc["type"])
        self.assertEqual([("L1", "Offenbach", "Offenbach", "POLI", 1800)], [(p["xref"], p["name"], p["fullName"], p["type"], p["date"]["year"]) for p in loc["parents"]])
        self.assertEqual(1, len(loc["events"]))
        e = loc["events"][0]
        self.assertEqual(("Brand", 1734, ["Scheune abgebrannt"], [("S1", "Ortschronik S. 12")]), (e["type"], e["date"]["year"], e["notes"], [(q["xref"], q["page"]) for q in e["sources"]]))
        self.assertAlmostEqual(50.11, h["lat"])
        # Hof ohne Ereignis: nur ueber die Hierarchie, Ereignisse 0, kein not-found
        h2 = U.sitzung().get("Place", "testbaum", name="hof nr. 2, offenbach").json
        self.assertEqual(("Hof Nr. 2, Offenbach", 0, [], "L4", "Hof", []), (h2["name"], h2["events"], h2["individuals"], h2["location"]["xref"], h2["location"]["type"], h2["location"]["events"]))
        self.assertEqual([], h2["children"])

    def test_unbekannt_und_verborgen(self):
        self.assertEqual("not-found", U.sitzung().get("Place", "testbaum", name="Markerlebendort").json["error"])
        self.assertEqual("not-found", U.sitzung("bearbeiter").get("Place", "testbaum", name="Markerkonfidenzort").json["error"])
        self.assertEqual("not-found", U.sitzung("verwalter").get("Place", "testbaum", name="Gibtesnicht").json["error"])
        self.assertEqual("name-missing", U.sitzung("verwalter").get("Place", "testbaum").json["error"])

    def test_zz_ortsdaten_schreiben(self):
        """Stufe 22: POST Place - zuletzt, weil es den Testbaum aendert."""
        s = U.sitzung("admin")
        # vorhandener _LOC: nur die GOV-Kennung aendern, Notiz/Quelle/Koordinaten bleiben
        a = s.post("Place", "testbaum", {"name": "Offenbach", "gov": "OFFACHJO40BD"})
        self.assertEqual((True, "L1", 0), (a.json["ok"], a.json["xref"], a.json["linked"]), a)
        loc = s.get("Place", "testbaum", name="Offenbach").json["location"]
        self.assertEqual(("OFFACHJO40BD", ["Stadt am Main"], 2, 50.1), (loc["gov"], loc["notes"], len(loc["sources"]), loc["lat"]))
        # Koordinaten und Notiz ersetzen, auch in die Geografischen Daten (Admin)
        a = s.post("Place", "testbaum", {"name": "Offenbach", "lat": 50.104444, "lng": -8.766, "note": "Stadt am Main\nzweite Zeile", "mapData": True})
        self.assertEqual((True, True), (a.json["ok"], a.json["mapData"]), a)
        loc = s.get("Place", "testbaum", name="Offenbach").json["location"]
        self.assertEqual((50.104444, -8.766, ["Stadt am Main\nzweite Zeile"]), (loc["lat"], loc["lng"], loc["notes"]))
        self.assertIn("2 LONG W8.766", umgebung.sql("SELECT o_gedcom FROM wt_other WHERE o_id = 'L1'")[0][0])
        self.assertEqual([(50.104444, -8.766)], [(float(a), float(b)) for a, b in umgebung.sql("SELECT latitude, longitude FROM wt_place_location WHERE place = 'Offenbach'")])
        # Medien am _LOC: hochladen (Route Media mit der Kennung des _LOC), dann ueber die Liste loesen
        rumpf, art = manifest.multipart({"title": "Ortsansicht", "link": "true"}, ("ansicht.png", manifest.PNG, "image/png"))
        req = urllib.request.Request(s.url("/module/_api4webtrees_/Media/testbaum", xref="L1"), data=rumpf, method="POST")
        req.add_header("Content-Type", art); req.add_header("X-CSRF-TOKEN", s.csrf)
        m = s._senden(req).json
        self.assertEqual(True, m["ok"], m)
        self.assertEqual(["Ortsansicht"], [x["title"] for x in s.get("Place", "testbaum", name="Offenbach").json["location"]["media"]])
        orts_medium = s.get("Place", "testbaum", name="Offenbach").json["location"]["media"][0]
        self.assertEqual(("photo", "png"), (orts_medium["type"], orts_medium["format"].lower()))
        self.assertTrue(any("1" in i for i in orts_medium["info"]), orts_medium["info"])
        # Titel und Art aendern (Route MediaObject)
        self.assertEqual(True, s.post("MediaObject", "testbaum", {"title": "Ortsansicht 1926", "type": "card"}, xref=m["media"]).json["ok"])
        orts_medium = s.get("Place", "testbaum", name="Offenbach").json["location"]["media"][0]
        self.assertEqual(("Ortsansicht 1926", "card"), (orts_medium["title"], orts_medium["type"]))
        self.assertEqual("invalid-type", s.post("MediaObject", "testbaum", {"type": "foto"}, xref=m["media"]).json["error"])
        self.assertEqual("not-editable", U.sitzung("mitglied").post("MediaObject", "testbaum", {"title": "x"}, xref=m["media"]).json["error"])
        self.assertEqual(True, s.post("Place", "testbaum", {"name": "Offenbach", "media": []}).json["ok"])
        self.assertEqual([], s.get("Place", "testbaum", name="Offenbach").json["location"]["media"])
        self.assertEqual(True, s.post("Place", "testbaum", {"name": "Offenbach", "media": [m["media"]]}).json["ok"])
        self.assertEqual(1, len(s.get("Place", "testbaum", name="Offenbach").json["location"]["media"]))
        # allgemeiner Quellenverweis am _LOC (Route Citation ohne factId)
        a = s.post("Citation", "testbaum", {"source": "S1", "page": "Ortschronik S. 3"}, xref="L1")
        self.assertEqual(True, a.json["ok"], a)
        self.assertIn(("S1", "Ortschronik S. 3"), [(q["xref"], q["page"]) for q in s.get("Place", "testbaum", name="Offenbach").json["location"]["sources"]])
        # Kurzname als "2 ABBR" unter dem NAME, auch in der Ortsliste
        self.assertEqual(True, s.post("Place", "testbaum", {"name": "Offenbach", "shortName": "Offb."}).json["ok"])
        self.assertEqual("Offb.", s.get("Place", "testbaum", name="Offenbach").json["location"]["shortName"])
        self.assertIn("1 NAME Offenbach\n2 ABBR Offb.", umgebung.sql("SELECT o_gedcom FROM wt_other WHERE o_id = 'L1'")[0][0])
        self.assertEqual("Offb.", next(o for o in s.get("Places", "testbaum", list=1).json["places"] if o["name"] == "Offenbach")["shortName"])
        s.post("Place", "testbaum", {"name": "Offenbach", "shortName": ""})
        self.assertIsNone(s.get("Place", "testbaum", name="Offenbach").json["location"]["shortName"])
        # Postleitzahl (vorhandene Schreibweise POST bleibt), Region, Land
        self.assertEqual(True, s.post("Place", "testbaum", {"name": "Offenbach", "postalCode": "63067", "country": "Deutschland"}).json["ok"])
        loc = s.get("Place", "testbaum", name="Offenbach").json["location"]
        self.assertEqual(("63067", "Hessen", "Deutschland"), (loc["postalCode"], loc["region"], loc["country"]))
        ged = umgebung.sql("SELECT o_gedcom FROM wt_other WHERE o_id = 'L1'")[0][0]
        self.assertIn("1 POST 63067", ged); self.assertIn("1 _CTRY Deutschland", ged); self.assertNotIn("_POST", ged)
        # Koordinaten entfernen
        s.post("Place", "testbaum", {"name": "Offenbach", "lat": None, "lng": None})
        self.assertIsNone(s.get("Place", "testbaum", name="Offenbach").json["location"]["lat"])
        # neuer _LOC; Blattname "Bieber" gibt es zweimal -> Verweis an die Ereignisse
        a = s.post("Place", "testbaum", {"name": "Bieber, Offenbach", "gov": "BIEBERJO40BC"})
        self.assertEqual((True, 1), (a.json["ok"], a.json["linked"]), a)
        neu = a.json["xref"]
        self.assertIn("3 _LOC @" + neu + "@", umgebung.sql("SELECT i_gedcom FROM wt_individuals WHERE i_id = 'I4'")[0][0])
        o = s.get("Place", "testbaum", name="Bieber, Offenbach").json
        self.assertEqual((neu, "Bieber", "BIEBERJO40BC"), (o["location"]["xref"], o["location"]["name"], o["location"]["gov"]))
        self.assertIsNone(s.get("Place", "testbaum", name="Bieber, Gelnhausen").json["location"], "der andere Bieber bekommt ihn nicht")
        # Bearbeiter ohne Sofortfreigabe: ausstehender _LOC, zweites Speichern trifft denselben
        b = U.sitzung("bearbeiter")
        a = b.post("Place", "testbaum", {"name": "Bieber, Gelnhausen", "note": "Dorf im Kinzigtal"})
        self.assertEqual((True, True), (a.json["ok"], a.json["pending"]), a)
        a2 = b.post("Place", "testbaum", {"name": "Bieber, Gelnhausen", "gov": "BIEBERJO40AA"})
        self.assertEqual(a.json["xref"], a2.json["xref"], "kein zweiter _LOC vor der Freigabe")
        # Stufe 27: Art und uebergeordneter Ort; neuer Hof ohne Ereignis nur mit parent
        self.assertEqual(True, s.post("Place", "testbaum", {"name": "Hof Nr. 2, Offenbach", "type": "Haus", "note": "Altenteil"}).json["ok"])
        self.assertEqual("1 TYPE Haus", [z for z in umgebung.sql("SELECT o_gedcom FROM wt_other WHERE o_id = 'L4'")[0][0].split("\n") if z.startswith("1 TYPE")][0])
        self.assertEqual(("Haus", ["Altenteil"]), tuple(s.get("Place", "testbaum", name="Hof Nr. 2, Offenbach").json["location"][k] for k in ("type", "notes")))
        neu = s.post("Place", "testbaum", {"name": "Hof Nr. 3, Offenbach", "type": "Hof", "parent": "L1"})
        self.assertEqual((True, 201), (neu.json["ok"], neu.status), neu)
        h3 = s.get("Place", "testbaum", name="Hof Nr. 3, Offenbach").json
        self.assertEqual(("Hof", ["L1"], 0), (h3["location"]["type"], [p["xref"] for p in h3["location"]["parents"]], h3["events"]))
        self.assertIn("Hof Nr. 3, Offenbach", [c["name"] for c in s.get("Place", "testbaum", name="Offenbach").json["children"]])
        self.assertEqual("not-found", s.post("Place", "testbaum", {"name": "Hof Nr. 4, Offenbach", "type": "Hof"}).json["error"], "ohne parent kein Ort ohne Ereignis")
        self.assertEqual("invalid-parent", s.post("Place", "testbaum", {"name": "Hof Nr. 3, Offenbach", "parent": "L99"}).json["error"])
        self.assertEqual("invalid-parent", s.post("Place", "testbaum", {"name": "Hof Nr. 3, Offenbach", "parent": neu.json["xref"]}).json["error"], "nicht sein eigener Oberort")
        s.post("Place", "testbaum", {"name": "Hof Nr. 3, Offenbach", "parent": None})
        self.assertNotIn("1 _LOC", umgebung.sql("SELECT o_gedcom FROM wt_other WHERE o_id = ?", neu.json["xref"])[0][0])
        # Fehler und Rechte
        self.assertEqual("not-editable", U.sitzung("mitglied").post("Place", "testbaum", {"name": "Offenbach", "gov": "X"}).json["error"])
        self.assertEqual("invalid-coordinates", s.post("Place", "testbaum", {"name": "Offenbach", "lat": 95, "lng": 1}).json["error"])
        self.assertEqual("invalid-coordinates", s.post("Place", "testbaum", {"name": "Offenbach", "lat": 50}).json["error"])
        self.assertEqual("invalid-gov", s.post("Place", "testbaum", {"name": "Offenbach", "gov": "a b"}).json["error"])
        self.assertEqual("not-found", b.post("Place", "testbaum", {"name": "Markerkonfidenzort", "gov": "X"}).json["error"])

    def test_zzz_umbenennen_und_zusammenfuehren(self):
        """Stufe 23: POST PlaceRename - nach dem Schreibtest, weil es den Testbaum aendert."""
        s = U.sitzung("admin")
        # Vorschau: Offenbach -> Offenbach am Main, mit dem Ort darunter; aendert nichts
        v = s.post("PlaceRename", "testbaum", {"from": "Offenbach", "to": "Offenbach am Main", "preview": True}).json
        self.assertEqual((True, False, 3, 5, 2, 0, "L1"), (v["preview"], v["merge"], v["records"], v["events"], v["subPlaces"], v["skipped"], v["location"]["from"]), v)
        self.assertEqual(True, s.get("Place", "testbaum", name="Offenbach").json.get("ok", True))
        # Ausfuehren
        a = s.post("PlaceRename", "testbaum", {"from": "Offenbach", "to": "Offenbach am Main"}).json
        self.assertEqual((True, False, 5), (a["ok"], a["preview"], a["events"]), a)
        self.assertEqual("not-found", s.get("Place", "testbaum", name="Offenbach").json["error"])
        o = s.get("Place", "testbaum", name="Offenbach am Main").json
        self.assertEqual(("L1", "Offenbach am Main", 3), (o["location"]["xref"], o["location"]["name"], o["events"]))
        self.assertEqual(["Bieber, Offenbach am Main", "Hof Nr. 1, Offenbach am Main", "Hof Nr. 2, Offenbach am Main"], [c["name"] for c in o["children"]], "Hoefe wandern ueber die _LOC-Hierarchie mit")
        ged = umgebung.sql("SELECT i_gedcom FROM wt_individuals WHERE i_id = 'I1'")[0][0]
        self.assertEqual(2, ged.count("2 PLAC Offenbach am Main\n3 _LOC @L1@"), "Geburt und Taufe zeigen auf den _LOC")
        # Hin und zurueck: der alte Name steht noch in der Ortstabelle von webtrees, ist aber kein Zusammenfuehren
        self.assertEqual(False, s.post("PlaceRename", "testbaum", {"from": "Offenbach am Main", "to": "Offenbach", "preview": True}).json["merge"])
        # Bearbeiter: das gesperrte Ereignis (RESI von I2) bleibt
        v = U.sitzung("bearbeiter").post("PlaceRename", "testbaum", {"from": "Offenbach am Main", "to": "Offenbach a. M.", "preview": True}).json
        self.assertEqual((4, 1), (v["events"], v["skipped"]), v)
        # Zusammenfuehren: Bieber unter Offenbach am Main -> Bieber, Gelnhausen (beide mit _LOC, GOV weicht ab)
        von = s.get("Place", "testbaum", name="Bieber, Offenbach am Main").json["location"]["xref"]
        nach = s.get("Place", "testbaum", name="Bieber, Gelnhausen").json["location"]["xref"]
        v = s.post("PlaceRename", "testbaum", {"from": "Bieber, Offenbach am Main", "to": "Bieber, Gelnhausen", "preview": True}).json
        self.assertEqual((True, von, nach, ["gov"]), (v["merge"], v["location"]["from"], v["location"]["to"], v["location"]["conflicts"]), v)
        a = s.post("PlaceRename", "testbaum", {"from": "Bieber, Offenbach am Main", "to": "Bieber, Gelnhausen"}).json
        self.assertEqual(True, a["ok"], a)
        b = s.get("Place", "testbaum", name="Bieber, Gelnhausen").json
        self.assertEqual((nach, ["I4"], ["F2"]), (b["location"]["xref"], [p["xref"] for p in b["individuals"]], [f["xref"] for f in b["families"]]))
        self.assertIn("Dorf im Kinzigtal", b["location"]["notes"], "Notiz des Ziels bleibt")
        self.assertEqual([], umgebung.sql("SELECT o_id FROM wt_other WHERE o_id = ?", von), "der alte _LOC ist weg")
        self.assertIn("3 _LOC @" + nach + "@", umgebung.sql("SELECT i_gedcom FROM wt_individuals WHERE i_id = 'I4'")[0][0])
        # Fehler und Rechte
        self.assertEqual("not-editable", U.sitzung("mitglied").post("PlaceRename", "testbaum", {"from": "Offenbach am Main", "to": "X"}).json["error"])
        self.assertEqual("not-found", U.sitzung("bearbeiter").post("PlaceRename", "testbaum", {"from": "Markerkonfidenzort", "to": "X"}).json["error"])
        self.assertEqual("name-missing", s.post("PlaceRename", "testbaum", {"from": "Offenbach am Main", "to": " , "}).json["error"])

class Schreiben(unittest.TestCase):
    def fakten(self, s, xref):
        return s.get("Individual", "testbaum", xref=xref).json["facts"]

    def beruf_anlegen(self, s, wert, **extra):
        return s.post("Fact", "testbaum", {"tag": "OCCU", "value": wert}, xref="I1", **extra)

    def test_gast_darf_nicht_schreiben(self):
        a = self.beruf_anlegen(U.sitzung(), "Gastberuf")
        self.assertEqual(False, a.json and a.json.get("ok"), a)
        self.assertNotIn("Gastberuf", U.sitzung("verwalter").get("Individual", "testbaum", xref="I1").text)

    def test_mitglied_darf_nicht_schreiben(self):
        a = self.beruf_anlegen(U.sitzung("mitglied"), "Mitgliedberuf")
        self.assertEqual({"ok": False, "error": "not-editable", "status": 403}, a.json)

    def test_ohne_csrf_wird_nichts_ausgefuehrt(self):
        s = U.sitzung("admin")
        a = self.beruf_anlegen(s, "Ohnecsrf", csrf=False)
        self.assertIn(a.status, (302, 303), a)  # webtrees CheckCsrf: Umleitung statt Ausfuehrung
        self.assertNotIn("Ohnecsrf", U.sitzung("verwalter").get("Individual", "testbaum", xref="I1").text)

    def test_bearbeiter_schreibt_als_ausstehende_aenderung(self):
        s = U.sitzung("bearbeiter")
        a = self.beruf_anlegen(s, "Bearbeiterberuf")
        self.assertEqual(True, a.json["ok"], a)
        self.assertEqual(True, a.json["pending"], "ohne auto_accept muss die Aenderung auf Freigabe warten")
        # Gaeste sehen ausstehende Aenderungen nicht
        self.assertNotIn("Bearbeiterberuf", U.sitzung().get("Individual", "testbaum", xref="I1").text)
        pending = U.sitzung("verwalter").get("Pending", "testbaum")
        self.assertIn("I1", pending.text)

    def test_admin_mit_auto_accept_schreibt_sofort(self):
        s = U.sitzung("admin")
        a = self.beruf_anlegen(s, "Adminberuf")
        self.assertEqual((True, False), (a.json["ok"], a.json["pending"]), a)
        self.assertIn("Adminberuf", U.sitzung().get("Individual", "testbaum", xref="I1").text)

    def test_vertrauliche_person_fuer_bearbeiter_nicht_bearbeitbar(self):
        a = U.sitzung("bearbeiter").post("Fact", "testbaum", {"tag": "OCCU", "value": "x"}, xref="I5")
        self.assertEqual("private", a.json["error"], a)

    def test_gesperrtes_ereignis_nicht_loeschbar(self):
        s = U.sitzung("bearbeiter")
        reli = [f for f in self.fakten(s, "I1") if f.get("tag") == "RELI"]
        self.assertEqual(1, len(reli), "RELI mit RESN locked muss sichtbar sein")
        a = s.post("DeleteFact", "testbaum", {"factId": reli[0]["id"]}, xref="I1")
        self.assertEqual("fact-locked", a.json["error"], a)

    def test_person_anlegen(self):
        s = U.sitzung("admin")
        a = s.post("AddIndividual", "testbaum", {"relation": "none", "given": "Neue", "surname": "Testperson", "sex": "F",
                                                  "dead": True, "birthDate": "1850"})
        self.assertEqual(True, a.json["ok"], a)
        self.assertIn("Testperson", s.get("Individual", "testbaum", xref=a.json["xref"]).text)

    def test_quellenverweis_mit_seite(self):
        # Die Seitenangabe (3 PAGE, mehrzeilig) kommt mit; eine vertrauliche Quelle nur fuer den Verwalter.
        # Gaesten zeigt webtrees in diesem Baum keine Quellen - dann auch keine Seite (das deckt der Lecktest ab).
        for benutzer, sieht_konfidenz in (("verwalter", True), ("bearbeiter", False), ("mitglied", False)):
            fakten = U.sitzung(benutzer).get("Individual", "testbaum", xref="I1").json["facts"]
            geburt = next(f for f in fakten if f.get("tag") == "BIRT")
            q = geburt["sources"]
            self.assertEqual((1, "S1", "Kirchenbuch Offenbach", "Taufen 1800,\nNr. 4"), (len(q), q[0]["xref"], q[0]["title"], q[0]["page"]), benutzer)
            tod = next(f for f in fakten if f.get("tag") == "DEAT")
            self.assertEqual(sieht_konfidenz, any(q.get("page") == "Markerkonfidenz-Seite" for q in tod["sources"]), benutzer)
        gast = U.sitzung().get("Individual", "testbaum", xref="I1").json["facts"]
        self.assertNotIn("Taufen 1800", json.dumps(gast, ensure_ascii=False))

    def test_quellenverweis_vollstaendig(self):
        # Stufe 18: Qualitaet, Datum und Text der Fundstelle, Notiz - und Text-Quellen ohne Datensatz
        fakten = U.sitzung("bearbeiter").get("Individual", "testbaum", xref="I1").json["facts"]
        q = next(f for f in fakten if f.get("tag") == "BIRT")["sources"][0]
        self.assertEqual(3, q["quality"])
        self.assertEqual("2 MAR 1800", q["date"]["gedcom"])
        self.assertEqual("Theodor, Sohn des\nSchmieds, getauft", q["text"])
        self.assertEqual(["Eintrag gut lesbar"], q["notes"])
        beruf = next(f for f in fakten if f.get("tag") == "OCCU" and f.get("value") == "Schmied")
        self.assertEqual([("", "laut Martha Meier", ["mündlich 1950"])], [(x["xref"], x["title"], x["notes"]) for x in beruf["sources"]])

    def test_quellen_liste_und_einzeln(self):
        s = U.sitzung("bearbeiter")
        liste = s.get("Sources", "testbaum").json
        self.assertEqual(["S1"], [x["xref"] for x in liste["sources"]])  # S2 ist vertraulich
        s1 = liste["sources"][0]
        self.assertEqual(("Pfarramt Offenbach", "Offenbach, 1790–1830", "Bistumsarchiv Mainz", "KB 12", 4),
                         (s1["author"], s1["publication"], s1["repository"], s1["callNumber"], s1["uses"]))
        einzeln = s.get("Source", "testbaum", xref="S1").json
        self.assertEqual("Taufen, Trauungen, Begräbnisse", einzeln["text"])
        self.assertEqual(["Digitalisat im Archiv"], einzeln["notes"])
        self.assertEqual({("I1", ("Geburt", "Kindstaufe")), ("I2", ("Tod",))}, {(p["xref"], tuple(p["facts"])) for p in einzeln["individuals"]})
        self.assertEqual("private", s.get("Source", "testbaum", xref="S2").json["error"])
        verwalter = U.sitzung("verwalter").get("Sources", "testbaum").json
        self.assertEqual(["Kirchenbuch Offenbach", "Markerkonfidenz-Quelle"], [x["title"] for x in verwalter["sources"]])

    def test_quellenverweis_schreiben(self):
        # Verweis anlegen, gezielt aendern, verschieben, loeschen - das Ereignis und die anderen Teile bleiben unberuehrt.
        # Die Kennung des Ereignisses aendert sich mit jedem Schreiben (Hash); die Antwort liefert die neue.
        s = U.sitzung("admin")
        a = s.post("AddIndividual", "testbaum", {"relation": "none", "given": "Zita", "surname": "Quell", "sex": "F", "dead": True,
                                                  "birthDate": "1 MAY 1850", "birthPlace": "Zitadorf"})
        xref = a.json["xref"]
        fid = next(f for f in self.fakten(s, xref) if f.get("tag") == "BIRT")["id"]

        def zitat(**rumpf):
            nonlocal fid
            a = s.post("Citation", "testbaum", {"factId": fid, **rumpf}, xref=xref)
            self.assertEqual(True, a.json["ok"], a)
            fid = a.json["factId"]
            return next(f for f in self.fakten(s, xref) if f.get("tag") == "BIRT")

        # 1. anlegen: Quelle S1 mit Seite, Qualitaet, Datum, Zitat, Notiz; 2. eine Text-Quelle dazu
        zitat(source="S1", page="Taufen 1850, Nr. 7", quality=3, date="3 MAY 1850", text="Zita, Tochter\ndes Quell", note="gut lesbar")
        geburt = zitat(source="laut Oma Quell", quality=1)
        self.assertEqual(fid, geburt["id"])
        q = geburt["sources"]
        self.assertEqual([("S1", "Taufen 1850, Nr. 7", 3, "Zita, Tochter\ndes Quell", ["gut lesbar"]), ("", "", 1, "", [])],
                         [(x["xref"], x["page"], x["quality"], x["text"], x["notes"]) for x in q])
        self.assertEqual("laut Oma Quell", q[1]["title"])
        # 3. nur die Seite aendern: Qualitaet, Datum, Zitat, Notiz bleiben
        q0 = zitat(index=0, page="Taufen 1850, Nr. 8")["sources"][0]
        self.assertEqual(("Taufen 1850, Nr. 8", 3, "3 MAY 1850", "Zita, Tochter\ndes Quell", ["gut lesbar"]),
                         (q0["page"], q0["quality"], q0["date"]["gedcom"], q0["text"], q0["notes"]))
        # 4. verschieben: die Text-Quelle nach vorn
        self.assertEqual(["", "S1"], [x["xref"] for x in zitat(index=1, moveTo=0)["sources"]])
        # Ort und Datum des Ereignisses selbst sind unveraendert, DATA ist richtig aufgebaut
        ged = umgebung.sql("SELECT i_gedcom FROM wt_individuals WHERE i_id = ?", xref)[0][0]
        self.assertIn("2 DATE 1 MAY 1850\n2 PLAC Zitadorf", ged)
        self.assertIn("3 DATA\n4 DATE 3 MAY 1850\n4 TEXT Zita, Tochter\n5 CONT des Quell", ged)
        # 5. loeschen
        self.assertEqual(["S1"], [x["xref"] for x in zitat(index=0, delete=True)["sources"]])
        # 6. allgemeiner Verweis am Datensatz und Fehler
        a = s.post("Citation", "testbaum", {"source": "S1", "page": "Familienbogen"}, xref=xref)
        self.assertEqual(True, a.json["ok"], a)
        allgemein = [f for f in self.fakten(s, xref) if f.get("tag") == "SOUR"]
        self.assertEqual([("S1", "Familienbogen")], [(x["sources"][0]["xref"], x["sources"][0]["page"]) for x in allgemein])
        self.assertEqual("source-not-found", s.post("Citation", "testbaum", {"factId": fid, "source": "@S999@"}, xref=xref).json["error"])
        self.assertEqual("invalid-quality", s.post("Citation", "testbaum", {"factId": fid, "index": 0, "quality": 7}, xref=xref).json["error"])
        self.assertEqual("not-editable", U.sitzung("mitglied").post("Citation", "testbaum", {"factId": fid, "source": "S1"}, xref=xref).json["error"])

    def test_paten_schreiben(self):
        # Stufe 20: Paten verknuepft und frei schreiben, Unterzeilen und Schreibweise bleiben, 1 ASSO in die Taufe
        s = U.sitzung("admin")
        a = s.post("AddIndividual", "testbaum", {"relation": "none", "given": "Pia", "surname": "Pate", "sex": "F", "dead": True,
                                                  "birthDate": "1 MAY 1850"})
        xref = a.json["xref"]
        taufe_alt = ("1 CHR\n2 DATE 3 MAY 1850\n2 PLAC Patendorf\n2 _ASSO @I2@\n3 RELA Godmother\n3 SOUR @S1@\n4 PAGE Taufen 1850"
                     "\n2 _ASSO @I5@\n3 RELA godfather\n2 NOTE Paten: Hans Alt, Bauer; Grete Alt\n2 NOTE Alte Randnotiz\n2 SOUR @S1@\n3 PAGE Taufen 1850, Nr. 3")
        self.assertEqual(True, s.post("Fact", "testbaum", {"gedcom": taufe_alt}, xref=xref).json["ok"])
        self.assertEqual(True, s.post("Fact", "testbaum", {"gedcom": "1 ASSO @I4@\n2 RELA Godfather\n2 NOTE in Abwesenheit"}, xref=xref).json["ok"])

        def taufe():
            return next(f for f in self.fakten(s, xref) if f.get("tag") == "CHR")

        def ged():
            return umgebung.sql("SELECT i_gedcom FROM wt_individuals WHERE i_id = ?", xref)[0][0]

        # Die gewoehnliche Notiz aendern: die Patenliste bleibt (sie ist nicht "die erste Notiz")
        a = s.post("Fact", "testbaum", {"factId": taufe()["id"], "note": "Randnotiz"}, xref=xref)
        self.assertEqual(True, a.json["ok"], a)
        self.assertEqual(["Paten: Hans Alt, Bauer; Grete Alt", "Randnotiz"], taufe()["notes"])
        fid = taufe()["id"]
        # Reihenfolge tauschen, I3 neu als Pate mit Notiz, I5 (fuer Mitglieder privat) bleibt, I4 aus 1 ASSO hereinholen,
        # freie Paten neu - die alte Notiz "Paten: ..." geht in _GODP ueber, die Randnotiz bleibt
        a = s.post("Association", "testbaum", {"factId": fid, "convertLevel1": True,
                                               "linked": [{"xref": "I5", "role": "godparent"}, {"xref": "I2", "role": "godparent"},
                                                          {"xref": "I3", "role": "godparent", "note": "Bruder des Vaters"},
                                                          {"xref": "I4", "role": "godparent"}],
                                               "free": [{"text": "Hans Alt, Bauer", "role": "godparent"}, {"text": "Fritz Neu, Schmied", "role": "godparent"}]},
                   xref=xref)
        self.assertEqual(True, a.json["ok"], a)
        t = taufe()
        self.assertEqual(a.json["factId"], t["id"])
        self.assertEqual(["I5", "I2", "I3", "I4"], [x["xref"] for x in t["associates"]])
        self.assertEqual([False] * 4, [x["level1"] for x in t["associates"]], "I4 steht jetzt an der Taufe selbst")
        self.assertEqual([("Hans Alt", "Bauer"), ("Fritz Neu", "Schmied")], [(x["name"], x["detail"]) for x in t["freeAssociates"]])
        self.assertEqual(["Randnotiz"], t["notes"])
        g = ged()
        self.assertIn("2 _ASSO @I5@\n3 RELA godfather\n2 _ASSO @I2@\n3 RELA Godmother\n3 SOUR @S1@\n4 PAGE Taufen 1850"
                      "\n2 _ASSO @I3@\n3 RELA godparent\n3 NOTE Bruder des Vaters\n2 _ASSO @I4@\n3 RELA godparent\n3 NOTE in Abwesenheit"
                      "\n2 _GODP Hans Alt, Bauer\n2 _GODP Fritz Neu, Schmied\n2 SOUR @S1@\n3 PAGE Taufen 1850, Nr. 3\n2 NOTE Randnotiz", g)
        self.assertNotIn("1 ASSO", g, "der 1 ASSO ist in die Taufe gewandert")
        self.assertIn("2 DATE 3 MAY 1850\n2 PLAC Patendorf", g)
        # Nur die freien aendern: verknuepfte bleiben; einen verknuepften entfernen: die freien bleiben
        fid = s.post("Association", "testbaum", {"factId": t["id"], "free": []}, xref=xref).json["factId"]
        self.assertEqual(([], 4), (taufe()["freeAssociates"], len(taufe()["associates"])))
        a = s.post("Association", "testbaum", {"factId": fid, "linked": [{"xref": "I2", "role": "godparent"}]}, xref=xref)
        self.assertEqual(["I2"], [x["xref"] for x in taufe()["associates"]])
        self.assertIn("3 SOUR @S1@", ged(), "Quelle am Paten bleibt")
        # Fehler
        fid = a.json["factId"]
        self.assertEqual("invalid-role", s.post("Association", "testbaum", {"factId": fid, "linked": [{"xref": "I3", "role": "nachbar"}]}, xref=xref).json["error"])
        self.assertEqual("individual-not-found", s.post("Association", "testbaum", {"factId": fid, "linked": [{"xref": "I999", "role": "godparent"}]}, xref=xref).json["error"])
        self.assertEqual("invalid-value", s.post("Association", "testbaum", {"factId": fid, "free": [{"text": "@I1@", "role": "godparent"}]}, xref=xref).json["error"])
        self.assertEqual("not-editable", U.sitzung("mitglied").post("Association", "testbaum", {"factId": fid, "free": []}, xref=xref).json["error"])
        # Andere Beteiligte mit freier Rolle
        s.post("Association", "testbaum", {"factId": fid, "linked": [{"xref": "I2", "role": "godparent"}, {"xref": "I3", "role": "other", "rela": "Hebamme"}]}, xref=xref)
        self.assertEqual([("godparent", "Godmother"), ("other", "Hebamme")], [(x["role"], x["rela"]) for x in taufe()["associates"]])
        # Aufraeumen: die Testperson zitiert S1 und wuerde die Zaehlung anderer Tests verfaelschen
        self.assertEqual(True, s.post("DeleteRecord", "testbaum", {}, xref=xref).json["ok"])

    def test_heiratsart_und_trauzeugen_schreiben(self):
        s = U.sitzung("admin")
        a = s.post("Fact", "testbaum", {"tag": "MARR", "date": "5 JUN 1830", "type": "religious"}, xref="F1")
        self.assertEqual(True, a.json["ok"], a)
        heiraten = [f for f in s.get("Family", "testbaum", xref="F1").json["facts"] if f["tag"] == "MARR"]
        kirchlich = next(f for f in heiraten if f["type"] == "RELIGIOUS")
        self.assertTrue(kirchlich["typeLabel"])
        a = s.post("Association", "testbaum", {"factId": kirchlich["id"], "linked": [{"xref": "I4", "role": "witness"}],
                                               "free": [{"text": "Otto Zeuge, Kuester", "role": "witness"}]}, xref="F1")
        self.assertEqual(True, a.json["ok"], a)
        g = umgebung.sql("SELECT f_gedcom FROM wt_families WHERE f_id = 'F1'")[0][0]
        self.assertIn("1 MARR\n2 TYPE RELIGIOUS\n2 DATE 5 JUN 1830\n2 _ASSO @I4@\n3 RELA witness\n2 _WITN Otto Zeuge, Kuester", g)
        # Art aendern und wieder aufraeumen; die erste Heirat (civil) bleibt unberuehrt
        a = s.post("Fact", "testbaum", {"factId": a.json["factId"], "type": "Civil"}, xref="F1")
        self.assertIn("2 TYPE CIVIL\n2 DATE 5 JUN 1830", umgebung.sql("SELECT f_gedcom FROM wt_families WHERE f_id = 'F1'")[0][0])
        neu = [f for f in s.get("Family", "testbaum", xref="F1").json["facts"] if f["tag"] == "MARR" and f["date"] and f["date"]["year"] == 1830 and f["date"]["gedcom"] == "5 JUN 1830"]
        self.assertEqual(True, s.post("DeleteFact", "testbaum", {"factId": neu[0]["id"]}, xref="F1").json["ok"])
        self.assertIn("2 DATE 1828\n2 TYPE civil", umgebung.sql("SELECT f_gedcom FROM wt_families WHERE f_id = 'F1'")[0][0])

    def test_quelle_und_archiv_pflegen(self):
        s = U.sitzung("admin")
        # Archiv anlegen, Quelle anlegen mit Archiv und Signatur
        r = s.post("Repository", "testbaum", {"name": "Stadtarchiv Zitadorf"})
        self.assertEqual(True, r.json["ok"], r); repo = r.json["xref"]
        a = s.post("Source", "testbaum", {"title": "Adressbuch Zitadorf 1900", "author": "Magistrat", "publication": "Zitadorf, 1900",
                                           "text": "Seite 1\nSeite 2", "note": "Digitalisat", "repository": repo, "callNumber": "AB 1900"})
        self.assertEqual(True, a.json["ok"], a); quelle = a.json["xref"]
        q = s.get("Source", "testbaum", xref=quelle).json
        self.assertEqual(("Adressbuch Zitadorf 1900", "Magistrat", "Zitadorf, 1900", "Seite 1\nSeite 2", ["Digitalisat"], "Stadtarchiv Zitadorf", "AB 1900"),
                         (q["title"], q["author"], q["publication"], q["text"], q["notes"], q["repository"], q["callNumber"]))
        # nur den Autor aendern: alles andere bleibt
        a = s.post("Source", "testbaum", {"author": "Magistrat der Stadt"}, xref=quelle)
        self.assertEqual(True, a.json["ok"], a)
        q = s.get("Source", "testbaum", xref=quelle).json
        self.assertEqual(("Adressbuch Zitadorf 1900", "Magistrat der Stadt", "Zitadorf, 1900", "AB 1900"), (q["title"], q["author"], q["publication"], q["callNumber"]))
        # nur die Signatur aendern, dann das Archiv entfernen
        s.post("Source", "testbaum", {"callNumber": "AB 1900/2"}, xref=quelle)
        self.assertEqual(("Stadtarchiv Zitadorf", "AB 1900/2"), (lambda q: (q["repository"], q["callNumber"]))(s.get("Source", "testbaum", xref=quelle).json))
        s.post("Source", "testbaum", {"repository": ""}, xref=quelle)
        self.assertEqual(("", ""), (lambda q: (q["repository"], q["callNumber"]))(s.get("Source", "testbaum", xref=quelle).json))
        # Archiv umbenennen, Liste
        self.assertEqual(True, s.post("Repository", "testbaum", {"name": "Stadtarchiv Zitadorf (neu)"}, xref=repo).json["ok"])
        repos = s.get("Repositories", "testbaum").json["repositories"]
        self.assertIn(("Stadtarchiv Zitadorf (neu)", 0), [(x["name"], x["uses"]) for x in repos])
        # Fehler und Rechte
        self.assertEqual("title-missing", s.post("Source", "testbaum", {"author": "x"}).json["error"])
        self.assertEqual("repository-not-found", s.post("Source", "testbaum", {"title": "x", "repository": "R999"}).json["error"])
        self.assertEqual("not-editable", U.sitzung("mitglied").post("Source", "testbaum", {"title": "x"}).json["error"])
        # Medium ohne Verknuepfung hochladen (link=false), dann an die Quelle und an einen Verweis haengen
        rumpf, art = manifest.multipart({"title": "Scan", "type": "document", "link": "false"}, ("scan.png", manifest.PNG, "image/png"))
        req = urllib.request.Request(s.url("/module/_api4webtrees_/Media/testbaum", xref="I1"), data=rumpf, method="POST")
        req.add_header("Content-Type", art); req.add_header("X-CSRF-TOKEN", s.csrf)
        m = s._senden(req).json
        self.assertEqual(True, m["ok"], m)
        self.assertNotIn(m["media"], json.dumps(self.fakten(s, "I1")), "link=false darf die Person nicht verknuepfen")
        self.assertEqual(True, s.post("Source", "testbaum", {"media": [m["media"]]}, xref=quelle).json["ok"])
        self.assertEqual(["Scan"], [x["title"] for x in s.get("Source", "testbaum", xref=quelle).json["media"]])
        self.assertEqual(True, s.post("Source", "testbaum", {"media": []}, xref=quelle).json["ok"])
        self.assertEqual([], s.get("Source", "testbaum", xref=quelle).json["media"])
        geburt = next(f for f in self.fakten(s, "I2") if f.get("tag") == "DEAT")
        a = s.post("Citation", "testbaum", {"factId": geburt["id"], "index": 0, "media": [m["media"]]}, xref="I2")
        self.assertEqual(True, a.json["ok"], a)
        tod = next(f for f in self.fakten(s, "I2") if f.get("tag") == "DEAT")["sources"][0]
        self.assertEqual(("Begräbnisse 1880", ["Scan"]), (tod["page"], [x["title"] for x in tod["media"]]))
        # unbenutzte Quelle loeschen
        self.assertEqual(0, next(x["uses"] for x in s.get("Sources", "testbaum").json["sources"] if x["xref"] == quelle))
        self.assertEqual(True, s.post("DeleteRecord", "testbaum", {}, xref=quelle).json["ok"])
        self.assertEqual("not-found", s.get("Source", "testbaum", xref=quelle).json["error"])

    def test_medienobjekt_aus_archivdatei(self):
        # Eine Datei im Medienordner (Archiv) wird auf Wunsch ein Medienobjekt - einmal; ohne Verknuepfung an die Person
        s = U.sitzung("admin")
        ordner = os.path.join(umgebung.WT, "data", "media", "kirchenbuch")
        os.makedirs(ordner, exist_ok=True)
        open(os.path.join(ordner, "taufe_1833.png"), "wb").write(manifest.PNG)
        a = s.post("MediaFromFile", "testbaum", {"file": "kirchenbuch/taufe_1833.png", "title": "Taufe 1833"}, xref="I1")
        self.assertEqual((True, False), (a.json["ok"], a.json["existing"]), a)
        m = a.json["media"]
        b = s.post("MediaFromFile", "testbaum", {"file": "kirchenbuch/taufe_1833.png"}, xref="I1")
        self.assertEqual((m, True), (b.json["media"], b.json["existing"]), b)
        self.assertNotIn(m, json.dumps(self.fakten(s, "I1")), "darf die Person nicht verknuepfen")
        self.assertEqual("file-not-found", s.post("MediaFromFile", "testbaum", {"file": "kirchenbuch/gibtsnicht.png"}, xref="I1").json["error"])
        self.assertEqual("invalid-value", s.post("MediaFromFile", "testbaum", {"file": "../config.ini.php"}, xref="I1").json["error"])
        self.assertEqual("upload-not-allowed", U.sitzung("mitglied").post("MediaFromFile", "testbaum", {"file": "kirchenbuch/taufe_1833.png"}, xref="I1").json["error"])
        # Am Verweis haengt es dann wie jedes Medium
        tod = next(f for f in self.fakten(s, "I2") if f.get("tag") == "DEAT")
        self.assertEqual(True, s.post("Citation", "testbaum", {"factId": tod["id"], "index": 0, "media": [m]}, xref="I2").json["ok"])
        self.assertEqual(["Taufe 1833"], [x["title"] for x in next(f for f in self.fakten(s, "I2") if f.get("tag") == "DEAT")["sources"][0]["media"]])

    def test_notiz_und_vorhandenes_medium_an_person(self):
        """Stufe 23: Notizen an der Person (Route Fact, Tag NOTE), Verweis auf Notiz-Datensatz erkennbar (noteXref),
        vorhandenes Medium verknuepfen (Route Media mit media) und wieder loesen (UnlinkMedia)."""
        s = U.sitzung("admin")
        a = s.post("Fact", "testbaum", {"tag": "NOTE", "value": "Erste Zeile\nzweite Zeile"}, xref="I2")
        self.assertEqual(True, a.json["ok"], a)
        notiz = next(f for f in self.fakten(s, "I2") if f.get("tag") == "NOTE")
        self.assertEqual(("Erste Zeile\nzweite Zeile", None), (notiz["value"], notiz["noteXref"]))
        # Medium an I1 hochladen, dann dasselbe Medium an I2 haengen, doppelt verknuepfen bleibt einfach
        rumpf, art = manifest.multipart({"title": "Gemeinsames Bild"}, ("bild.png", manifest.PNG, "image/png"))
        req = urllib.request.Request(s.url("/module/_api4webtrees_/Media/testbaum", xref="I1"), data=rumpf, method="POST")
        req.add_header("Content-Type", art); req.add_header("X-CSRF-TOKEN", s.csrf)
        mx = s._senden(req).json["media"]
        self.assertEqual(True, s.post("Media", "testbaum", {"media": mx}, xref="I2").json["ok"])
        self.assertEqual(True, s.post("Media", "testbaum", {"media": mx}, xref="I2").json["ok"])
        ged = umgebung.sql("SELECT i_gedcom FROM wt_individuals WHERE i_id = 'I2'")[0][0]
        self.assertEqual(1, ged.count("1 OBJE @" + mx + "@"))
        self.assertEqual("media-not-found", s.post("Media", "testbaum", {"media": "M999"}, xref="I2").json["error"])
        self.assertEqual(True, s.post("UnlinkMedia", "testbaum", {"media": mx}, xref="I2").json["ok"])
        self.assertNotIn("@" + mx + "@", umgebung.sql("SELECT i_gedcom FROM wt_individuals WHERE i_id = 'I2'")[0][0])

    def test_ort_in_zwei_schreibweisen(self):
        """SQLite: "Ort" und "ort" sind zwei Eintraege der Ortstabelle - Place zaehlt beide und nennt die Schreibweise
        der Mehrheit; PlaceRename nimmt beide mit."""
        s = U.sitzung("admin")
        liste = s.get("Places", "testbaum", list="1").json["places"]
        ort = max(liste, key=lambda o: o["events"])
        vorher = s.get("Place", "testbaum", name=ort["name"]).json
        a = s.post("Fact", "testbaum", {"tag": "RESI", "date": "1900", "place": ort["name"].lower()}, xref="I2")
        self.assertEqual(True, a.json["ok"], a)
        nachher = s.get("Place", "testbaum", name=ort["name"]).json
        self.assertEqual(vorher["events"] + 1, nachher["events"])
        self.assertEqual(vorher["events"] + 1, s.get("Place", "testbaum", name=ort["name"].lower()).json["events"])
        neu = [f for f in self.fakten(s, "I2") if f.get("tag") == "RESI" and (f.get("place") or {}).get("name") == ort["name"].lower()]
        self.assertEqual(True, s.post("DeleteFact", "testbaum", {"factId": neu[0]["id"]}, xref="I2").json["ok"])

    def test_startperson(self):
        """Stufe 24: Info nennt die Startperson wie webtrees; StartPerson setzt die eigene Standardperson, die des
        Stammbaums nur fuer Verwalter."""
        def baum(sitzung):
            return next(b for b in sitzung.info()["trees"] if b["name"] == "testbaum")
        m = U.sitzung("mitglied")
        self.assertTrue(baum(m)["startXref"])
        r = m.post("StartPerson", "testbaum", {"xref": "I2"})
        self.assertEqual((True, "I2", "I2"), (r.json["ok"], r.json["startXref"], r.json["defaultXref"]), r)
        self.assertEqual("I2", baum(m)["startXref"])
        self.assertEqual("not-manager", m.post("StartPerson", "testbaum", {"xref": "I2", "forTree": True}).json["error"])
        self.assertEqual("not-found", m.post("StartPerson", "testbaum", {"xref": "I999"}).json["error"])
        # Eigene Standardperson wieder entfernen: dann gilt die des Stammbaums
        v = U.sitzung("verwalter")
        self.assertEqual(True, v.post("StartPerson", "testbaum", {"xref": "I1", "forTree": True}).json["ok"])
        r = m.post("StartPerson", "testbaum", {"xref": ""})
        self.assertEqual(("", "I1"), (r.json["defaultXref"], r.json["treeDefaultXref"]))
        self.assertEqual("not-logged-in", U.sitzung().post("StartPerson", "testbaum", {"xref": "I1"}).json["error"])

    def test_mein_konto(self):
        """Stufe 25: MyAccount aendert den eigenen angezeigten Namen."""
        m = U.sitzung("mitglied")
        alt = m.info()["user"]["realName"]
        r = m.post("MyAccount", None, {"realName": "  Neuer Name  "})
        self.assertEqual((True, "Neuer Name"), (r.json["ok"], r.json["realName"]), r)
        self.assertEqual("Neuer Name", m.info()["user"]["realName"])
        self.assertEqual("missing-real-name", m.post("MyAccount", None, {"realName": " "}).json["error"])
        self.assertEqual("not-logged-in", U.sitzung().post("MyAccount", None, {"realName": "X"}).json["error"])
        # Spalte real_name: 64 Zeichen; Zeilenumbrueche werden zu Leerzeichen
        self.assertEqual("real-name-too-long", m.post("MyAccount", None, {"realName": "X" * 65}).json["error"])
        self.assertTrue(m.post("MyAccount", None, {"realName": "Ä" * 64}).json["ok"])
        self.assertEqual("Anna Bauer", m.post("MyAccount", None, {"realName": "Anna\nBauer"}).json["realName"])
        self.assertEqual("Anna Bauer", m.info()["user"]["realName"])
        m.post("MyAccount", None, {"realName": alt})

    def test_ausstehendes_geschlecht(self):
        """Stufe 28: Eine ausstehende Aenderung des Geschlechts zeigt der Bearbeiter in person.sex wie im SEX-Fakt und
        mit pending; Mitglieder sehen weiter den freigegebenen Wert, ohne pending."""
        ed, mi, adm = U.sitzung("bearbeiter"), U.sitzung("mitglied"), U.sitzung("admin")

        def ansicht(s):
            p = s.get("Individual", "testbaum", xref="I1").json
            return p["person"]["sex"], [(f["value"], f["pending"]) for f in p["facts"] if f["tag"] == "SEX"]

        self.assertEqual(("M", [("männlich", False)]), ansicht(ed))
        fid = next(f["id"] for f in ed.get("Individual", "testbaum", xref="I1").json["facts"] if f["tag"] == "SEX")
        try:
            r = ed.post("Fact", "testbaum", {"factId": fid, "tag": "SEX", "value": "F"}, xref="I1")
            self.assertEqual((True, True), (r.json["ok"], r.json["pending"]), r)
            self.assertEqual(("F", [("weiblich", True)]), ansicht(ed))
            self.assertEqual(("M", [("männlich", False)]), ansicht(mi))
            liste = ed.get("Individuals", "testbaum", q="Theodor").json["data"]
            self.assertEqual({"F"}, {e["sex"] for e in liste if e["xref"] == "I1"})
            alle = ed.get("Individual", "testbaum", xref="I1").json["facts"]
            self.assertEqual(1, sum(1 for f in alle if f["pending"]))
            self.assertFalse(any(f["pending"] for f in mi.get("Individual", "testbaum", xref="I1").json["facts"]))
        finally:
            adm.post("Reject", "testbaum", {}, xref="I1")
        self.assertEqual(("M", [("männlich", False)]), ansicht(ed))

    def test_anmeldeseite_in_info(self):
        """Stufe 26: Info.loginForm nennt Begruessungstext, Selbstregistrierung und Bedingungen - auch Gaesten."""
        def form():
            return U.sitzung().info()["loginForm"]
        f = form()
        self.assertEqual({"welcomeMessage", "isSelfRegistrationAllowed", "registrationTerms"}, set(f))
        self.assertTrue(f["welcomeMessage"])
        self.assertIsNone(f["registrationTerms"])
        self.assertGreaterEqual(U.sitzung().info()["api"], 26)
        try:
            umgebung.sql("INSERT OR REPLACE INTO wt_site_setting (setting_name, setting_value) VALUES ('SHOW_REGISTER_CAUTION', '1')")
            umgebung.sql("INSERT OR REPLACE INTO wt_site_setting (setting_name, setting_value) VALUES ('USE_REGISTRATION_MODULE', '0')")
            f = form()
            self.assertIn("<p>", f["registrationTerms"])
            self.assertFalse(f["isSelfRegistrationAllowed"])
        finally:
            umgebung.sql("DELETE FROM wt_site_setting WHERE setting_name = 'SHOW_REGISTER_CAUTION'")
            umgebung.sql("INSERT OR REPLACE INTO wt_site_setting (setting_name, setting_value) VALUES ('USE_REGISTRATION_MODULE', '1')")
        self.assertTrue(form()["isSelfRegistrationAllowed"])

    def test_name_aendern_behaelt_unterangaben(self):
        # Beim Aendern des Namens darf nichts verloren gehen: Praefix, Spitzname und Notiz bleiben,
        # GIVN/SURN/NSFX folgen dem neuen Namen.
        s = U.sitzung("admin")
        a = s.post("AddIndividual", "testbaum", {"relation": "none", "given": "Karl", "surname": "Muster", "sex": "M", "dead": True})
        xref = a.json["xref"]
        name = next(f for f in self.fakten(s, xref) if f.get("tag") == "NAME")
        voll = "1 NAME Dr. Karl /Muster/\n2 NPFX Dr.\n2 GIVN Karl\n2 SURN Muster\n2 NICK Kalle\n2 NOTE Namensnotiz"
        self.assertEqual(True, s.post("Fact", "testbaum", {"factId": name["id"], "gedcom": voll}, xref=xref).json["ok"])
        name = next(f for f in self.fakten(s, xref) if f.get("tag") == "NAME")
        a = s.post("Fact", "testbaum", {"factId": name["id"], "value": "Dr. Karl Heinz /Muster-Meier/ jun."}, xref=xref)
        self.assertEqual(True, a.json["ok"], a)
        ged = umgebung.sql("SELECT i_gedcom FROM wt_individuals WHERE i_id = ?", xref)[0][0]
        for zeile in ("1 NAME Dr. Karl Heinz /Muster-Meier/ jun.", "2 NPFX Dr.", "2 NICK Kalle", "2 NOTE Namensnotiz",
                      "2 GIVN Karl Heinz", "2 SURN Muster-Meier", "2 NSFX jun."):
            self.assertIn(zeile, ged)
        self.assertNotIn("2 SURN Muster\n", ged + "\n")

    def test_jahrestag_heute(self):
        heute = datetime.date.today()
        datum = f"{heute.day} {'JAN FEB MAR APR MAY JUN JUL AUG SEP OCT NOV DEC'.split()[heute.month - 1]} {heute.year - 100}"
        s = U.sitzung("admin")
        neu = s.post("AddIndividual", "testbaum", {"relation": "none", "given": "Hundert", "surname": "Jahre", "sex": "M",
                                                    "birthDate": datum, "dead": True})
        self.assertEqual(True, neu.json["ok"], neu)
        a = U.sitzung().get("Anniversaries", "testbaum", days=1)
        treffer = [e for e in a.json["data"] if e["xref"] == neu.json["xref"]]
        self.assertEqual(1, len(treffer), a)
        self.assertEqual((0, 100, "BIRT"), (treffer[0]["inDays"], treffer[0]["years"], treffer[0]["tag"]))
        self.assertEqual(neu.json["xref"], treffer[0]["person"]["xref"])
        self.assertIn("more", a.json)
        self.assertLessEqual(len(a.json["data"]), a.json["total"])

    def test_app_liste_gueltig(self):
        """src/Apps.php: jeder Eintrag vollstaendig, https, Schema eindeutig - die Pruefung, die ein Pull Request bestehen muss."""
        modul = os.path.join(umgebung.HIER, "..")
        code = 'require "src/Apps.php"; echo json_encode([Api4Webtrees\\Apps::check(), array_keys(Api4Webtrees\\Apps::ALL)]);'
        fehler, kennungen = json.loads(subprocess.check_output(["php", "-r", code], cwd=modul, text=True))
        self.assertEqual([], fehler)
        self.assertEqual(["wtand", "wtwin", "wttux", "wtmac"], kennungen[:4])

    def test_seite_app_je_geraet(self):
        """Die Seite App zeigt die Apps fuer das Geraet des Besuchers zuerst; ein Geraet ohne passende App bekommt den Browser-Hinweis."""
        s = U.sitzung("admin")

        def seite(user_agent):
            req = urllib.request.Request(s.url("/module/_api4webtrees_/App"))
            req.add_header("User-Agent", user_agent)
            return s._senden(req).text

        android = seite("Mozilla/5.0 (Linux; Android 14) Mobile")
        self.assertLess(android.index("wtAnd"), android.index("wtWin"))
        self.assertLess(android.index("wtWin"), android.index("wtTux"))
        self.assertIn("webtreesand://connect?", android)
        self.assertNotIn("noch kein eigenes Programm", android)

        windows = seite("Mozilla/5.0 (Windows NT 10.0; Win64; x64)")
        self.assertLess(windows.index("wtWin"), windows.index("wtAnd"))
        self.assertIn("wtwin://connect?", windows)

        # iPhone: die App aus der Liste fuer iOS steht oben, mit Store-Badge und Koppel-Link, kein Browser-Hinweis
        iphone = seite("Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)")
        self.assertNotIn("noch kein eigenes Programm", iphone)
        self.assertIn("webtreesmobile://connect?", iphone)
        self.assertIn("app-store.svg", iphone)
        self.assertLess(iphone.index("webtrees mobile"), iphone.index("wtAnd"))

        mac = seite("Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0)")
        self.assertNotIn("noch kein eigenes Programm", mac)
        self.assertLess(mac.index("wtMac"), mac.index("wtWin"))
        self.assertIn("wtmac://connect?", mac)

        # Verbinden-Seite (Ziel des QR-Codes): ein Knopf je Handy-App mit Schema, Download je App.
        verbinden = s._senden(urllib.request.Request(s.url("/module/_api4webtrees_/Connect"))).text
        self.assertIn('data-scheme="webtreesand"', verbinden)
        self.assertIn("wtAnd herunterladen", verbinden)
        self.assertNotIn("wtWin", verbinden)

    def test_app_abschalten(self):
        """Einstellungen: eine abgehakte App verschwindet von der Seite App und aus der Fusszeile."""
        s = U.sitzung("admin")
        admin_url = s.url("/module/_api4webtrees_/Admin")
        formular = s._senden(urllib.request.Request(admin_url)).text
        alle = re.findall(r'name="apps\[\]" value="([^"]+)"', formular)
        self.assertIn("wttux", alle)
        csrf = re.search(r'name="_csrf" value="([^"]+)"', formular).group(1)

        def speichern(apps):
            daten = urllib.parse.urlencode([("_csrf", csrf), ("trees[]", "testbaum"), ("trees[]", "geheim")] + [("apps[]", a) for a in apps]).encode()
            req = urllib.request.Request(admin_url, data=daten, method="POST")
            req.add_header("Content-Type", "application/x-www-form-urlencoded")
            return s._senden(req)

        try:
            self.assertIn(speichern(["wtand", "wtwin"]).status, (302, 303))
            seite = s._senden(urllib.request.Request(s.url("/module/_api4webtrees_/App/testbaum"))).text
            self.assertNotIn("wtTux", seite)
            self.assertIn("wtWin", seite)
        finally:
            speichern(alle)

        seite = s._senden(urllib.request.Request(s.url("/module/_api4webtrees_/App/testbaum"))).text
        self.assertIn("wtTux", seite)

    def test_ungueltiger_koppelcode(self):
        s = U.sitzung()
        a = s.post("Pair", None, {"code": "0" * 48})
        self.assertEqual(False, a.json["ok"], a)
        self.assertFalse(s.info()["user"]["loggedIn"])


if __name__ == "__main__":
    sys.path.insert(0, umgebung.HIER)
    unittest.main(verbosity=2)
