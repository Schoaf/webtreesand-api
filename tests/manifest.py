#!/usr/bin/env python3
"""API-Manifest: erzeugt docs/openapi.json und docs/API.md.  Aufruf: python3 tests/manifest.py

Die Beschreibungen stehen hier von Hand (ROUTEN). Die Antwortschemas werden NICHT abgeschrieben, sondern aus echten
Antworten abgeleitet: Das Skript baut die Testumgebung (umgebung.py), ruft jede Route als Gast, Mitglied, Bearbeiter
und Admin auf und fasst die Antworten zu einem Schema zusammen. test_api.py prueft danach jede Antwort gegen das
Manifest - aendert sich eine Antwort, ohne dass das Manifest neu erzeugt wurde, wird ein Test rot.
"""
import base64
import datetime
import json
import os
import re
import sys
import urllib.request
import uuid

import test_api
import umgebung

HIER = umgebung.HIER
DOCS = os.path.join(umgebung.MODUL, "docs")
OPENAPI = os.path.join(DOCS, "openapi.json")
ROUTE = "/module/_api4webtrees_/{action}"

ROLLEN = {"visitor": "anyone (visitors see only what webtrees shows them)", "member": "signed in, member of the tree",
          "editor": "editor of the tree", "moderator": "moderator of the tree", "admin": "site administrator"}

P = lambda name, beschreibung, typ="string", pflicht=False: {  # noqa: E731
    "name": name, "in": "query", "required": pflicht, "description": beschreibung, "schema": {"type": typ}}
XREF = P("xref", "Record identifier, e.g. `I123`", pflicht=True)

