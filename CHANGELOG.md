# Changelog

## Unreleased
**API level 31.** `Info.trees[].availableModules`: the names of the enabled modules the user can use in the tree – modules
with an access level only when it allows the user (as webtrees' `ModuleService::findByComponent()`). Clients can offer
features of optional modules (privacy policy, Sammlungen, …) only when they are there.

## 1.18.1 – 2026-10-07
- **Place type with GOV type number.** `Places?list=1`, `Place` and its `children` now carry `govType`: the GOV type number
  from `2 _GOVTYPE` under the `_LOC` record's `1 TYPE` (GEDCOM-L addendum; 24 farm, 87 mill, 55 village …, list at
  gov.genealogy.net/type/list). Clients can classify houses, farms and higher levels by the number instead of the free text.
- `type` is the last of several dated `1 TYPE` lines (the current one), not the first.
- `POST Place` with `type` changes only the value of the latest TYPE line; its `_GOVTYPE`, date and sources and older
  dated TYPE lines stay. Before, the whole TYPE block was replaced.
- API level stays 30; the field is additive.

## 1.18.0 – 2026-10-06
**API level 30: the interface is complete.** With this release every route needed for everyday genealogy work is there:
individuals, families, events, names, sources and citations, media, places and location records, godparents, merging with
undo, and now research tasks, ordering, change history and favourites. **From here on releases will be rare.** Changes
and fixes are collected and published together; pull requests are merged without an immediate release, so clients built
on this API are not served a new version every few days. Everything is additive; older clients keep working.
- **Bookmarks → webtrees favourites.** `GET/POST Bookmarks` now use webtrees' own `favorite` table, so the bookmarks appear
  in the browser under “My page › My favourites” and survive any client. `data` are the user's, `treeFavorites` the
  tree's (managers set them with `forTree: true`), each person with a `note`. Bookmarks from the old user setting are
  taken over once, automatically.
- **Research tasks.** `GET Tasks` lists webtrees' `_TODO` facts of the tree (text, date, user, note; `?open=1` only the
  ones due); `POST Task` adds or changes one (date defaults to today, user to the signed-in one); deleting with
  `DeleteFact` means done. `Individual` and `Family` answer with `tasks`. Nothing proprietary: `_TODO` is what the
  webtrees module “Research tasks” writes and shows.
- **Ordering.** `POST Reorder` sorts children, partnerships, names or media – like the “Re-order” pages in webtrees,
  only the order of the GEDCOM lines changes.
- **Change history.** `GET Changes` lists who created, changed or deleted which record and when (webtrees' change table,
  pending changes included), `?xref=` for one record; `Individual` and `Family` answer with `lastChange` (CHAN).
- Tests: `StufeDreissig` (favourites with migration, tasks, ordering, history); the documentation is generated again.

## 1.17.2 – 2026-10-06
**Merge preview: contained facts.** No API change; API level stays 29.
- A fact that is contained in a fact of the other individual – same first line, every further line present there too, but
  the other version has more lines (birth with date only next to birth with date and place) – is now reported as `same`
  and not suggested for keeping; the more complete version stays, whichever individual it belongs to. Before, both
  births would have been kept.
- Test `Zusammenfuehren` covers it.

## 1.17.1 – 2026-10-06
**Shorter “App” page.** No API change.
- Only the app for the visitor's device is open; every other app is one folded line below (before: wtWin and wtAnd
  were always open, which made the page long on every device). wtWin now comes first in the list, since most people
  open the page at a PC.

## 1.17.0 – 2026-10-06
**Logos on the “App” page, readable code.** No API change; API level stays 29.
- The “App” and “Connect” pages show an app's logo before its name (new optional field `icon` in `src/Apps.php`, file
  under `resources/img`; the tests check that the file exists). The four apps of the module author share one logo; a
  third-party app adds its own with its pull request. Under the author's apps a short note says why they come first:
  app and interface are developed together. The order of the apps is unchanged.
- The code was reworked for readability, with no change in behaviour: named constants instead of bare numbers,
  helpers instead of repeated blocks (visibility check, fact lookup with rights, pending changes, GEDCOM sub-record
  replacement), English identifiers throughout, the longest actions split into named steps, misplaced comments fixed.
  The API level history now lives in `docs/API.md` and this file only.

## 1.16.0 – 2026-10-05
**API level 29: merging individuals, with undo.** Everything is additive; older clients keep working.
- **`POST Merge`** merges two individuals the way webtrees' own merge does: `xref2` is absorbed into `xref1`, everything
  that pointed to it (families, source citations, notes, media, associations) points to `xref1` afterwards, duplicate
  links are dropped, `xref2` is deleted. Which facts stay is up to the client (`keep1`, `keep2`); links (FAMC, FAMS,
  OBJE) always stay from both. `preview: true` changes nothing and answers with both persons, their facts with a
  suggestion (`keep`: all of the first, from the second only what the first does not have word for word; a bare
  `1 DEAT Y` gives way to a dated death), the records linking to `xref2` and `suggestions` – further pairs that are
  probably duplicates too (father, mother, spouses and children with the same name). Only managers of the tree, as in
  webtrees. Without automatic acceptance the changes are pending as usual.
