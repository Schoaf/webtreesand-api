# Changelog

## Unreleased
- **Fix:** `Anniversaries` threw a 500 (`Method julianDay does not exist.`) on every call. `Registry::timestampFactory()->now()->julianDay()`
  resolves to a `CarbonImmutable` macro that isn't actually implemented; converting today's date via the
  Gregorian-calendar library webtrees itself ships works reliably instead.

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
A second app next to webtreesAnd; the API level stays 7, the JSON answers do not change.
- **Settings: “Another app (optional)”.** A manager can enter a second app that follows the same interface – name,
  download addresses for Android and iPhone/iPad (https only) and the scheme of its connect link. With a name entered,
  the “App” page offers both downloads, and “Connect” shows one button per app; the QR code leads to the “Connect”
  page, which then lets the person choose instead of forwarding at once. The one-time code is the same for both and
  is used up by whichever app redeems it. webtreesAnd stays first and remains the default; with the fields empty
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
