"""Erzeugt einen grossen, gleichbleibenden Test-GEDCOM fuer den Leistungscheck (tests/leistung.py).

Generationen von Familien ab 1600, je Person Geburt/Taufe/Tod/Begraebnis mit Ort, Beruf, gelegentlich Notizen.
Gleicher Zufallsstart = gleicher Baum, damit Messungen vergleichbar bleiben.
"""
import random

VORNAMEN_M = "Johann Georg Friedrich Wilhelm Heinrich Karl Peter Jakob Andreas Michael Christian Philipp".split()
VORNAMEN_W = "Anna Maria Elisabeth Katharina Margaretha Barbara Christina Sophia Johanna Dorothea Eva Magdalena".split()
NACHNAMEN = ("Müller Schmidt Schneider Fischer Weber Meyer Wagner Becker Schulz Hoffmann Koch Bauer Richter Klein "
             "Wolf Schröder Neumann Schwarz Zimmermann Braun Krüger Hofmann Hartmann Lange Werner Krause").split()
ORTE = [f"{o}, {k}, Hessen, Deutschland" for o, k in [
    ("Offenbach", "Offenbach"), ("Hanau", "Main-Kinzig"), ("Gelnhausen", "Main-Kinzig"), ("Büdingen", "Wetterau"),
    ("Friedberg", "Wetterau"), ("Nidda", "Wetterau"), ("Seligenstadt", "Offenbach"), ("Langen", "Offenbach"),
    ("Dieburg", "Darmstadt-Dieburg"), ("Babenhausen", "Darmstadt-Dieburg"), ("Groß-Umstadt", "Darmstadt-Dieburg"),
    ("Schlüchtern", "Main-Kinzig"), ("Wächtersbach", "Main-Kinzig"), ("Ortenberg", "Wetterau")]]
BERUFE = "Bauer Schmied Müller Weber Schneider Leinenweber Tagelöhner Wirt Lehrer Pfarrer Zimmermann Bäcker".split()
MONATE = "JAN FEB MAR APR MAY JUN JUL AUG SEP OCT NOV DEC".split()


def datum(r, jahr):
    return f"{r.randint(1, 28)} {r.choice(MONATE)} {jahr}"


def erzeugen(personen: int, pfad: str, start: int = 1600, samen: int = 42):
    r = random.Random(samen)
    zeilen = ["0 HEAD", "1 SOUR api4webtrees-leistung", "1 GEDC", "2 VERS 5.5.1", "2 FORM LINEAGE-LINKED", "1 CHAR UTF-8"]
    indi, fam = [], []
    n_i = n_f = 0

    def person(sex, jahr, nachname, famc=None):
        nonlocal n_i
        n_i += 1
        x = f"I{n_i}"
        tod = jahr + r.randint(1, 85)
        p = {"x": x, "sex": sex, "jahr": jahr, "name": nachname, "famc": famc, "fams": [],
             "zeilen": [f"0 @{x}@ INDI",
                        f"1 NAME {r.choice(VORNAMEN_M if sex == 'M' else VORNAMEN_W)} /{nachname}/",
                        f"1 SEX {sex}",
                        "1 BIRT", f"2 DATE {datum(r, jahr)}", f"2 PLAC {r.choice(ORTE)}",
                        "1 CHR", f"2 DATE {datum(r, jahr)}", f"2 PLAC {r.choice(ORTE)}"]}
        if tod < 2026:
            p["zeilen"] += ["1 DEAT", f"2 DATE {datum(r, tod)}", f"2 PLAC {r.choice(ORTE)}",
                            "1 BURI", f"2 DATE {datum(r, tod)}", f"2 PLAC {r.choice(ORTE)}"]
        if sex == "M" and r.random() < 0.8:
            p["zeilen"].append(f"1 OCCU {r.choice(BERUFE)}")
        if r.random() < 0.1:
            p["zeilen"].append("1 NOTE Laut Kirchenbuch " + " ".join(r.choice(NACHNAMEN) for _ in range(12)))
        indi.append(p)
        return p

    # Stammeltern: je 20 Paare, danach Generation um Generation Kinder, die wieder heiraten.
    ledig = [person("M", start + r.randint(0, 20), r.choice(NACHNAMEN)) for _ in range(40)]
    while n_i < personen:
        maenner = [p for p in ledig if p["sex"] == "M"]
        r.shuffle(maenner)
        neu = []
        for mann in maenner:
            if n_i >= personen:
                break
            n_f += 1
            f = f"F{n_f}"
            frau = person("F", mann["jahr"] + r.randint(-5, 3), r.choice(NACHNAMEN))
            mann["fams"].append(f); frau["fams"].append(f)
            kinder = []
            for k in range(r.randint(1, 7)):
                if n_i >= personen:
                    break
                kinder.append(person(r.choice("MF"), mann["jahr"] + 22 + 2 * k + r.randint(0, 3), mann["name"], f))
            fam.append({"x": f, "zeilen": [f"0 @{f}@ FAM", f"1 HUSB @{mann['x']}@", f"1 WIFE @{frau['x']}@",
                                          "1 MARR", f"2 DATE {datum(r, mann['jahr'] + 21)}", f"2 PLAC {r.choice(ORTE)}"]
                        + [f"1 CHIL @{c['x']}@" for c in kinder]})
            neu += kinder
        ledig = neu or [person("M", start, r.choice(NACHNAMEN)) for _ in range(20)]

    with open(pfad, "w", encoding="utf-8") as out:
        for p in indi:
            z = p["zeilen"] + ([f"1 FAMC @{p['famc']}@"] if p["famc"] else []) + [f"1 FAMS @{f}@" for f in p["fams"]]
            out.write("\n".join(z) + "\n")
        for f in fam:
            out.write("\n".join(f["zeilen"]) + "\n")
        out.write("0 TRLR\n")
    # Kopf vorn einfuegen (erst jetzt, damit die Datei in einem Zug geschrieben wird)
    inhalt = open(pfad, encoding="utf-8").read()
    open(pfad, "w", encoding="utf-8").write("\n".join(zeilen) + "\n" + inhalt)
    return n_i, n_f


if __name__ == "__main__":
    import sys
    print(erzeugen(int(sys.argv[1]), sys.argv[2]))
