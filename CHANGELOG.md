# Changelog

## Unreleased
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
  `_RUFNAME` as written by Ahnenblatt and GEDCOM-L), christening (`CHR`, else `BAPM`) and burial (`BURI`, else
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