# (Methode, Aktion, mit Baum, Stufe, Rolle, Kurzbeschreibung, Parameter, Rumpf)
ROUTEN = [
    ("get", "Info", False, 1, "visitor",
     "Entry point: versions, API level, signed-in user, the trees this user can see (with role and rights), "
     "CSRF token for POST requests and the largest accepted upload.", [], None),
    ("get", "Individuals", True, 1, "visitor",
     "List of individuals, sorted by name, 50 per page. `q` filters by name; with `scope=all` the words may appear "
     "anywhere in the visible data (place, year, occupation …).",
     [P("q", "Search words"), P("page", "Page, from 1", "integer"), P("scope", "`all`: search all visible data")], None),
    ("get", "Individual", True, 1, "visitor",
     "One individual with facts, parent/spouse/step families, media and – with `relativeTo` – the relationship "
     "to another individual.", [XREF, P("relativeTo", "Relationship relative to this individual (default: own individual)")], None),
    ("get", "Family", True, 1, "visitor", "One family with spouses, children and facts.",
     [P("xref", "Family identifier, e.g. `F12`", pflicht=True)], None),
    ("get", "Pedigree", True, 1, "visitor",
     "Ancestors as a list with Kekulé numbers (1 = root, 2 = father, 3 = mother …), up to 12 generations; "
     "`siblings=1` adds each ancestor's siblings.",
     [XREF, P("generations", "1–12, default 4", "integer"), P("siblings", "`1`: include siblings (level 15)", "integer")], None),
    ("get", "Descendants", True, 1, "visitor", "Descendants as a nested tree, up to 10 generations.",
     [XREF, P("generations", "1–10, default 3", "integer")], None),
    ("get", "Relationship", True, 13, "visitor",
     "How two individuals are related – shortest paths through the families like the webtrees chart "
     "“Relationships”, at most 5, each step with its relation and label.",
     [P("xref1", "From", pflicht=True), P("xref2", "To", pflicht=True), P("ancestors", "`1`: only via common ancestors", "integer")], None),
    ("get", "Export", True, 17, "visitor",
     "The whole visible tree page by page (250 records): first individuals, then families, linked by identifiers "
     "only. Individuals and families that are linked but not visible come as placeholders (`private: true`).",
     [P("page", "Page, from 1", "integer")], None),
    ("get", "MediaList", True, 2, "visitor", "All media objects, newest first, 60 per page, with up to three linked names.",
     [P("page", "Page, from 1", "integer")], None),
    ("get", "Anniversaries", True, 4, "visitor",
     "Birthdays, weddings and deaths in the next `days` days. At most 100 entries: per day living people first, "
     "then round anniversaries (25, 50 …); `total` and `more` tell whether there were more (since 1.9.6).",
     [P("days", "1–60, default 14", "integer")], None),
    ("get", "Bookmarks", True, 11, "member", "The signed-in user's bookmarks in this tree (user setting, all clients).", [], None),
    ("get", "Pending", True, 5, "moderator", "Records with pending changes.", [], None),
    ("get", "Tags", True, 1, "visitor", "Facts and events the client may offer for adding, with labels.",
     [P("type", "`INDI` or `FAM`", pflicht=True)], None),
    ("get", "Places", True, 8, "editor",
     "Place suggestions while typing, like webtrees' own autocomplete. `Berlin, Deu` searches per level.",
     [P("q", "Beginning or part of the place name")], None),
    ("post", "Fact", True, 1, "editor",
     "Add or change a fact or event. Unmentioned sub-lines (sources, media …) are kept when changing.",
     [XREF], "`{factId?, tag, value?, date?, place?, note?}` or `{factId?, gedcom: \"1 BIRT\\n2 DATE …\"}`"),
    ("post", "DeleteFact", True, 1, "editor", "Delete a fact or event (not links like FAMC/FAMS).", [XREF], "`{factId}`"),
    ("post", "AddIndividual", True, 1, "editor", "Create an individual and link it as child, spouse, father or mother.", [],
     "`{relation: child|spouse|father|mother|none, relativeTo?, family?, given, surname, sex: M|F|U, birthDate?, "
     "birthPlace?, dead?, deathDate?, deathPlace?, marriageDate?, marriagePlace?, facts?: [...]}`"),
    ("post", "Link", True, 8, "editor", "Link two existing individuals (like AddIndividual without a new individual).", [],
     "`{individual, relation: child|spouse|father|mother, relativeTo, family?, marriageDate?, marriagePlace?}`"),
    ("post", "Unlink", True, 4, "editor", "Remove an individual from a family; the individual stays.", [],
     "`{family, individual}`"),
    ("post", "DeleteRecord", True, 4, "editor", "Delete a record with webtrees' own logic (links are removed too).", [XREF], None),
    ("post", "Media", True, 1, "editor", "Upload a file as a media object and link it.", [XREF],
     "`multipart/form-data`: `file`, `title?`, `note?`"),
    ("post", "UnlinkMedia", True, 8, "editor", "Unlink a media object; object and file stay.", [XREF], "`{media}`"),
    ("post", "PrimaryMedia", True, 8, "editor", "Make a linked image the main photo.", [XREF], "`{media}`"),
    ("post", "Bookmarks", True, 11, "member", "Add or remove a bookmark; answers with the whole list.", [], "`{xref, add: true|false}`"),
    ("post", "Accept", True, 5, "moderator", "Accept pending changes of one record, or of the whole tree without `xref`.",
     [P("xref", "Record, optional")], None),
    ("post", "Reject", True, 5, "moderator", "Reject pending changes of one record, or of the whole tree without `xref`.",
     [P("xref", "Record, optional")], None),
    ("post", "Pair", False, 6, "visitor",
     "Redeem the one-time code from the page “App”: afterwards this session is signed in as that user.", [], "`{code}`"),
]

FEHLER = sorted(set(re.findall(r"error\((\d+), '([a-z-]+)'\)", "".join(
    open(os.path.join(umgebung.MODUL, f)).read() for f in
    ["Api4WebtreesModule.php", "src/ReadActions.php", "src/WriteActions.php", "src/AppPages.php"]))), key=lambda e: (e[0], e[1]))


# ── Schema aus Beispielen ────────────────────────────────────────────────────────────────────────────────────────

def typ(v):
    return {dict: "object", list: "array", str: "string", bool: "boolean", int: "integer", float: "number",
            type(None): "null"}[type(v)]


def ableiten(werte):
    """Ein JSON-Schema, das alle Beispielwerte beschreibt: Felder, die immer da sind, werden Pflicht."""
    typen = sorted({typ(v) for v in werte})
    if "integer" in typen and "number" in typen:
        typen.remove("integer")
    s = {"type": typen[0] if len(typen) == 1 else typen}
    objekte = [v for v in werte if isinstance(v, dict)]
    if objekte:
        schluessel = sorted({k for o in objekte for k in o})
        s["properties"] = {k: ableiten([o[k] for o in objekte if k in o]) for k in schluessel}
        pflicht = [k for k in schluessel if all(k in o for o in objekte)]
        if pflicht:
            s["required"] = pflicht
        s["additionalProperties"] = False
    listen = [v for v in werte if isinstance(v, list)]
    if listen:
        elemente = [e for l in listen for e in l]
        s["items"] = ableiten(elemente) if elemente else {}
    return s