- **`POST MergeUndo`** takes a merge back. webtrees keeps every change with the old and the new text; the module
  remembers which changes belong to a merge (module setting per tree, last 200) and replays the old texts in reverse
  order – the deleted individual comes back under its old identifier. Only if none of the records was edited since,
  otherwise `changed-since` with the records and nothing changes. Pending changes of the merge are rejected instead.
- **`GET Merges`**: the log of the tree's merges, newest first, with `undone`.
- Tests: `Zusammenfuehren` (merge, undo, changed-since, rights, manager without automatic acceptance); the
  documentation is generated again.

## 1.15.0 – 2026-10-05
**API levels 27 and 28: houses and farms as places, pending facts.** Everything is additive; older clients keep working.
- **Level 27 – the whole GEDCOM-L `_LOC` record.** Houses and farms are location records of their own with a type and a
  superior place (`1 TYPE Hof`, `1 _LOC @L1@`), as GEDCOM-L and local heritage books with farm lists use them.
  `GET Place` now gives the `_LOC` record's `type`, its `parents` (the hierarchy pointers with their type and date) and
  its `events` (`1 EVEN` at the place – fire, rebuilding, sale … with type, date, notes and sources). `children` are
  merged from webtrees' place table and the `_LOC` hierarchy, each with `location` and `type`. A place that exists only
  as a `_LOC` in the hierarchy (a farm without recorded residents) is listed by `Places?list=1` and answered by `Place`
  with 0 events instead of `not-found`. `POST Place` takes `type` and `parent`; with `parent` a place without events can
  be created. Renaming a place takes its farms along.
- **Level 28 – pending facts.** Facts carry `pending: true` while a change waits for approval (only users who see
  pending changes get `true`). `sex` is everywhere what the facts show the user, also while a change of sex is pending.
- Tests: `test_hof`, the test tree has two farms; the documentation is generated again.

