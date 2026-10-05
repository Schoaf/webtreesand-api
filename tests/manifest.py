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
          "editor": "editor of the tree", "moderator": "moderator of the tree", "manager": "manager of the tree",
          "admin": "site administrator"}

P = lambda name, beschreibung, typ="string", pflicht=False: {  # noqa: E731
    "name": name, "in": "query", "required": pflicht, "description": beschreibung, "schema": {"type": typ}}
XREF = P("xref", "Record identifier, e.g. `I123`", pflicht=True)

# (Methode, Aktion, mit Baum, Stufe, Rolle, Kurzbeschreibung, Parameter, Rumpf)
ROUTEN = [
    ("get", "Info", False, 1, "visitor",
     "Entry point: versions, API level, signed-in user, the trees this user can see (with role and rights), "
     "CSRF token for POST requests and the largest accepted upload. From level 26 also `loginForm`, the settings of the "
     "sign-in page: `welcomeMessage`, `isSelfRegistrationAllowed` and `registrationTerms` (`null` when the site shows no "
     "terms). `welcomeMessage` and `registrationTerms` are HTML written by the site administrator, in the language of the "
     "request – show them as HTML only after sanitising. From level 31 each tree also has `availableModules`: the names "
     "of the enabled modules this user can use there (modules with an access level – menus, tabs, footers … – only when "
     "it allows the user), so a client can offer e.g. the privacy policy only when that module is on. This makes `Info` "
     "longer by one list of module names per tree.", [], None),
    ("get", "Individuals", True, 1, "visitor",
     "List of individuals, sorted by name, 50 per page. `q` filters by name; with `scope=all` the words may appear "
     "anywhere in the visible data (place, year, occupation …).",
     [P("q", "Search words"), P("page", "Page, from 1", "integer"), P("scope", "`all`: search all visible data")], None),
    ("get", "Individual", True, 1, "visitor",
     "One individual with facts, parent/spouse/step families, media and – with `relativeTo` – the relationship "
     "to another individual. From level 28 each fact has `pending` (`true`: a change waiting for approval, visible only "
     "to users whom webtrees shows pending changes, i.e. editors and moderators) and `sex` is the value the user sees in "
     "the facts, also while a change of sex is pending.", [XREF, P("relativeTo", "Relationship relative to this individual (default: own individual)")], None),
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
    ("get", "Sources", True, 18, "visitor",
     "All sources the viewer may see, sorted by title: title, author, publication, abbreviation, first repository "
     "with call number, and how many individuals and families cite them (`uses`).", [], None),
    ("get", "Source", True, 18, "visitor",
     "One source with text, notes, media, repositories and who cites it: individuals and families with the facts "
     "that carry the citation (at most 1000 each; `moreIndividuals`, `moreFamilies`).",
     [P("xref", "Source identifier, e.g. `S12`", pflicht=True)], None),
    ("get", "Repositories", True, 18, "visitor", "All repositories (archives) the viewer may see, with address and how many sources refer to them.", [], None),
    ("get", "MediaList", True, 2, "visitor", "All media objects, newest first, 60 per page, with up to three linked names.",
     [P("page", "Page, from 1", "integer")], None),
    ("get", "Anniversaries", True, 4, "visitor",
     "Birthdays, weddings and deaths in the next `days` days. At most 100 entries: per day living people first, "
     "then round anniversaries (25, 50 …); `total` and `more` tell whether there were more (since 1.9.6).",
     [P("days", "1–60, default 14", "integer")], None),
    ("get", "Bookmarks", True, 11, "member", "The signed-in user's bookmarks in this tree. From level 30 they are webtrees' own favourites "
     "(table `favorite`, block “My favourites” on “My page”): `data` the user's, `treeFavorites` the tree's (set by managers), "
     "each person with `note`. Bookmarks from the old user setting (levels 11–29) are taken over once.", [], None),
    ("get", "Pending", True, 5, "moderator", "Records with pending changes.", [], None),
    ("get", "Tags", True, 1, "visitor", "Facts and events the client may offer for adding, with labels.",
     [P("type", "`INDI` or `FAM`", pflicht=True)], None),
    ("get", "Places", True, 8, "editor",
     "Place suggestions while typing, like webtrees' own autocomplete. `Berlin, Deu` searches per level. "
     "With `list=1` (level 21, visitor): every place at a visible fact – as written there – with the number of "
     "events, individuals and families, coordinates and their origin (`location`: the GEDCOM-L `_LOC` record, "
     "`mapData`: webtrees' geographic data, `event`: `MAP` at a fact), the `_LOC` record and its GOV identifier. From level 27 "
     "also `type` (the `_LOC` record's TYPE: farm, house, parish …) and places that exist only as a `_LOC` in the GEDCOM-L "
     "hierarchy (`1 _LOC @parent@`, e.g. a farm without recorded residents) with 0 events.",
     [P("q", "Beginning or part of the place name"), P("list", "`1`: list of all places (level 21)", "integer")], None),
    ("get", "Place", True, 21, "visitor",
     "One place: levels, sub-places one level down, coordinates with origin, the `_LOC` record (GOV identifier, "
     "coordinates, notes, sources, media) and the individuals and families with their events at this place "
     "(at most 1000 each). The `_LOC` record is found like the Ortsregister module does: `3 _LOC` at the events, "
     "the module's binding, its GOV identifier, the leaf name if unique on both sides. From level 27 the `_LOC` record also "
     "carries `type` (TYPE), `parents` (the GEDCOM-L hierarchy `1 _LOC @parent@` with pointer type and date) and `events` "
     "(`1 EVEN` at the place: fire, rebuilding, change of ownership … with type, date, notes, sources); `children` are "
     "merged from webtrees' place table and the `_LOC` hierarchy, each with `location` and `type`. A place that exists only "
     "as a `_LOC` in the hierarchy is answered with 0 events. `not-found` if neither a visible event nor a `_LOC` names the place.",
     [P("name", "The place as written at the event, e.g. `Kortau, Allenstein`", pflicht=True)], None),
    ("post", "Place", True, 22, "editor",
     "Save a place's data in its GEDCOM-L `_LOC` record – created if there is none. Only the parts named in the body are "
     "replaced; sources, media and unknown lines of the `_LOC` stay. If the leaf name is not unique in the tree, the events "
     "at the place get the pointer `3 _LOC @L1@` (`linked`: how many). `mapData: true` also writes the coordinates to "
     "webtrees' geographic data (site administrators only) – webtrees' own maps read only those and `MAP` at the events.",
     [], "`{name, gov?, lat?, lng?, note?, media?, mapData?, postalCode?, region?, country?, shortName?, type?, parent?}` – `lat`/`lng` together, `null` removes the coordinates; "
     "`media` replaces the linked media objects (upload new ones with route Media and the `_LOC` identifier). `type` (level 27) sets the "
     "`_LOC` record's TYPE, `parent` the identifier of the superior `_LOC` (`1 _LOC @parent@`, replaces all hierarchy pointers; `null` detaches); "
     "with `parent` a place without events may be created (a farm without recorded residents). "
     "Answer: `{ok, xref, pending, linked, mapData}`, status 201 when the `_LOC` was created."),
    ("post", "MediaObject", True, 23, "editor",
     "Change title and type of a media object (first file: `2 TITL`, `2 FORM` / `3 TYPE`); everything else stays.",
     [XREF], "`{title?, type?}` – type one of photo, document, certificate, book, newspaper, card, map, tombstone, audio, video, "
     "electronic, film, fiche, magazine, manuscript, painting, other; empty removes it."),
    ("post", "MyAccount", False, 25, "member",
     "Change the signed-in user's own display name, as under “My account” in the browser. Username, email and password "
     "stay with the browser.", [], "`{realName}`. Answer: `{ok, realName}`."),
    ("post", "StartPerson", True, 24, "member",
     "Set the start person. Without `forTree` the signed-in user's own default individual (as under “My account”; an empty "
     "`xref` removes it), with `forTree: true` the family tree's default individual (managers only). Route Info names per "
     "tree `startXref` – the individual webtrees starts with for this user, if visible – and `treeDefaultXref`.",
     [], "`{xref, forTree?}`. Answer: `{ok, startXref, defaultXref, treeDefaultXref}`."),
    ("post", "PlaceRename", True, 23, "editor",
     "Rename a place or merge it into another. Every event at `from` gets `to`; places below move along "
     "(`Kortau, Allenstein` → `Kortau, Olsztyn`). If `to` already exists it is a merge: the two `_LOC` records become one "
     "(gaps filled, notes, sources and media appended, differing GOV identifier or coordinates reported in `conflicts`) "
     "and the `3 _LOC` pointers point to it. Events the user may not edit (locked, confidential) stay and are counted in "
     "`skipped`. With `preview: true` nothing changes. Without automatic acceptance the changes are pending as usual.",
     [], "`{from, to, preview?}`. Answer: `{ok, preview, from, to, merge, records, events, subPlaces, skipped, "
     "location: {from, to, conflicts}, pending?}`."),
    ("post", "Merge", True, 29, "manager",
     "Merge two individuals: `xref2` is absorbed into `xref1`. Everything that pointed to `xref2` (families, sources, notes, "
     "media, associations) points to `xref1` afterwards, duplicate links are dropped, `xref2` is deleted – as webtrees' own "
     "merge does. Which facts stay is up to the client (`keep1`, `keep2`, fact ids); links (FAMC, FAMS, OBJE) always stay "
     "from both. With `preview: true` nothing changes: the answer lists both persons, their facts with a suggestion "
     "(`keep`: all of the first, from the second only what the first does not have word for word; `same` marks identical "
     "facts, `link` the ones that always stay), the records that link to `xref2` and `suggestions` – further pairs that are "
     "probably the same person too (father, mother, spouses and children with the same name). Without `preview` the merge "
     "is done and remembered: `mergeId` is the handle for `MergeUndo`. Only managers of the tree, like in webtrees. Without "
     "automatic acceptance the changes are pending as usual.",
     [], "`{xref1, xref2, keep1?, keep2?, preview?}`. Answer (preview): `{ok, preview, person1, person2, facts1, facts2, links, "
     "suggestions}`; (merge): `{ok, preview, xref, removed, mergeId, records, pending}`."),
    ("post", "MergeUndo", True, 29, "manager",
     "Take a merge back. webtrees keeps every change with the old and the new text; the module remembered which changes "
     "belong to the merge and replays the old texts in reverse order – the deleted individual comes back under its old "
     "identifier, links point to it again. Only if none of the records was changed since: otherwise `changed-since` with "
     "the records in `changed`, and nothing is changed. Pending changes of the merge are rejected instead. With "
     "`preview: true` only the check is done. Without automatic acceptance the undo is pending as usual.",
     [], "`{id, preview?}`. Answer: `{ok, preview, xref, removed, records, pending?}`."),
    ("get", "Merges", True, 29, "manager",
     "The merges of this tree, newest first: who merged whom into whom and when, how many records were changed, and "
     "whether the merge was undone (`undone`: time or `null`).", [], None),
    ("post", "Fact", True, 1, "editor",
     "Add or change a fact or event. Unmentioned sub-lines (sources, media …) are kept when changing.",
     [XREF], "`{factId?, tag, value?, date?, place?, note?, type?}` or `{factId?, gedcom: \"1 BIRT\\n2 DATE …\"}`. "
     "`type` (level 20) sets the fact's TYPE; for MARR in webtrees' form (civil → CIVIL, religious → RELIGIOUS, PARTNERS, COMMON LAW)."),
    ("post", "Association", True, 20, "editor",
     "Write godparents, witnesses and other associates of a fact. `linked` replaces the linked individuals (`2 _ASSO` + "
     "`3 RELA`, written as webtrees does: `godparent`/`witness`; `other` needs `rela`); an individual already linked keeps "
     "its sub-lines (sources) and its RELA spelling when the role matches; `note` replaces its embedded note. `free` "
     "replaces people without a record, one line each as `2 _GODP` (godparents) or `2 _WITN` (witnesses) as in "
     "GEDCOM-L – old notes \"Paten: …\"/\"Trauzeugen: …\" on the fact are converted. `convertLevel1` moves the "
     "person's `1 ASSO` for individuals named in `linked` into the baptism. Parts not named stay. Answer: new `factId`.",
     [XREF], "`{factId, linked?: [{xref, role: godparent|witness|other, rela?, note?}], free?: [{text, role: godparent|witness}], convertLevel1?}`"),
    ("post", "Source", True, 18, "editor",
     "Create a source (no `xref`, `title` required) or change one (`xref`). Only the parts named in the body are "
     "replaced; media, further repositories and unknown lines are kept. Media are added with the route Media and `xref` of the source.",
     [P("xref", "Source identifier when changing, e.g. `S12`")], "`{title?, author?, publication?, abbreviation?, text?, note?, repository?: \"R1\" | \"\", callNumber?}`"),
    ("post", "Repository", True, 18, "editor", "Create a repository (no `xref`) or rename one (`xref`).",
     [P("xref", "Repository identifier when changing, e.g. `R1`")], "`{name}`"),
    ("post", "MediaFromFile", True, 18, "editor",
     "Media object for a file that already lies in the tree's media folder (e.g. a scan from the archive): returns the "
     "existing object's identifier or creates one, without linking it. `type` defaults to `document`.",
     [XREF], "`{file, title?, type?}`"),
    ("post", "Citation", True, 18, "editor",
     "Add, change, delete or move a source citation on a fact – or a general citation on the record (no `factId`). "
     "Only the parts named in the body are replaced; everything else on the citation and the fact is kept.",
     [XREF], "`{factId?, index?, delete?, moveTo?, source?: \"S1\" | free text, page?, quality?: 0-3, date?, text?, note?, media?: [\"M1\"]}`"),
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
    ("post", "Bookmarks", True, 11, "member", "Add or remove a bookmark; answers with both lists. From level 30 `note` (text shown with the favourite) and "
     "`forTree: true` (the tree's favourites, managers only, error `not-manager`).", [], "`{xref, add: true|false, note?, forTree?}`"),
    ("get", "Tasks", True, 30, "member",
     "All research tasks of the tree – webtrees' own `_TODO` facts at individuals and families (text, date, user, note), as the "
     "module “Research tasks” writes and shows them – at records the user may see, by date. `?open=1` only the ones due (date not in "
     "the future; webtrees treats a future date as a reminder). A task is done when it is deleted (`DeleteFact` with its `factId`).",
     [P("open", "`1`: only tasks whose date is not in the future")], None),
    ("post", "Task", True, 30, "editor",
     "Add or change a research task at an individual or family: `1 _TODO text` with `2 DATE` (today when missing), `2 _WT_USER` "
     "(the signed-in user when missing) and `2 NOTE`. With `factId` the task is changed, without it created. The answer's `factId` "
     "is the new id. Delete (= done) with `DeleteFact`.", [XREF], "`{factId?, text, date?, user?, note?}`. Answer: `{ok, xref, pending, factId}`."),
    ("post", "Reorder", True, 30, "editor",
     "Reorder children (`children`, at a family), partnerships (`families`), names (`names`, at an individual) or media (`media`, "
     "both) – like the “Re-order” pages in webtrees, only the order of the lines changes. `order` lists the identifiers (for names "
     "the fact ids) in the new order; what is not listed comes after. The answer's `order` is the resulting order.",
     [XREF], "`{type: children|families|names|media, order: [...]}`. Answer: `{ok, xref, pending, order}`."),
    ("get", "Changes", True, 30, "member",
     "The change history of the tree from webtrees' change table, newest first: who created, changed or deleted which record and "
     "when, including pending changes. Only records the user may see; deleted records only for managers. `?limit=50` (at most 200), "
     "`?xref=` only that record.", [P("limit", "At most this many entries (1–200, default 50)", "integer"), P("xref", "Only this record")], None),
    ("post", "Accept", True, 5, "moderator", "Accept pending changes of one record, or of the whole tree without `xref`.",
     [P("xref", "Record, optional")], None),
    ("post", "Reject", True, 5, "moderator", "Reject pending changes of one record, or of the whole tree without `xref`.",
     [P("xref", "Record, optional")], None),
    ("post", "Pair", False, 6, "visitor",
     "Redeem the one-time code from the page “App”: afterwards this session is signed in as that user.", [], "`{code}`"),
]

