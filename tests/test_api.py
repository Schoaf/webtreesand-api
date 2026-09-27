#!/usr/bin/env python3
"""Tests fuer api4webtrees gegen ein echtes webtrees (siehe umgebung.py). Aufruf: python3 tests/test_api.py

Kern ist der Leck-Test: Die Testdaten tragen Markierungswoerter in allem, was eine Rolle NICHT sehen darf (lebende
Person, vertrauliche Person, Ereignis mit RESN privacy, privater Baum). Jede Rolle ruft jede Leseroute auf - kein
Markierungswort darf in irgendeiner Antwort stehen. So faellt ein Leck auf, egal ueber welches Feld es kaeme.
"""
import json
import sys
import unittest

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
    ]
    return aufrufe


class Lecktest(unittest.TestCase):
    def pruefen(self, benutzer):
        s = U.sitzung(benutzer)
        verboten = VERBOTEN[benutzer]
        for baum in ("testbaum", "geheim"):
            for aktion, b, params in leseaufrufe(baum):
                a = s.get(aktion, b, **params)
                self.assertLess(a.status, 500, f"{benutzer or 'Gast'}: {aktion} {b} {params} -> {a}")
                # Die Suche nennt das Suchwort in der Antwort - das ist kein Leck.
                text = a.text.replace(json.dumps(params.get("q", "")), '""')
                for wort in verboten:
                    # Ortsnamen schuetzt webtrees nicht (Ortsliste, Ortsvorschlaege: SearchService::searchPlaces) -
                    # die Route Places nutzt dieselbe Funktion, nur fuer Bearbeiter. Kein Leck des Moduls.
                    if aktion == "Places" and wort == KONFIDENZ:
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

    def test_ungueltiger_koppelcode(self):
        s = U.sitzung()
        a = s.post("Pair", None, {"code": "0" * 48})
        self.assertEqual(False, a.json["ok"], a)
        self.assertFalse(s.info()["user"]["loggedIn"])


if __name__ == "__main__":
    sys.path.insert(0, umgebung.HIER)
    unittest.main(verbosity=2)
