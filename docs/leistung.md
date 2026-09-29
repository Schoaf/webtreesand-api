## Performance

Measured 2026-09-27 with `tests/leistung.py` (generated trees, webtrees 2.2.6, SQLite, PHP 8.5.4 built-in server, x86_64 PC). Median of three calls in seconds; Visitor = not signed in (privacy checks per individual); size = answer for the admin.


### 10000 individuals, 2002 families – GEDCOM import 9.7 s (4.2 MB)

| Route | Parameters | Admin s | Visitor s | Size KB |
|---|---|---:|---:|---:|
| Info |  | 0.01 | 0.01 | 1 |
| Individuals |  | 0.05 | 0.06 | 56 |
| Individuals | page=50 | 0.07 | 0.07 | 56 |
| Individuals | q=Müller | 0.06 | 0.06 | 56 |
| Individuals | q=Anna Hanau scope=all | 0.16 | 0.15 | 55 |
| Individual | xref=I1 | 0.02 | 0.02 | 9 |
| Individual | xref=I9995 | 0.02 | 0.02 | 12 |
| Pedigree | xref=I9995 generations=8 siblings=1 | 0.04 | 0.04 | 33 |
| Descendants | xref=I1 generations=6 | 0.04 | 0.04 | 43 |
| Relationship | xref1=I1 xref2=I9995 (empty answer) | 0.02 | 0.02 | 0 |
| Relationship | xref1=I1 xref2=I361 | 0.03 | 0.03 | 4 |
| Relationship | xref1=I361 xref2=I2 (empty answer) | 0.02 | 0.02 | 0 |
| Family | xref=F1 | 0.02 | 0.03 | 9 |
| Anniversaries | days=1 | 0.06 | 0.06 | 26 |
| Anniversaries | days=14 | 0.33 | 0.36 | 42 |
| Anniversaries | days=60 | 1.23 | 1.33 | 42 |
| Places | q= | 0.01 | 0.01 | 1 |
| Tags | type=INDI | 0.01 | 0.01 | 1 |
| MediaList | (empty answer) | 0.01 | 0.01 | 0 |
| Pending | (empty answer) | 0.01 | 0.01 | 0 |
| Export, all pages (Admin) | 49 pages, 10000 individuals | 13.2 s in total | | |
| Export, all pages (Visitor) | 49 pages, 10000 individuals | 13.9 s in total | | |

For comparison, the same file in Gramps 6.0.6 (free software, SQLite, command line):

| | webtrees + api4webtrees | Gramps |
|---|---:|---:|
| Read the GEDCOM file | 9.7 s | 36.2 s |
| Read everything once (API export, all pages / GEDCOM export) | 13.2 s | 10.9 s |

### 50000 individuals, 9988 families – GEDCOM import 50.2 s (21.3 MB)

| Route | Parameters | Admin s | Visitor s | Size KB |
|---|---|---:|---:|---:|
| Info |  | 0.02 | 0.02 | 1 |
| Individuals |  | 0.08 | 0.08 | 56 |
| Individuals | page=50 | 0.11 | 0.11 | 56 |
| Individuals | q=Müller | 0.09 | 0.09 | 56 |
| Individuals | q=Anna Hanau scope=all | 0.61 | 0.60 | 55 |
| Individual | xref=I1 | 0.02 | 0.02 | 9 |
| Individual | xref=I49995 | 0.02 | 0.02 | 14 |
| Pedigree | xref=I49995 generations=8 siblings=1 | 0.04 | 0.05 | 47 |
| Descendants | xref=I1 generations=6 | 0.04 | 0.04 | 43 |
| Relationship | xref1=I1 xref2=I49995 (empty answer) | 0.08 | 0.08 | 0 |
| Relationship | xref1=I1 xref2=I361 | 0.08 | 0.08 | 4 |
| Relationship | xref1=I361 xref2=I2 (empty answer) | 0.07 | 0.08 | 0 |
| Family | xref=F1 | 0.02 | 0.02 | 9 |
| Anniversaries | days=1 | 0.16 | 0.17 | 45 |
| Anniversaries | days=14 | 1.46 | 1.61 | 45 |
| Anniversaries ⚠ | days=60 | 6.32 | 6.84 | 45 |
| Places | q= | 0.01 | 0.01 | 1 |
| Tags | type=INDI | 0.01 | 0.01 | 1 |
| MediaList | (empty answer) | 0.01 | 0.01 | 0 |
| Pending | (empty answer) | 0.01 | 0.01 | 0 |
| Export, all pages (Admin) | 240 pages, 50000 individuals | 67.3 s in total | | |
| Export, all pages (Visitor) | 240 pages, 50000 individuals | 71.1 s in total | | |

For comparison, the same file in Gramps 6.0.6 (free software, SQLite, command line):

| | webtrees + api4webtrees | Gramps |
|---|---:|---:|
| Read the GEDCOM file | 50.2 s | 208.3 s |
| Read everything once (API export, all pages / GEDCOM export) | 67.3 s | 52.3 s |
