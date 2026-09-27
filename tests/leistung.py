#!/usr/bin/env python3
"""Leistungscheck: Antwortzeiten aller Leserouten auf grossen Baeumen. Kein Pass/Fail-Test, sondern eine Messung.

  python3 tests/leistung.py 10000 50000     Baeume mit so vielen Personen erzeugen, einspielen, messen

Gemessen wird als Admin (sieht alles) und als Gast (Privatsphaere-Pruefung je Person), je Route der Median aus drei
Aufrufen. Dazu der komplette Export (alle Seiten), den die Apps zum Offline-Speichern abrufen.
"""
import datetime
import os
import re
import statistics
import sys
import time

import grossbaum
import umgebung

LANGSAM = 2.0  # Sekunden: ab hier ist eine Route am Handy spuerbar zaeh


def messen(s, aktion, baum, **params):
    zeiten, a = [], None
    for _ in range(3):
        t = time.perf_counter()
        a = s.get(aktion, baum, **params)
        zeiten.append(time.perf_counter() - t)
    return statistics.median(zeiten), a


def export_gesamt(s, baum):
    t = time.perf_counter()
    seite, personen, seiten = 1, 0, 0
    while True:
        a = s.get("Export", baum, page=seite)
        seiten += 1
        personen += len(a.json.get("individuals", []))
        if not a.json.get("nextPage"):
            break
        seite = a.json["nextPage"]
    return time.perf_counter() - t, seiten, personen, len(a.text)


def main(groessen):
    u = umgebung.Umgebung().aufbauen()
    # Alles, was print() ausgibt, landet auch in docs/leistung.md (steht dann in docs/API.md).
    ausgabe = []
    global print
    _print = print
    print = lambda *a, **k: (_print(*a, **k), ausgabe.append(" ".join(str(x) for x in a)))  # noqa: E731
    try:
        for n in groessen:
            baum = f"gross{n}"
            ged = os.path.join(umgebung.ARBEIT, f"{baum}.ged")
            _, familien = grossbaum.erzeugen(n, ged)
            umgebung.wt("tree", baum, "--create", f"--title=Gross {n}")
            t = time.perf_counter()
            umgebung.wt("tree-import", baum, ged)
            import_s = time.perf_counter() - t
            print(f"\n## {n} individuals, {familien} families – GEDCOM import {import_s:.1f} s "
                  f"({os.path.getsize(ged) / 1e6:.1f} MB)\n")

            tief = f"I{n - 5}"  # juengste Generation: lange Ahnenreihe
            # Ein entfernter Nachfahre von I1 (letzter im Nachfahrenbaum), damit die Wegsuche wirklich laeuft.
            nachfahren = u.sitzung("admin").get("Descendants", baum, xref="I1", generations=12).text
            fern = re.findall(r'"xref":"(I\d+)"', nachfahren)[-1]
            aufrufe = [
                ("Info", None, {}),
                ("Individuals", baum, {}),
                ("Individuals", baum, {"page": 50}),
                ("Individuals", baum, {"q": "Müller"}),
                ("Individuals", baum, {"q": "Anna Hanau", "scope": "all"}),
                ("Individual", baum, {"xref": "I1"}),
                ("Individual", baum, {"xref": tief}),
                ("Pedigree", baum, {"xref": tief, "generations": 8, "siblings": 1}),
                ("Descendants", baum, {"xref": "I1", "generations": 6}),
                ("Relationship", baum, {"xref1": "I1", "xref2": tief}),   # ohne Verwandtschaft
                ("Relationship", baum, {"xref1": "I1", "xref2": fern}),   # Ahn und ferner Nachfahre
                ("Relationship", baum, {"xref1": fern, "xref2": "I2"}),
                ("Family", baum, {"xref": "F1"}),
                ("Anniversaries", baum, {"days": 1}),    # Erinnerung am Handy
                ("Anniversaries", baum, {"days": 14}),   # Startseite der Apps
                ("Anniversaries", baum, {"days": 60}),   # groesster erlaubter Wert
                ("Places", baum, {"q": ""}),
                ("Tags", baum, {"type": "INDI"}),
                ("MediaList", baum, {}),
                ("Pending", baum, {}),
            ]
            sitzungen = {"Admin": u.sitzung("admin"), "Visitor": u.sitzung()}
            print("| Route | Parameters | Admin s | Visitor s | Size KB |")
            print("|---|---|---:|---:|---:|")
            for aktion, b, params in aufrufe:
                werte = {}
                for wer, s in sitzungen.items():
                    werte[wer] = messen(s, aktion, b, **params)
                ta, aa = werte["Admin"]
                tg, _ = werte["Visitor"]
                warnung = " ⚠" if max(ta, tg) > LANGSAM or aa.status >= 500 else ""
                p = " ".join(f"{k}={v}" for k, v in params.items())
                klein = f" `{aa.text[:70]}`" if len(aa.text) < 300 else ""
                print(f"| {aktion}{warnung} | {p}{klein} | {ta:.2f} | {tg:.2f} | {len(aa.text) / 1024:.0f} |")
            for wer, s in sitzungen.items():
                t, seiten, personen, _ = export_gesamt(s, baum)
                print(f"| Export, all pages ({wer}) | {seiten} pages, {personen} individuals | {t:.1f} s in total | | |")
    finally:
        u.abbauen()
    import platform, subprocess
    php = subprocess.run([umgebung.PHP, "-r", "echo PHP_VERSION;"], capture_output=True, text=True).stdout
    kopf = ["## Performance", "",
            f"Measured {datetime.date.today()} with `tests/leistung.py` (generated trees, webtrees {os.environ.get('WEBTREES', '2.2.6')}, "
            f"SQLite, PHP {php} built-in server, {platform.processor() or platform.machine()} PC). Median of three calls in seconds; "
            "Visitor = not signed in (privacy checks per individual); size = answer for the admin.", ""]
    with open(os.path.join(umgebung.MODUL, "docs", "leistung.md"), "w") as f:
        f.write("\n".join(kopf + [z.replace("## ", "### ") for z in ausgabe]) + "\n")


if __name__ == "__main__":
    main([int(a) for a in sys.argv[1:]] or [10000])