def pruefen(wert, schema, pfad="$"):
    """Kleiner Validator fuer die abgeleiteten Schemas. Liefert eine Liste von Abweichungen."""
    if not schema:
        return []
    erlaubt = schema["type"] if isinstance(schema["type"], list) else [schema["type"]]
    t = typ(wert)
    if t not in erlaubt and not (t == "integer" and "number" in erlaubt):
        return [f"{pfad}: {t} statt {'/'.join(erlaubt)}"]
    fehler = []
    if isinstance(wert, dict) and "properties" in schema:
        for k in schema.get("required", []):
            if k not in wert:
                fehler.append(f"{pfad}.{k} fehlt")
        for k, v in wert.items():
            if k not in schema["properties"]:
                fehler.append(f"{pfad}.{k} steht nicht im Manifest")
            else:
                fehler += pruefen(v, schema["properties"][k], f"{pfad}.{k}")
    if isinstance(wert, list) and "items" in schema:
        for i, e in enumerate(wert[:50]):
            fehler += pruefen(e, schema["items"], f"{pfad}[{i}]")
    return fehler


_API = None


def schema_fuer(methode, aktion):
    global _API
    if _API is None:
        with open(OPENAPI) as f:
            _API = json.load(f)
    api = _API
    for pfad, eintrag in api["paths"].items():
        op = eintrag.get(methode)
        if op and op["operationId"] == f"{methode}{aktion}":
            return op["responses"]["200"]["content"]["application/json"]["schema"]
    return None


# ── Beispiele sammeln ───────────────────────────────────────────────────────────────────────────────────────────

def multipart(felder, datei):
    grenze = uuid.uuid4().hex
    teile = []
    for k, v in felder.items():
        teile.append(f"--{grenze}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n".encode())
    name, inhalt, art = datei
    teile.append(f"--{grenze}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{name}\"\r\n"
                 f"Content-Type: {art}\r\n\r\n".encode() + inhalt + b"\r\n")
    teile.append(f"--{grenze}--\r\n".encode())
    return b"".join(teile), f"multipart/form-data; boundary={grenze}"


PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==")


def sammeln(u):
    beispiele = {}

    def merken(methode, aktion, antwort):
        if antwort.json is not None and antwort.json.get("ok") is not False:
            beispiele.setdefault((methode, aktion), []).append(antwort.json)

    admin = u.sitzung("admin")
    # Daten, damit jede Route etwas zu zeigen hat: Foto, Merkliste, Jahrestag heute, ausstehende Aenderung.
    heute = datetime.date.today()
    monat = "JAN FEB MAR APR MAY JUN JUL AUG SEP OCT NOV DEC".split()[heute.month - 1]
    neu = admin.post("AddIndividual", "testbaum", {"relation": "child", "relativeTo": "I4", "family": "F2", "given": "Hanna",
                                                    "surname": "Offen", "sex": "F", "dead": True,
                                                    "birthDate": f"{heute.day} {monat} {heute.year - 150}"})
    merken("post", "AddIndividual", neu)
    kind = neu.json["xref"]
    rumpf, art = multipart({"title": "Testbild"}, ("bild.png", PNG, "image/png"))
    req = urllib.request.Request(admin.url("/module/_api4webtrees_/Media/testbaum", xref="I1"), data=rumpf, method="POST")
    req.add_header("Content-Type", art)
    req.add_header("X-CSRF-TOKEN", admin.csrf)
    medien = admin._senden(req)
    merken("post", "Media", medien)
    m = medien.json.get("media")
    merken("post", "PrimaryMedia", admin.post("PrimaryMedia", "testbaum", {"media": m}, xref="I1"))
    merken("post", "Bookmarks", admin.post("Bookmarks", "testbaum", {"xref": "I1", "add": True}))
    fakt = admin.post("Fact", "testbaum", {"tag": "OCCU", "value": "Manifestberuf", "date": "1850"}, xref="I4")
    merken("post", "Fact", fakt)
    occu = [f for f in admin.get("Individual", "testbaum", xref="I4").json["facts"] if f.get("tag") == "OCCU"]
    merken("post", "DeleteFact", admin.post("DeleteFact", "testbaum", {"factId": occu[0]["id"]}, xref="I4"))
    ehe = admin.post("AddIndividual", "testbaum", {"relation": "none", "given": "Ella", "surname": "Neu", "sex": "F", "dead": True})
    link = admin.post("Link", "testbaum", {"individual": ehe.json["xref"], "relation": "spouse", "relativeTo": "I4"})
    merken("post", "Link", link)
    merken("post", "Unlink", admin.post("Unlink", "testbaum", {"family": link.json["family"], "individual": ehe.json["xref"]}))
    # Koppeln: die Seite "App" legt den Einmal-Code an, eine frische Sitzung loest ihn ein.
    seite = admin._senden(urllib.request.Request(admin.url("/module/_api4webtrees_/App")))
    code = re.findall(r"[0-9a-f]{48}", seite.text)[0]
    merken("post", "Pair", u.sitzung().post("Pair", None, {"code": code}))
    merken("post", "DeleteRecord", admin.post("DeleteRecord", "testbaum", {}, xref=ehe.json["xref"]))
    bearbeiter = u.sitzung("bearbeiter")
    bearbeiter.post("Fact", "testbaum", {"tag": "OCCU", "value": "Wartet"}, xref="I2")
    bearbeiter.post("Fact", "testbaum", {"tag": "OCCU", "value": "Wartet auch"}, xref="I1")

    for benutzer in (None, "mitglied", "bearbeiter", "verwalter", "admin"):
        s = u.sitzung(benutzer)
        for aktion, b, params in test_api.leseaufrufe("testbaum") + [("Individual", "testbaum", {"xref": kind}),
                                                                      ("Individual", "testbaum", {"xref": "I1", "relativeTo": "I3"})]:
            merken("get", aktion, s.get(aktion, b, **params))

    merken("post", "UnlinkMedia", admin.post("UnlinkMedia", "testbaum", {"media": m}, xref="I1"))
    merken("post", "Reject", admin.post("Reject", "testbaum", {}, xref="I2"))
    merken("post", "Accept", admin.post("Accept", "testbaum", {}))
    return beispiele


