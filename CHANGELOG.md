# Changelog

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
