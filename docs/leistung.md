## Performance

Measured 2026-10-02 with `tests/leistung.py` (generated trees, webtrees 2.2.6, SQLite, PHP 8.5.4 built-in server, x86_64 PC). Median of three calls in seconds; Visitor = not signed in (privacy checks per individual); size = answer for the admin.


### 10000 individuals, 2002 families – GEDCOM import 9.2 s (4.2 MB)

| Route | Parameters | Admin s | Visitor s | Size KB |
|---|---|---:|---:|---:|
| Info |  | 0.01 | 0.01 | 1 |
| Individuals |  | 0.05 | 0.05 | 56 |
| Individuals | page=50 | 0.06 | 0.07 | 56 |
| Individuals | q=Müller | 0.05 | 0.05 | 56 |
| Individuals | q=Anna Hanau scope=all | 0.14 | 0.15 | 55 |
| Individual | xref=I1 | 0.03 | 0.03 | 9 |
| Individual | xref=I9995 | 0.03 | 0.03 | 13 |
| Pedigree | xref=I9995 generations=8 siblings=1 | 0.03 | 0.04 | 33 |
| Descendants | xref=I1 generations=6 | 0.04 | 0.04 | 43 |
| Relationship | xref1=I1 xref2=I9995 (empty answer) | 0.02 | 0.02 | 0 |
| Relationship | xref1=I1 xref2=I361 | 0.03 | 0.03 | 4 |
| Relationship | xref1=I361 xref2=I2 (empty answer) | 0.02 | 0.02 | 0 |
| Family | xref=F1 | 0.02 | 0.02 | 9 |
| Anniversaries | days=1 | 0.06 | 0.06 | 29 |
| Anniversaries | days=14 | 0.32 | 0.36 | 43 |
| Anniversaries | days=60 | 1.19 | 1.30 | 43 |
| Places | q= | 0.01 | 0.01 | 1 |
| Places | list=1 | 0.62 | 0.88 | 2 |
| Place | name=Offenbach, Offenbach, Hessen, Deutschland | 0.46 | 0.54 | 330 |
| Place | name=Hessen, Deutschland | 0.05 | 0.05 | 0 |
| Tags | type=INDI | 0.01 | 0.01 | 1 |
| MediaList |  (empty answer) | 0.01 | 0.01 | 0 |
| Pending |  (empty answer) | 0.01 | 0.01 | 0 |
| Export, all pages (Admin) | 49 pages, 10000 individuals | 13.2 s in total | | |
| Export, all pages (Visitor) | 49 pages, 10000 individuals | 13.9 s in total | | |

For comparison, the same file in Gramps 6.0.6 (free software, SQLite, command line):

| | webtrees + api4webtrees | Gramps |
|---|---:|---:|
| Read the GEDCOM file | 9.2 s | 35.6 s |
| Read everything once (API export, all pages / GEDCOM export) | 13.2 s | 10.8 s |

### 50000 individuals, 9988 families – GEDCOM import 48.6 s (21.3 MB)

| Route | Parameters | Admin s | Visitor s | Size KB |
|---|---|---:|---:|---:|
| Info |  | 0.01 | 0.01 | 1 |
| Individuals |  | 0.07 | 0.08 | 56 |
| Individuals | page=50 | 0.10 | 0.10 | 56 |
| Individuals | q=Müller | 0.08 | 0.08 | 56 |
| Individuals | q=Anna Hanau scope=all | 0.57 | 0.57 | 55 |
| Individual | xref=I1 | 0.06 | 0.07 | 9 |
| Individual | xref=I49995 | 0.07 | 0.07 | 15 |
| Pedigree | xref=I49995 generations=8 siblings=1 | 0.04 | 0.04 | 47 |
| Descendants | xref=I1 generations=6 | 0.04 | 0.04 | 43 |
| Relationship | xref1=I1 xref2=I49995 (empty answer) | 0.08 | 0.07 | 0 |
| Relationship | xref1=I1 xref2=I361 | 0.08 | 0.08 | 4 |
| Relationship | xref1=I361 xref2=I2 (empty answer) | 0.07 | 0.07 | 0 |
| Family | xref=F1 | 0.02 | 0.02 | 9 |
| Anniversaries | days=1 | 0.16 | 0.17 | 48 |
| Anniversaries | days=14 | 1.49 | 1.64 | 48 |
| Anniversaries ⚠ | days=60 | 6.05 | 6.72 | 48 |
| Places | q= | 0.01 | 0.01 | 1 |
| Places ⚠ | list=1 | 3.35 | 4.64 | 2 |
| Place | name=Offenbach, Offenbach, Hessen, Deutschland | 1.53 | 1.96 | 437 |
| Place | name=Hessen, Deutschland | 0.22 | 0.22 | 0 |
| Tags | type=INDI | 0.01 | 0.01 | 1 |
| MediaList |  (empty answer) | 0.01 | 0.01 | 0 |
| Pending |  (empty answer) | 0.01 | 0.01 | 0 |
| Export, all pages (Admin) | 240 pages, 50000 individuals | 72.5 s in total | | |
| Export, all pages (Visitor) | 240 pages, 50000 individuals | 79.1 s in total | | |

For comparison, the same file in Gramps 6.0.6 (free software, SQLite, command line):

| | webtrees + api4webtrees | Gramps |
|---|---:|---:|
| Read the GEDCOM file | 48.6 s | 221.9 s |
| Read everything once (API export, all pages / GEDCOM export) | 72.5 s | 53.5 s |