# ── Ausgabe ────────────────────────────────────────────────────────────────────────────────────────────────────

def openapi(beispiele):
    fehler_schema = {"type": "object", "required": ["ok", "error", "status"], "properties": {
        "ok": {"const": False}, "error": {"type": "string", "enum": sorted({c for _, c in FEHLER})},
        "status": {"type": "integer", "description": "The intended HTTP status (the response itself is 200)"}}}
    pfade = {}
    for methode, aktion, baum, stufe, rolle, text, params, rumpf in ROUTEN:
        pfad = ROUTE.format(action=aktion) + ("/{tree}" if baum else "")
        werte = beispiele.get((methode, aktion), [])
        op = {
            "operationId": f"{methode}{aktion}", "summary": text.split(". ")[0].rstrip(".") + ".", "description": text,
            "x-api-level": stufe, "x-minimum-role": rolle,
            "parameters": ([{"name": "tree", "in": "path", "required": True, "schema": {"type": "string"},
                             "description": "Tree name (`Info.trees[].name`)"}] if baum else []) + params,
            "responses": {"200": {"description": "Result – or an error object (`ok: false`, see `Error`)",
                                  "content": {"application/json": {"schema": ableiten(werte) if werte else {}}}}},
        }
        if methode == "post":
            op["parameters"].append({"name": "X-CSRF-TOKEN", "in": "header", "required": True,
                                     "schema": {"type": "string"}, "description": "`Info.csrf`"})
            if rumpf:
                op["x-body"] = rumpf
        pfade.setdefault(pfad, {})[methode] = op
    return {
        "openapi": "3.1.0",
        "info": {"title": "api4webtrees", "version": open(os.path.join(umgebung.MODUL, "latest-version.txt")).read().strip(),
                 "x-api-level": int(re.search(r"API_VERSION = (\d+)", open(os.path.join(umgebung.MODUL, "Api4WebtreesModule.php")).read()).group(1)),
                 "description": "JSON API for webtrees 2.2. Generated by tests/manifest.py from real responses – do not edit by hand."},
        "servers": [{"url": "{webtrees}/index.php?route=", "variables": {"webtrees": {"default": "https://example.org/webtrees"}}}],
        "paths": pfade,
        "components": {"schemas": {"Error": fehler_schema}},
    }