FEHLER = sorted(set(re.findall(r"error\((\d+), '([a-z-]+)'\)", "".join(
    open(os.path.join(umgebung.MODUL, f)).read() for f in
    ["Api4WebtreesModule.php", "src/ReadActions.php", "src/WriteActions.php", "src/AppPages.php", "src/MergeActions.php", "src/TaskActions.php"]))),
    key=lambda e: (e[0], e[1]))


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
    merken("post", "MediaObject", admin.post("MediaObject", "testbaum", {"title": "Manifestbild"}, xref=medien.json["media"]))
    m = medien.json.get("media")
    merken("post", "PrimaryMedia", admin.post("PrimaryMedia", "testbaum", {"media": m}, xref="I1"))
    os.makedirs(os.path.join(umgebung.WT, "data", "media", "archiv"), exist_ok=True)
    open(os.path.join(umgebung.WT, "data", "media", "archiv", "manifest.png"), "wb").write(PNG)
    merken("post", "MediaFromFile", admin.post("MediaFromFile", "testbaum", {"file": "archiv/manifest.png", "title": "Manifestscan"}, xref="I1"))
    merken("post", "MediaFromFile", admin.post("MediaFromFile", "testbaum", {"file": "archiv/manifest.png"}, xref="I1"))
    merken("post", "Bookmarks", admin.post("Bookmarks", "testbaum", {"xref": "I1", "add": True, "note": "Manifest"}))
    merken("post", "Bookmarks", admin.post("Bookmarks", "testbaum", {"xref": "I2", "add": True, "forTree": True}))
    aufgabe = admin.post("Task", "testbaum", {"text": "Manifestaufgabe", "note": "Taufe suchen"}, xref="I4")
    merken("post", "Task", aufgabe)
    merken("post", "Task", admin.post("Task", "testbaum", {"factId": aufgabe.json["factId"], "text": "Manifestaufgabe geaendert", "date": "1 JAN 2030"}, xref="I4"))
    merken("post", "Reorder", admin.post("Reorder", "testbaum", {"type": "children", "order": ["I5", "I4"]}, xref="F1"))
    merken("post", "Reorder", admin.post("Reorder", "testbaum", {"type": "children", "order": ["I4", "I5"]}, xref="F1"))
    merken("post", "StartPerson", admin.post("StartPerson", "testbaum", {"xref": "I1"}))
    merken("post", "MyAccount", admin.post("MyAccount", None, {"realName": admin.info()["user"]["realName"]}))
    # Info.loginForm mit Bedingungen: ohne SHOW_REGISTER_CAUTION waere registrationTerms immer null und das Schema falsch.
    umgebung.sql("INSERT OR REPLACE INTO wt_site_setting (setting_name, setting_value) VALUES ('SHOW_REGISTER_CAUTION', '1')")
    merken("get", "Info", u.sitzung().get("Info"))
    umgebung.sql("DELETE FROM wt_site_setting WHERE setting_name = 'SHOW_REGISTER_CAUTION'")
    archiv = admin.post("Repository", "testbaum", {"name": "Manifestarchiv"})
    merken("post", "Repository", archiv)
    quelle = admin.post("Source", "testbaum", {"title": "Manifestquelle", "author": "Manifest", "repository": archiv.json["xref"], "callNumber": "M 1"})
    merken("post", "Source", quelle)
    merken("post", "Source", admin.post("Source", "testbaum", {"publication": "Manifeststadt, 1900"}, xref=quelle.json["xref"]))
    merken("post", "Place", admin.post("Place", "testbaum", {"name": "Bieber, Gelnhausen", "gov": "MANIFEST1", "lat": 50.2, "lng": 9.3, "note": "Manifestort"}))
    merken("post", "PlaceRename", admin.post("PlaceRename", "testbaum", {"from": "Bieber, Gelnhausen", "to": "Bieber, Main-Kinzig", "preview": True}))
    fakt = admin.post("Fact", "testbaum", {"tag": "OCCU", "value": "Manifestberuf", "date": "1850"}, xref="I4")
    merken("post", "Fact", fakt)
    occu = [f for f in admin.get("Individual", "testbaum", xref="I4").json["facts"] if f.get("tag") == "OCCU"]
    zitat = admin.post("Citation", "testbaum", {"factId": occu[0]["id"], "source": "S1", "page": "Manifestseite", "quality": 2}, xref="I4")
    merken("post", "Citation", zitat)
    taufe = [f for f in admin.get("Individual", "testbaum", xref="I4").json["facts"] if f.get("tag") == "CHR"]
    merken("post", "Association", admin.post("Association", "testbaum", {"factId": taufe[0]["id"], "free": [{"text": "Manifest Pate, Bauer", "role": "godparent"}]}, xref="I4"))
    occu = [f for f in admin.get("Individual", "testbaum", xref="I4").json["facts"] if f.get("tag") == "OCCU"]
    merken("post", "DeleteFact", admin.post("DeleteFact", "testbaum", {"factId": occu[0]["id"]}, xref="I4"))
    ehe = admin.post("AddIndividual", "testbaum", {"relation": "none", "given": "Ella", "surname": "Neu", "sex": "F", "dead": True})
    link = admin.post("Link", "testbaum", {"individual": ehe.json["xref"], "relation": "spouse", "relativeTo": "I4"})
    merken("post", "Link", link)
    merken("post", "Unlink", admin.post("Unlink", "testbaum", {"family": link.json["family"], "individual": ehe.json["xref"]}))
    # Zusammenfuehren: zwei Wegwerfpersonen, Vorschau, Zusammenfuehren, Protokoll, Rueckgaengig
    d1 = admin.post("AddIndividual", "testbaum", {"relation": "none", "given": "Manifest", "surname": "Doppel", "sex": "M", "dead": True, "birthDate": "1850"}).json["xref"]
    d2 = admin.post("AddIndividual", "testbaum", {"relation": "none", "given": "Manifest", "surname": "Doppel", "sex": "M", "dead": True, "deathDate": "1900"}).json["xref"]
    merken("post", "Merge", admin.post("Merge", "testbaum", {"xref1": d1, "xref2": d2, "preview": True}))
    zusammen = admin.post("Merge", "testbaum", {"xref1": d1, "xref2": d2})
    merken("post", "Merge", zusammen)
    merken("post", "MergeUndo", admin.post("MergeUndo", "testbaum", {"id": zusammen.json["mergeId"], "preview": True}))
    merken("post", "MergeUndo", admin.post("MergeUndo", "testbaum", {"id": zusammen.json["mergeId"]}))
    merken("post", "MergeUndo", admin.post("MergeUndo", "testbaum", {"id": zusammen.json["mergeId"]}))
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

def modulversion():
    """Die Fassung aus Api4WebtreesModule.php (customModuleVersion) - latest-version.txt wird erst beim Release gesetzt."""
    quelle = open(os.path.join(umgebung.MODUL, "Api4WebtreesModule.php"), encoding="utf-8").read()
    return re.search(r"function customModuleVersion\(\): string\s*\{\s*return '([^']+)'", quelle).group(1)


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
        "info": {"title": "api4webtrees", "version": modulversion(),
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