## 1.14.0 – 2026-10-05
**API levels 25 and 26, both from Andreas Scharf for his app webtrees mobile.** Everything is additive; older clients keep working.
- **Level 25 – `POST MyAccount`.** The signed-in user changes their own display name, as under “My account” in the
  browser: body `{realName}`, answer `{ok, realName}`. User name, email and password stay with the browser. Names
  longer than 64 characters (the column's limit) are refused with `real-name-too-long`; line breaks and control
  characters become spaces.
- **Level 26 – `Info.loginForm`.** The settings of webtrees' sign-in page: `welcomeMessage`,
  `isSelfRegistrationAllowed` and `registrationTerms` (`null` when the site shows no terms), in the language of the
  request, so apps can show the same welcome text and offer registration only when the site allows it. The two texts are
  HTML written by the site administrator – show them as HTML only after sanitising.
- Tests: `test_mein_konto`, `test_anmeldeseite_in_info`; the documentation is generated again, with a sample that has
  the terms switched on, so `registrationTerms` is described as text or `null`.

## 1.13.1 – 2026-10-04
API level 24, unchanged. **iPhone and iPad get an app: webtrees mobile by Andreas Scharf** (App Store, connect scheme
`webtreesmobile://`), the first app from another author in `src/Apps.php`. On iPhone and iPad the “App” page now
shows it with the App Store badge and one-tap connecting instead of the browser note; managers can untick it in the
settings like any other app.

## 1.13.0 – 2026-10-03
**API levels 21–24: places, media objects, start person.** Everything is additive; older clients keep working.
- **Level 21 – reading places.** `GET Places?list=1`: every place at visible events with counts (events, individuals,
  families), coordinates (from the GEDCOM-L `_LOC` record, else webtrees' geographic data, else `MAP` at an event, with
  `coordSource`), the `_LOC` identifier, GOV identifier and short name. `GET Place?name=`: the individuals and families
  with their events there, places below, `eventCounts` (births, marriages, deaths, other) and the `_LOC` with GOV
  identifier, coordinates, postal code, region, country, short name, notes, sources and media. A place finds its `_LOC`
  by the pointer at the event, the binding of the place-register module, the GOV identifier or a unique leaf name.
  Privacy as everywhere: only visible events count; restricted records take the exact (slower) path.
- **Level 22 – writing places.** `POST Place {name, gov?, lat?, lng?, note?, media?, postalCode?, region?, country?,
  shortName?, mapData?}` writes into the `_LOC` and creates it if missing (`_POST`/`POST`, `_STAE`, `_CTRY`,
  `2 ABBR` under the name – existing spellings stay). With an ambiguous leaf name the events get `3 _LOC @L…@`.
  `mapData: true` also writes webtrees' geographic data (site administrators only).
- **Level 23 – renaming and merging places.** `POST PlaceRename {from, to, preview?}`: every event at `from` gets `to`,
  places below move along. If `to` already has records it is a merge: the two `_LOC` become one (gaps filled, notes,
  sources and media appended, differing GOV identifier or coordinates reported). Locked or confidential events stay and
  are counted. `POST MediaObject {title?, type?}` changes title and type of a media object; media now carry
  `type`/`format`, at a `_LOC` also file size and image dimensions. `POST Media {media}` links an existing media
  object (edit rights suffice). NOTE facts name `noteXref` when they point to a shared note.
- **Level 24 – start person.** Route Info names per tree `startXref` – the individual webtrees starts with for this
  user (own default individual, “this is me”, the tree's default individual, else the first) – and
  `treeDefaultXref`. `POST StartPerson {xref, forTree?}` sets the user's own default individual (empty removes it) or,
  for managers, the family tree's.
- With SQLite (nas4webtrees, the family tree on this PC) webtrees can keep one place in two spellings that differ only
  in case (“Celle”, “celle”); places count both, renaming takes both along.
- Tests: places (reading, writing, renaming, merging, privacy), media objects, start person; performance check with
  10,000 and 50,000 individuals (`docs/leistung.md`).

## 1.12.0 – 2026-10-01
**API level 20: godparents and witnesses – writing.** New route `POST Association` and `type` for `POST Fact`.
- `POST Association` (`?xref=` individual or family): `{factId, linked?, free?, convertLevel1?}`. `linked` replaces the
  linked individuals of the fact in the given order and writes them as webtrees does (`2 _ASSO @I…@` + `3 RELA
  godparent`/`witness`; `other` with a free `rela`). An individual already linked keeps its sub-lines (`3 SOUR` …) and
  its RELA spelling (“Godfather”) when the role matches; `note` replaces only its embedded note. Links to individuals
  the writer may not even see as a reference are always kept. `free` replaces the people without a record, one line
  each as `2 _GODP` (godparents) or `2 _WITN` (witnesses) – the GEDCOM-L form; notes “Paten: …” /
  “Trauzeugen: …” on the fact are converted into it, other notes stay. `convertLevel1: true` moves the person's
  `1 ASSO` for individuals in `linked` into the baptism, with their note and source. Parts not named stay untouched.
  Answer: the new `factId`. Moderation and change log as for `POST Fact`.
- `POST Fact` takes `type` (the fact's `2 TYPE`); for `MARR` it is written in webtrees' form (civil → `CIVIL`,
  religious → `RELIGIOUS`, `PARTNERS`, `COMMON LAW`), anything else as given.
- `POST Fact` with `note` no longer overwrites a godparent list (“Paten: …”, “Trauzeugen: …”) that happens to be the
  first note of the fact; it replaces the first ordinary note.
- Tests: write godparents (order, kept sources and spelling, private links, free entries, `1 ASSO` into the baptism,
  errors) and marriage type with witnesses.

## 1.11.0 – 2026-10-01
**API level 19: godparents and witnesses – reading.** Until now the API ignored `_ASSO` entirely, so the apps saw
godparents only from notes. Everything here is additive; older clients keep working and simply do not see the new
fields (spec: `docs/spec-paten-quellen.md`, read side).
- Every fact carries `associates[]`: the people linked with `2 _ASSO` (godparents at `CHR`/`BAPM`, witnesses at
  `MARR` …) with `xref`, `name`, `sex`, `rela` (raw, as in the file), `role` (normalised regardless of case:
  `godparent` for godparent/godfather/godmother/Pate/Patin/Taufpate/Gevatter, `witness` for witness/Trauzeuge/Zeuge,
  else `other`), `label` as webtrees shows it (`RelationIsDescriptor` by the linked person's sex: “Pate”/“Patin”),
  `private`, `level1`, and the association's own `notes` and `sources` (same form as on facts).
- A `1 ASSO` on the person (GEDCOM 5.5.1, older exports) stays a fact `ASSO` as before – now with
  `associates` so that `RELA` is no longer lost – and, when it names a godparent and the person has a christening
  (`CHR`, else `BAPM`), is added to that christening's `associates` with `level1: true`. Nothing is rewritten.
- `freeAssociates[]`: people without a record. First from the GEDCOM-L tags `2 _GODP <text>` under `CHR`/`BAPM`
  (godparents) and `2 _WITN <text>` under `MARR` (witnesses; GEDCOM-L, webtrees knows both), one
  person per line (“Friedrich Plate, Anbauer zu Celle”), so each line is one entry with `name` up to the first comma
  and `detail` the rest – a line with `;` is a list like a note. Then from fact notes beginning with `Paten:`,
  `Taufpaten:`, `Gevattern:`, `Trauzeugen:` or `Zeugen:` (any case) – entries separated by `;`, `name` up to the first
  comma, `detail` the rest. A note without `;` (commas only) gives one entry with `name: null` and the whole `text` –
  no guessing. The note stays in `notes`; the new parallel `noteKinds[]` marks it as `associates` (others: `note`), so
  clients do not show it twice.
- `Individual.associatedIn[]`: where this person is a godparent, witness … – the other person's or family's event
  (`record`, `recordType`, `name`, `tag`, `label`, `factId`, `date`, `place`, `rela`, `role`, `label2`, `level1`,
  `url`, and for families `husband`/`wife` as xrefs of the visible partners, so a client can open the entry), like
  webtrees' “associated events” over the `ASSO` and `_ASSO` links, but including `1 ASSO` on the
  godchild. Only visible records and facts; sorted by date.
- `typeLabel` on every fact with a `TYPE`: the value as webtrees displays it (`FAM:MARR:TYPE`: `CIVIL` → “Civil
  marriage”, `RELIGIOUS` → “Religious marriage”), raw for unknown values, `null` without a type. `type` itself is
  unchanged (webtrees' canonical form, upper case for `MARR`).
- Notes and texts are read with `CONC` as well as `CONT` (`CONC` appends without a space). webtrees merges `CONC`
  on import and on accepting a change, so this matters for pending changes and raw GEDCOM sent by clients.
- Privacy: a linked person the user may not see comes with `xref`, `private: true` and no name or sex; one whose
  name they may not see at all (visitors, with “show names of private people” at its default) is left out.
  `associatedIn` drops hidden records and facts silently. The leak test covers the new fields for visitor, member
  and editor; the test tree carries a living, a confidential and a visible godparent, a `1 ASSO`, free entries in both
  forms, a civil marriage with a linked witness and a note with `CONC`. New tests: class `Paten`.
- Cross-checked against the demo tree Falkenrath 1.2 (all cases of spec section 7).
- `tests/manifest.py` takes the version from `Api4WebtreesModule.php`; `docs/API.md` and `docs/openapi.json` are
  regenerated (they were still at 1.9.6). Writing (`POST Association`, `FactRequest.type`, the source gaps of spec
  sections 2 and 4) is not part of this release.

## 1.10.2 – 2026-09-30
Settings: the name app4webtrees in the text is a link to the GitHub repository.

## 1.10.1 – 2026-09-30
**wtMac in the list** (test build for Macs with Apple chip or Intel, from the same code as wtWin/wtTux; connect scheme
`wtmac://`). A Mac now gets its program on the “App” page instead of the browser note, which is left for iPhone and
iPad. Settings text: the apps live on GitHub under app4webtrees in four editions; “users” instead of “family
members” – whoever works on a tree. Note and footer say “computer” instead of “PC”.

## 1.10.0 – 2026-09-30
**The apps the module shows now live in the code, not in the settings.** `src/Apps.php` lists them (wtAnd, wtWin,
wtTux) with name, devices, download, connect scheme and store badge; a further app joins by pull request with one
entry, once it is publicly installable. The settings offer a tick per app instead of the four free-text fields for
“Another app” (1.2.0), which nobody could sensibly fill in; values entered there are no longer read. The “App” page
orders the apps by the visitor's device – matching ones first, own before others, wtWin and wtAnd always open, the rest
folded – and each app gets its own heading with its devices, so two apps for different devices no longer look like one.
iPhone, iPad and Mac show the browser note only while no app in the list fits them. The footer names the app for the
device. The tests check the list (fields, https, unique schemes), so a faulty entry cannot be merged.
**Compatibility promise** written down in the README: the interface only grows, every addition raises the API level,
existing routes and fields keep their meaning; a client built for level N works with every module from level N on.
**API level 18: sources.** New routes `Sources` (all visible sources with author, publication, repository, call
number and how often they are cited) and `Source` (one source with text, notes, media, repositories and the
individuals and families citing it, with the facts that carry the citation). Citations on facts now come complete:
besides `page` also `quality` (QUAY 0–3), `date` and `text` of the entry (DATA), `notes` and `media` of the citation,
and sources without a record ("according to Martha Meier") with an empty `xref`. Sources a viewer may not see are
left out, as in webtrees. Tests: `test_quellenverweis_vollstaendig`, `test_quellen_liste_und_einzeln`; the leak test
covers the new routes.
**Writing citations:** new route `Citation` adds, changes, deletes or moves a citation on a fact, or a general
citation on the record. Only the parts named in the body are replaced (`source`, `page`, `quality`, `date`, `text`,
`note`, `media`); everything else on the citation and the fact stays. A fact's id is a hash of its content, so the
answer carries the new `factId`. Test: `test_quellenverweis_schreiben`.
**Managing sources and repositories:** `Source` (POST) creates a source or changes only the parts named (title, author,
publication, abbreviation, text, note, repository with call number); `Repositories` lists the archives, `Repository`
(POST) creates or renames one. `Media` accepts `type` (`document` for scans of records, default `photo`) and works
with a source's `xref` too. Unused sources (`uses: 0`) can be removed with `DeleteRecord`. Test:
`test_quelle_und_archiv_pflegen`.
**Documents on sources and citations:** `Media` takes `link: false` to create the media object without linking it to
the record, so a client can attach it to a citation (`Citation`, `media`) or to a source (`Source`, `media`, which
replaces the list of linked media). Unlinking keeps the media object and the file, as in webtrees.
**Files from the archive:** `MediaFromFile` (POST) turns a file that already lies in the media folder – e.g. a parish
register scan from the *Sammlungen* archive – into a media object, or returns the existing one, without linking it.
The file stays where it is; the client attaches the object to a source or citation only on the user's explicit
choice. Test: `test_medienobjekt_aus_archivdatei`.

Also: **Changing a name no longer loses its details.** Until now, editing a name through `Fact`
removed the nickname (`NICK`) and the name prefixes (`NPFX`, `SPFX`) along with the parts derived from the name. Now
only `GIVN`, `SURN` and `NSFX` are rebuilt from the new name (`NSFX` is new: the text after the surname, e.g. "jun."),
everything else under the name stays; a prefix such as "Dr." is no longer counted among the given names. Test:
`test_name_aendern_behaelt_unterangaben`.
**Source citations carry their page.** Each entry in `sources` of a fact now has `page` – the `PAGE` of the citation
("Baptisms 1833, no. 19"), multi-line with line breaks; empty if there is none. Only for sources the viewer may see,
like the title. Older clients ignore the new field. Test: `test_quellenverweis_mit_seite`; the test tree has a public
and a confidential source.

## 1.9.6 – 2026-09-27
API level 17, unchanged. **Anniversaries stay small on large trees.** A tree with 50,000 individuals has hundreds of
anniversaries of long-dead people every day; 14 days came to 5.5 MB in 4 s. Now at most 100 entries are returned –
per day living people first, then round anniversaries (25, 50, 75 …) – with short person entries (`xref`, `name`,
`sex`, `isDead`, `private`, `lifespan`, `thumb`, `url`); new fields `total` and `more`. 14 days on 50,000 individuals:
45 KB in 1.5 s. Clients read the answer as before.
**Documentation and tests:** [docs/API.md](docs/API.md) and [docs/openapi.json](docs/openapi.json) describe every route,
generated from real answers; `tests/` checks privacy (no role sees more than webtrees shows it), writing rights, CSRF
and that every answer matches the documentation, on every push. Performance figures for 10,000 and 50,000 individuals
are in the documentation.

## 1.9.5 – 2026-09-27
API level 17, unchanged. **Apple devices get an honest note.** There is no program for Mac, iPhone and iPad yet, so
the page “App” now says so and recommends the browser instead of showing the Android app first; the note after signing
in is not shown there. The footer names wtWin for everyone (“Program for the PC: wtWin · App for Android”) – wtTux is
on the page “App”, opened for Linux PCs.

## 1.9.4 – 2026-09-27
API level 17, unchanged. **wtWin and wtTux connect with one click.** On the page “App” at the PC, step 2 is now a
button “Connect with wtWin” (or wtTux): it puts the connect link with the one-time code on the clipboard and also opens
it as `wtwin://connect?…` / `wttux://connect?…`. wtWin/wtTux 1.21 take it over by themselves and ask once for
confirmation – no address, username or password to type. “Copy address” stays below as the manual way. The page reloads
by itself when you come back after the code has run out (e.g. after download and installation). The note after
signing in and the footer now also depend on the device: at the PC they point to wtWin/wtTux, and the note remembers
phone and PC separately – whoever has connected wtAnd still hears about wtWin. The page “App” shows wtWin and wtAnd
open (on a phone wtAnd first) and wtTux folded below, opened when the page is visited from a Linux PC.

## 1.9.3 – 2026-09-27
API level 17, unchanged. **The page “App” helps at the PC too.** It reads the browser's operating system and shows the
matching program first: on Windows wtWin, on Linux wtTux, on phones wtAnd as before; the others fold away under
“Other devices”. For the PC there are two steps: a download button for the newest `.exe` or `.deb` (looked up on
GitHub only when clicked; otherwise it opens the release page), with the one sentence needed for the Windows
warning, and the address of this family tree with a “Copy address” button – which also works over `http://` at home.
wtWin/wtTux 1.20 pick up the copied address by themselves.

## 1.9.2 – 2026-09-26
API level 17, unchanged. **Apps at home without HTTPS** (for nas4webtrees, where webtrees runs under
`http://<nas-ip>:8095`): the page “App” now offers the connect button and QR code also over `http://` inside the home
network – private, loopback and link-local addresses (`10/8`, `172.16/12`, `192.168/16`, `127/8`, `169.254/16`,
`::1`, `fc00::/7`, `fe80::/10`), host names without a dot and the endings `.local`, `.lan`, `.home`, `.home.arpa`,
`.internal`, `.fritz.box`, `.box`. The same rule decides in the apps (from wtAnd/wtWin/wtTux 1.19). A short note says
“Unencrypted – home network only”; the status line in the module settings shows the home network in yellow instead of
red. Public `http://` addresses stay blocked.

## 1.9.1 – 2026-09-26
API level 17, unchanged. **Bug fix:** fact values over several lines – above all notes with `CONT` lines – lost
their line breaks, so the lines ran together (“…seines Vaters.In der Familie…”). `facts[].value` now keeps line
breaks as `\n` and paragraphs as a blank line; single-line values are unchanged. Found by the desktop client's book.

## 1.9.0 – 2026-09-26
New fields and actions only; existing answers keep all their fields. Each addition raises the API level, so a client
can tell exactly which of them a server has.
- **Level 13 – `Relationship`** `?xref1=…&xref2=…`: how two people are related, the way webtrees' relationship chart
  finds it – the shortest paths through the families (at most 5), each with its steps (`person`, `relation`,
  `family`), the relationship `name` as webtrees words it and the `commonAncestors` at the top of the path. With
  pedigree collapse there are several paths of the same length. Privacy as in the chart; new error `chart-disabled`.
- **Level 14 – `call`, `chr`, `buri`, `occupation`** on every person: the call name (given name marked with `*`, or
  `_RUFNAME` as in GEDCOM-L), christening (`CHR`, else `BAPM`) and burial (`BURI`, else
  `CREM`) with date and place like `birth`/`death`, and the first occupation. For charts and lists that show more
  than birth and death without fetching each person.
- **Level 15 – `Pedigree?siblings=1`**: each ancestor carries `siblings`, the other children of the family its parents
  come from. For ancestor charts with siblings. Without the parameter the answer is unchanged.
- **Level 16 – `Pedigree` allows 12 generations** (was 7), for large ancestor charts. `generations` in the answer
  reports the depth actually delivered, so a client sees when an older module stopped at 7.
- **Level 17 – `Export?page=…`**: the whole visible tree, page by page – individuals with all facts and media, then
  families, linked by xref only. For lists and books in the desktop client. Privacy is webtrees' own: whoever
  appears in a family or chart appears here, hidden records as placeholders without facts, following the tree
  setting “show private relationships”.

## 1.8.0 – 2026-09-26
API level 12. New fields only; existing answers keep all their fields.
- **`Individual.stepFamilies`**: the families of the parents with other partners – their children are the
  half-siblings. Same form as `parentFamilies`, plus `parent` (the shared parent); `spouse` is the other partner.
  webtrees shows the same in its "Families" tab.
- **`hasParents`, `partnersCount`, `childrenCount`** for the person and everyone in its parent, spouse and step
  families (`Individual` only, not in lists or search): whether a view can expand from there without fetching each
  person. Suggested by Andreas Scharf for his own app.
- **`Descendants` allows 10 generations** (was 4), for printable descendant charts in the desktop client. The answer
  keeps its form; `generations` reports the depth actually delivered, so clients can tell an older module (4) and
  fetch the rest piece by piece.

## 1.7.0 – 2026-09-23
API level 11.
- **`Bookmarks`** (GET) and **`Bookmarks`** (POST `{ xref, add }`): a bookmark list of persons per signed-in user and
  tree. Stored as a webtrees user preference – no extra module, nothing in the GEDCOM, no pending change. The
  desktop client wtWin shows it as "Merkliste"; any client may use it. Guests get `not-logged-in`.

## 1.6.1 – 2026-09-23
**Bug fix – please update.** Since 1.5.0 the `spouse` of a family in the Individual answer could be wrong: the loop that
collects the children's marriages overwrote it, so the app showed the partner of the last child (e.g. a son-in-law)
instead of the actual spouse. `husband`/`wife` were always correct. API level 10, unchanged.

## 1.6.0 – 2026-09-23
API level 10, unchanged. Two additions for the desktop client; existing answers keep all their fields.
- **`given` and `surname`** on every person (surname including its prefix, e.g. "de' Medici"), for register-style names
  "Surname, Given" in the desktop client. Empty for private persons or unknown names.
- **`Pedigree` allows 7 generations** (was 6), for the navigator of the desktop client. API level unchanged; older clients keep asking for up to 6.

## 1.5.1 – 2026-09-23
API level 10, unchanged. Only a link has changed.
- **The app's repository is now [app4webtrees](https://github.com/thobgg/app4webtrees).** The download button on
  the “App” page points there. The Android app keeps its name wtAnd; a desktop client for Linux and Windows is in
  the works. The old address still forwards, so older versions of this module keep working.

## 1.5.0 – 2026-09-22
API level 10. One new field; existing answers keep all their fields.
- **`spouseFamilies[].children[].marriages`**: the marriages of each child – `family`, `spouse` (name, empty if
  private), `date`, `place`; `date` is `null` for an undated marriage. wtAnd 1.16 lists a son's or daughter's
  wedding in the parent's timeline, next to the births of the children.

## 1.4.0 – 2026-09-22
API level 9. One new field; existing answers keep all their fields.
- **`media[].path`**: the file's path inside the tree's media folder (`Familienfotos/hochzeit-1928.jpg`),
  `null` for media linked by URL. Lets an app name the same file to another module – wtAnd 1.13 uses it to edit
  the EXIF details of a person's photo through the [Sammlungen](https://github.com/thobgg/webtrees-sammlungen)
  module, the way it already does for archive pictures.

## 1.3.2 – 2026-09-21
- **Fix: the app could not connect after the 1.3.0 rename.** webtrees names a custom module after its folder
  (`_api4webtrees_`) and ignores what `setName()` in the module says. So the address did change with 1.3.0 after
  all – contrary to what its entry below claims – and the module's own name check (language switch, tree
  restriction) silently stopped matching. The module now uses the folder-derived name everywhere. Addresses are
  `…/module/_api4webtrees_/<Action>[/<tree>]`; wtAnd 1.7 knows both names and picks the one the server answers to.
- **Module settings survive the rename.** webtrees stores them under the module name, so after the update the
  tree restriction and a second app were gone. On first run under the new name the module copies its settings and
  access levels from `_webtreesand-api_`. The old entry stays listed under *Modules → Deleted modules* until you
  remove it there.

## 1.3.1 – 2026-09-21
- **Fix: `Anniversaries` stops working on webtrees 2.3.** In 2.2 `Registry::timestampFactory()->now()` returns a
  `Timestamp`, which has `julianDay()`. From 2.3 it returns a `CarbonImmutable`, where that call throws
  “Method julianDay does not exist.” Today's Julian day is now computed with the Gregorian calendar class that
  webtrees ships and uses itself, which works on both versions. Found and fixed by Andreas Scharf.
- **Fix: language files other than `en.php` were never loaded.** `customTranslations()` returned English for every
  language except German, so a contributed translation had no effect. It now takes `resources/lang/<tag>.php`,
  falls back to the language without the region (`nl-BE` → `nl`) and only then to English.
- **Dutch translation**, contributed by TheDutchJewel – which is how the loader bug came to light.
- `build-release.sh` now compares the keys of every language file against `en.php` and names stale and missing
  ones. A stale key falls back to German without a word of warning, and nobody notices.

## 1.3.0 – 2026-09-21
- **Renamed.** The module is now `api4webtrees`, the app `wtAnd`. Only names and texts have changed – the API level
  stays 8, every address and every answer is unchanged, and the module keeps its settings.
  **When updating, delete the old `modules_v4/webtreesand-api` folder first**; otherwise the same module lies in
  `modules_v4` twice.
- **No more “App” menu entry.** Instead, signed-in users see a note “The family tree on your phone” at the top of the
  page until their app is connected (`Pair`, or the app calling `Info`) or they click *Do not show again*. After that,
  a footer link “App for Android” leads to the “App” page. Only in enabled trees.
- The settings page explains the sign-in QR code and links to the “App” page of every enabled tree.

## 1.2.0 – 2026-09-19
API level 8. New fields and actions only; existing answers keep all their fields.
- **`Places?q=`**: place names of the tree as suggestions while typing (editors only, up to 20), searched per level
  like webtrees' own autocomplete: `Wien, Ö` finds “Wien, Österreich”.
- **Dates in GEDCOM form:** every fact date (`facts[].date`) now carries `gedcom` (`"ABT 1850"`, `"9 NOV 1957"`)
  next to the display `text`. Clients can pre-fill an edit form without translating the display back.
- **Photos:** each entry in `media[]` of `Individual` and `Family` carries `factId` and `primary`. New actions
  `UnlinkMedia` (remove a photo from a person; the media object stays) and `PrimaryMedia` (make a photo the main one,
  which webtrees takes from the first linked image).
- **`Link`**: links two existing people as child, spouse, father or mother – the counterpart to `Unlink`, with the
  same family rules as `AddIndividual`.
- **`AddIndividual` with `facts`**: further facts (occupation, residence, note …) are stored together with the new
  person in one step; if one of them is invalid, nothing is created.
- **`Individuals?scope=all`**: every search word must appear somewhere in the person's visible facts, not only in the
  name (`Huber Wien`). Facts the user may not see are not searched.
- **`Info.trees[].lastChange`**: number of the latest change in the tree, so that clients can tell whether cached data
  is still current.

## 1.1.0 – 2026-09-18
A second app next to wtAnd; the API level stays 7, the JSON answers do not change.
- **Settings: “Another app (optional)”.** A manager can enter a second app that follows the same interface – name,
  download addresses for Android and iPhone/iPad (https only) and the scheme of its connect link. With a name entered,
  the “App” page offers both downloads, and “Connect” shows one button per app; the QR code leads to the “Connect”
  page, which then lets the person choose instead of forwarding at once. The one-time code is the same for both and
  is used up by whichever app redeems it. wtAnd stays first and remains the default; with the fields empty
  nothing changes.
- The connect link is now documented as a contract for other apps: `<scheme>://connect?url=…&code=…&tree=…&user=…`,
  redeemed with `POST Pair {code}`.

## 1.0.0 – 2026-09-18
Hardening after a security review of the module; the API level stays 7, nothing changes for clients that use the
documented fields.
- The one-time code of the “Connect” QR code travels in the URL fragment (`#code=…`) instead of the query string:
  it no longer reaches the web server and therefore no access log. The “Connect” page builds the app link in
  JavaScript and removes the fragment from the browser history.
- `Fact`: the GEDCOM of one fact may contain only one level-1 line; every further line must be a sub-line (levels 2–9)
  with a valid tag (`invalid-gedcom`). This closes a gap where the raw `gedcom` field could smuggle in further
  level-1 lines (`FAMS`, `OBJE`, `RESN`, …) past the `link-tag-not-allowed` rule. Values, places and notes of the
  form `@X@` are rejected (`invalid-value`) – for GEDCOM they would be pointers, not text. A `NAME` needs its surname
  between exactly two slashes and no `@` (`invalid-name`).
- `AddIndividual`: slashes and `@` are removed from given names and surnames; places of the form `@X@` are rejected.
- `Media`: the `folder` parameter is gone – webtrees ignored it anyway (`auto=1` stores the file under its SHA-1 name
  directly in the tree's media folder). The README says so now.
- Cosmetic: the “admin only” check in the middleware compares case-insensitively, like webtrees itself.
- `Pending` no longer fails with “Invalid GEDCOM record” when a record was created and deleted again while both
  changes are still pending; the entry is listed with name and type taken from the raw GEDCOM.
- Code split by task: the module class stays the entry point, `src/` holds the pages, the read and write endpoints,
  the JSON builders and the pure GEDCOM text helpers. English texts moved to `resources/lang/en.php`.

## 0.8.0 – 2026-09-17
- **Settings page** in the control panel (wrench icon in the module list): install the app (QR code), choose **which
  family trees the app may reach**, jump to the “App” page of a tree to connect, and see the status (https, upload limit).
- Trees that are not enabled cannot be reached through this module at all – for any user, whatever their rights in
  webtrees (error `tree-disabled`, API level 7); the “App” menu entry is hidden there too. Default: all trees, as before.

## 0.7.0 – 2026-09-17
- New page **“App”** (menu entry for signed-in users): install the app (link + QR code) and **connect it to your
  account with one tap** – no address, no password to type. The QR codes are generated on the server (TCPDF /
  tc-lib-barcode, both shipped with webtrees).
- API level 6: `Pair` redeems the one-time code (valid for 10 minutes, once, only over https; only its hash is stored).

## 0.6.0 – 2026-09-17
- API level 5: moderation for moderators and managers – `Pending` (records with pending changes), `Accept`,
  `Reject` (one record, or the whole tree without `xref`); `Info.trees[]` gains `canModerate` and `pending`.
- `Info.maxUpload`: the largest upload this server accepts (PHP `upload_max_filesize` / `post_max_size`), so that
  clients can shrink photos to fit.

## 0.5.0 – 2026-09-17
- API level 4: `Anniversaries` (births, marriages and deaths of the next days), `DeleteRecord` (hands over to
  webtrees' own delete logic, including removing links and empty families), `Unlink` (remove a person from a
  family, both records stay).
- Place coordinates fall back to webtrees' location table when the fact itself carries none.

## 0.4.1 – 2026-09-17
- API level 3: `?lang=de` (or `en-GB`, …) selects the language of labels, dates and relationship names for
  that one answer. The session and the user's language preference are left alone. Without it, a client
  running in German showed “Occupation” when the webtrees account was set to English.

## 0.4.0 – 2026-09-17
- API level 2: `MediaList` (photo overview), `Individual.relationship` (“great-grandmother” relative to
  `relativeTo` or the user's own record), `Pedigree.ancestors[].hasParents` (expand a branch upwards),
  `Info.trees[].individuals`, `facts[].known` (vendor tags webtrees has no definition for).
- Family records no longer list their `HUSB`/`WIFE`/`CHIL` links as facts.
- README: recommendation to use one media folder per tree.

## 0.3.0 – 2026-09-17
- Module description is translatable (German source, English for all other languages).
- Update notice in the webtrees control panel (`latest-version.txt`).
- Friendlier labels for vendor tags such as `_INET`.

## 0.2.1 – 2026-09-17
- Domain errors are returned as HTTP 200 with `{"ok":false,"error":…,"status":…}`: many web servers
  (e.g. Synology Web Station) replace the body of 4xx/5xx answers with their own error page.

## 0.2.0 – 2026-09-17
- Write access: `Fact`, `DeleteFact`, `AddIndividual`, `Media`, plus `Tags`.
- People list sorted by primary name (married names no longer change the sort position).

## 0.1.0 – 2026-09-17
- Read access: `Info`, `Individuals`, `Individual`, `Family`, `Pedigree`, `Descendants`.