def markdown(api):
    z = [f"# api4webtrees API {api['info']['version']} (level {api['info']['x-api-level']})", "",
         "JSON API for webtrees 2.2, used by wtAnd, wtWin/wtTux and nas4webtrees. Machine-readable: "
         "[openapi.json](openapi.json). Both files are generated by `tests/manifest.py` from real responses; "
         "`tests/test_api.py` checks every response against them.", "",
         "## Basics", "",
         "- **Address:** `{base}/index.php?route={path}/module/_api4webtrees_/{Action}[/{tree}]&lang={language}` – "
         "`{path}` is the path of the base URL (`/webtrees` for `https://example.org/webtrees`, empty in the web root); "
         "webtrees expects it inside `route`. Works with and without pretty URLs. `lang` sets labels and dates.",
         "- **Clients:** an answer counts only with `Content-Type: application/json` (anything else: sign in, or the "
         "server failed). Send an honest `User-Agent` and `Accept-Language` (a fake browser without cookie gets 406 "
         "from webtrees' bot blocker), and no `X-Requested-With` header.",
         "- **Session:** the webtrees session cookie. Sign in with webtrees' own form (`POST /login` with `username`, "
         "`password`, `_csrf` from `Info`) or with a one-time code (`Pair`).",
         "- **Writing:** `POST` with header `X-CSRF-TOKEN: {Info.csrf}` and a JSON body. Without a valid token webtrees "
         "redirects (302) and executes nothing – fetch `Info` again and repeat once. Changes go through webtrees "
         "(moderation, change log); `pending: true` means they wait for approval.",
         "- **Privacy:** every answer shows exactly what webtrees shows this user – checked by webtrees itself "
         "(`canShow`, RESN, living people, private trees).",
         "- **Errors** come with HTTP 200 and `{\"ok\": false, \"error\": \"…\", \"status\": 403}` – some web servers "
         "(Synology, nginx) replace real 4xx/5xx bodies with their own pages.",
         "- **API level:** `Info.api` grows with each new capability; clients switch features on by level "
         "(column “since” below). Nothing is removed.",
         "- **Pages** (HTML, not part of this API): `App` (connect a device), `Connect` (target of the QR code), "
         "`Admin` (module settings).", "",
         "## Routes", "", "| Route | since | minimum role | purpose |", "|---|---:|---|---|"]
    for pfad, eintrag in api["paths"].items():
        for methode, op in eintrag.items():
            name = pfad.replace("/module/_api4webtrees_/", "")
            z.append(f"| `{methode.upper()} {name}` | {op['x-api-level']} | {op['x-minimum-role']} | {op['summary']} |")
    z += ["", "## Details", ""]
    for pfad, eintrag in api["paths"].items():
        for methode, op in eintrag.items():
            name = pfad.replace("/module/_api4webtrees_/", "")
            z += [f"### `{methode.upper()} {name}`", "", op["description"], ""]
            params = [p for p in op["parameters"] if p["name"] not in ("tree", "X-CSRF-TOKEN")]
            if params:
                z += ["| parameter | | |", "|---|---|---|"]
                z += [f"| `{p['name']}` | {'required' if p['required'] else 'optional'} | {p['description']} |" for p in params]
                z.append("")
            if op.get("x-body"):
                z += [f"Body: {op['x-body']}", ""]
            schema = op["responses"]["200"]["content"]["application/json"]["schema"]
            if schema.get("properties"):
                felder = ", ".join(f"`{k}`" for k in schema["properties"])
                z += [f"Answer fields: {felder} – full schema in openapi.json.", ""]
    z += ["## Error codes", "", "| status | error |", "|---:|---|"]
    z += [f"| {s} | `{c}` |" for s, c in FEHLER]
    leistung = os.path.join(DOCS, "leistung.md")
    if os.path.isfile(leistung):
        z += ["", open(leistung).read().strip()]
    return "\n".join(z) + "\n"


def main():
    u = umgebung.Umgebung().aufbauen()
    try:
        api = openapi(sammeln(u))
    finally:
        u.abbauen()
    os.makedirs(DOCS, exist_ok=True)
    with open(OPENAPI, "w") as f:
        json.dump(api, f, indent=1, ensure_ascii=False)
        f.write("\n")
    with open(os.path.join(DOCS, "API.md"), "w") as f:
        f.write(markdown(api))
    leer = [op["operationId"] for e in api["paths"].values() for op in e.values()
            if not op["responses"]["200"]["content"]["application/json"]["schema"]]
    print(f"{OPENAPI}: {sum(len(e) for e in api['paths'].values())} Routen" + (f", OHNE Beispiel: {leer}" if leer else ""))


if __name__ == "__main__":
    sys.path.insert(0, HIER)
    main()
