# api4webtrees

**English** · [Deutsch](README.de.md)

A [webtrees](https://webtrees.net/) module that gives the native Android app **[wtAnd](https://github.com/thobgg/app4webtrees)**
a JSON interface for reading **and** writing. It is an ordinary custom module: it lives in
`modules_v4/`, the webtrees core is not modified.

**Why:** whoever maintains the tree at the PC keeps working in webtrees as before. The rest of the family, who so far
only had a website that is hard to use on a phone, get the tree on phone and tablet through the app: tree,
relationships, anniversaries and photos. Changes made in the app, such as a photo of a gravestone or a corrected date,
arrive as pending changes in the same webtrees installation.

| | |
| - | - |
| webtrees | 2.2.x (tested with 2.2.6); prepared for 2.3 |
| PHP | 8.3 or newer (as required by webtrees 2.2) |
| Access | read and write, always with the rights of the signed-in webtrees user |
| License | GPL-3.0 |

## The app: wtAnd

The module is the server side of **[wtAnd](https://github.com/thobgg/app4webtrees)**, a native Android app
(Kotlin, no WebView) for phone and tablet. [Download the APK](https://github.com/thobgg/app4webtrees/releases/latest)
(signed). Outside the Play Store, Android asks once to allow your browser to install apps.

| Tablet: tree and profile side by side | Phone: the profile as a timeline |
| - | - |
| ![Tree and profile side by side](docs/app-tablet.png) | ![Profile on a phone](docs/app-handy.png) |

- **Tree:** hourglass view with ancestors, partners, children and grandchildren, siblings on request;
  pan and zoom freely, unfold branches upwards, make any person the centre.
- **Profile:** a timeline of the person's life (with marriage and the births of the children), the relationship to
  yourself (“paternal grandfather”), photos, family, a map of where the person lived.
- **Editing:** add, change and delete events, add relatives with the “+” on any card, take or pick photos and attach
  them to a person, resized to fit the server's upload limit.
- **Anniversaries** of the coming days, with a daily reminder if you like; moderators accept or reject pending changes in the app.
- **Connect with one tap:** the “App” page in webtrees hands server, tree and a one-time code to the app.

German and English; the labels of the server come in the language of the app. Whatever is not native yet opens as a
webtrees page in the same session. All pictures show the fictional demo tree “Familie Falkenrath”.

## Installation

1. Download the ZIP from the [latest release](../../releases/latest).
2. Unpack it into `modules_v4/` of your webtrees installation, so that you get `modules_v4/api4webtrees/module.php`.
3. Done. The module is active and listed under *Control panel → Modules → All modules*.

Coming from version 1.2.0 or older: **delete the old `modules_v4/webtreesand-api` folder**, it was renamed in 1.3.0.
The module's address changed with it (`_api4webtrees_` instead of `_webtreesand-api_`); wtAnd 1.7 or newer handles both,
and the module takes over its settings from the old name on first run (1.3.2).

To update, replace the folder. To uninstall, delete it. The module creates no database tables. It stores
one module setting (which trees the app may reach) and, per user, only the hash of a pairing code while it is valid.

## Settings

*Control panel → Modules → All modules → api4webtrees → wrench icon* (tip: type “API” into the search box of the
module list). The page offers the app download, the status (https, upload limit) and the most important switch:
**which family trees the app may reach.** Trees that are not ticked cannot be reached through this module at all,
for any user, whatever their rights in webtrees. Default: all trees.

**Another app (optional):** here a second app that follows the same interface can be entered, for iPhone and iPad, say.
With the fields empty nothing changes. See [Connecting other apps](#connecting-other-apps).

![Settings page: install the app, family trees for the app, another app, status](docs/einstellungen.png)

## For family members: the “App” page

Signed-in users see a note **“The family tree on your phone”** at the top of the page with a button to the “App” page.
The note disappears for good once their app is connected (or when they click *Do not show again*); after that the
link **App for Android** in the footer leads there, for example for a new phone. The page offers two steps:

1. **Install the app:** a button and a QR code lead to the download.
2. **Connect your account:** one tap (or a QR code when sitting at a computer) hands the server address, the tree and
   a one-time code to the app. There is nothing to type, and the password never reaches the phone.

The one-time code is shown only to the signed-in user, is valid for 10 minutes and exactly once, and is only offered over
https. Only its hash is stored. Do not untick the module under *Control panel → Modules → Footers*: in webtrees that switches
off the whole module, including the API.

## Privacy and permissions

The module deliberately has no login and no permission system of its own:

- The app signs in with the normal webtrees login; every request runs as that user.
- Data is read only through the webtrees objects (`canShow()`, `facts()`, `children()` …). The same
  privacy rules apply as on the web pages: living individuals appear as “Private” to visitors,
  restricted facts are left out, trees that require a login stay invisible.
- Data is written only through webtrees' own functions (`createFact`, `updateFact`,
  `createIndividual`, `createFamily`, `MediaFileService`). Editor rights, `RESN locked`, the change
  log and moderation (“pending changes”) therefore work exactly as in the web interface. Every POST
  passes webtrees' CSRF check.

## One media folder per tree

If several trees are used by different groups of people, give each tree its **own media folder**
(*Control panel → Family trees → Preferences → Media folder*, e.g. `media/smith/`). This is a webtrees
matter, not one of this module: webtrees offers editors all files of the tree's media folder that the tree
does not use yet (“unused files”). With a shared folder, editors of one tree can see and link the files
of another.

## For developers

### Addresses

The module uses webtrees' built-in module route and registers no routes of its own (the routing API
changes in webtrees 2.3, the module route does not):

```
<base>/index.php?route=<path>/module/_api4webtrees_/<Action>[/<tree>]&<parameters>
```

`<path>` is the path part of the base URL: `/webtrees` for `https://example.org/webtrees`, empty for
an installation in the web root. webtrees expects the sub-folder **inside the `route` parameter**;
without it the answer is a 302 to the home page or a 404. With “pretty URLs” switched on, webtrees
redirects to `<base>/module/_api4webtrees_/<Action>/<tree>?…`; the `index.php?route=` form always
works, so a client need not know the setting.

Every action accepts `lang=<language tag>` (`de`, `de-DE`, `en-GB`, …): labels, dates and relationship names
of that answer come in this language if it is active in webtrees – otherwise in the language of the session.

Rules for clients:

- A response counts only if it is `Content-Type: application/json`. Anything else means “sign in”
  (302 / HTML) or “webtrees or the web server failed” (4xx/5xx).
- Use an honest `User-Agent` and always send `Accept-Language`. A client that claims to be
  Chrome/Firefox/Safari but has no cookie yet gets a “cookie check” (406) from webtrees' bot blocker.
- Do **not** send `X-Requested-With: XMLHttpRequest`: webtrees then answers its own errors with
  `200` and an HTML fragment.
- Domain errors come as **HTTP 200** with `{"ok":false,"error":"not-found","status":404}` (`status`
  is the intended code). Reason: many web servers (Synology Web Station, nginx with
  `fastcgi_intercept_errors`) replace the body of 4xx/5xx answers with their own error page.

### Signing in

1. `GET …/Info` – sets the session cookie and returns `csrf`
2. `POST <base>/index.php?route=<path>/login` with `username`, `password`, `_csrf`
   (webtrees rejects a login POST that carries no cookie yet)
3. `GET …/Info` – `user.loggedIn` tells whether it worked

Image addresses (`thumb`, `file`) are webtrees' signed media routes and need the same cookie. `path` (level 9) is the
file's path inside the tree's media folder, for naming the same file to another module; `null` for media linked by URL.

### Connecting other apps

Any client can use the interface with the normal sign-in above. A second app can also take part in the one-tap
connection if a manager enters it under *Settings → Another app* with its name, download addresses (https only) and its own URL
scheme; it then appears next to wtAnd on the “App” page and when connecting. The contract is the one
wtAnd uses:

1. The “App” page and the “Connect” page open `<scheme>://connect?url=<base URL>&code=<48 hex>&tree=<tree name>&user=<user name>`
   in the app. `tree` and `user` are hints for the display; `url` is the webtrees base URL.
2. The app calls `GET <url>…/Info` to obtain a session cookie and the `csrf` token, then `POST …/Pair` with header
   `X-CSRF-TOKEN` and body `{"code": "<code>"}` – like every other POST. Answer: `{"ok":true,"tree":"…","user":"…"}` – the session
   is now signed in as that user – or `{"ok":false,"error":"pair-invalid"|"pair-expired"}`.
3. The rules for the code are those [above](#for-family-members-the-app-page), whichever app redeems it.

Nothing else in the module is specific to one app: the JSON endpoints, rights and privacy are the same for every client.

### Reading (GET)

| Action | Tree | Parameters | Content |
| - | - | - | - |
| `Info` | – | – | versions, `api` level, user, visible trees with role, rights, number of individuals and (for moderators) of records with pending changes, `maxUpload` in bytes, CSRF token, `trees[].lastChange` (number of the latest change in the tree: a different value than last time means “reload”; compare for equality only, a new GEDCOM import resets it) |
| `Individuals` | yes | `q`, `page`, `scope?` | people by sort name, 50 per page, `nextPage`. `q` searches names; with `scope=all` every word must appear somewhere in the person's visible facts (`Huber Wien` finds the Hubers with Wien as birth place, residence …); error `too-many-results` when webtrees refuses the search |
| `Individual` | yes | `xref`, `relativeTo?` | person, facts (`known: false` marks vendor tags webtrees has no definition for), parent and spouse families, media, `relationship` to `relativeTo` (default: the user's own record), e.g. “great-grandmother”. Each fact date carries `gedcom` (`"ABT 1850"`) next to the display `text`, for pre-filling an edit form; each media entry carries `factId` and `primary` (the photo webtrees shows for the person) |
| `Family` | yes | `xref` | family with facts, children, media |
| `Pedigree` | yes | `xref`, `generations` (1–7) | ancestors with ahnentafel number `n`; `hasParents` tells a client that the branch can be expanded further |
| `Descendants` | yes | `xref`, `generations` (1–4) | descendants as a tree |
| `Pending` | yes | – | moderators only: records with pending changes (`new`, `changed`, `deleted`), who changed them and when |
| `Anniversaries` | yes | `days` (1–60, default 14) | births, marriages and deaths whose anniversary falls into the next days, with the number of years |
| `MediaList` | yes | `page` | all media objects of the tree, newest first, 60 per page, each with up to three linked people |
| `Tags` | yes | `type` (`INDI`/`FAM`) | labelled list of facts a client can offer for adding |
| `Places` | yes | `q` | editors only: up to 20 place names of the tree for suggestions while typing; `Wien, Ö` searches per level, like webtrees' own autocomplete |

### Writing (POST)

Header `X-CSRF-TOKEN: <csrf from Info>`, JSON body. Answer: `{"ok":true,"xref":"…","pending":true|false}` –
`pending` means the change waits for a moderator (users without “automatically accept changes”).

| Action | Parameters | Body |
| - | - | - |
| `Fact` | `xref` | `{factId?, tag, value?, date?, place?, note?}` or `{factId?, gedcom}` – with `factId` the fact is changed; sub-lines that are not mentioned (sources, media, coordinates) are kept |
| `DeleteFact` | `xref` | `{factId}` |
| `AddIndividual` | – | `{relation: child\|spouse\|father\|mother\|none, relativeTo?, family?, given, surname, sex, birthDate?, birthPlace?, dead?, deathDate?, deathPlace?, marriageDate?, marriagePlace?, facts?}` – `facts` is a list of further facts in the form of `Fact` (`[{tag:"OCCU", value:"Gardener"}, …]`), stored in the same step as the person; if one is invalid, nothing is created |
| `Accept`, `Reject` | `xref?` | – moderators only: accept or reject the pending changes of one record, or of the whole tree when `xref` is omitted; answer `{ok, pending}` |
| `DeleteRecord` | `xref` | – deletes the record with webtrees' own logic: links from other records are removed, a family left with one member and no events is deleted too |
| `Link` | – | `{individual, relation: child\|spouse\|father\|mother, relativeTo, family?, marriageDate?, marriagePlace?}` – links two existing people like `AddIndividual` does with a new one: `individual` becomes child, spouse, father or mother of `relativeTo`; `link-exists` if they are already linked that way |
| `Unlink` | – | `{family, individual}` – removes the person from the family; both records stay |
| `Media` | `xref` | `multipart/form-data`: `file`, `title?`, `note?` – uploads and links; the file is stored under the SHA-1 of its content directly in the tree's media folder |
| `UnlinkMedia` | `xref` | `{media}` – removes the link to the media object; the media object and its file stay |
| `PrimaryMedia` | `xref` | `{media}` – makes this the person's main photo by moving its link before all other media links. While the change is pending, a further edit of the same person restores the old order (webtrees keeps the order of the accepted record; its own “re-order media” page behaves the same) |

Dates in GEDCOM format (`12 MAR 1890`, `ABT 1850`, `BET 1900 AND 1910`). Error codes: `not-found`, `link-not-found`, `link-exists`, `invalid-relation`, `too-many-results`,
`private`, `not-editable`, `not-editor`, `fact-locked`, `family-locked`, `fact-not-found`,
`invalid-date`, `invalid-gedcom` (only one level-1 line, sub-lines 2–9 with a valid tag), `invalid-value` (text of the form `@X@` would be a pointer), `invalid-name` (surname between exactly two slashes), `link-tag-not-allowed`, `parent-exists`, `family-required`,
`family-not-found`, `name-required`, `upload-not-allowed`, `upload-failed`.

Not included yet: creating sources and repositories, merging records.

### Examples

Taken from the demo tree, shortened (`…`). Addresses are abbreviated to `<base>`.

`GET …/Individual/falkenrath?xref=I1&lang=de`

```json
{
  "person": {
    "xref": "I1", "name": "Jonas Falkenrath", "sortName": "Falkenrath,Jonas", "sex": "M",
    "isDead": false, "private": false, "lifespan": "1985–",
    "birth": { "date": { "text": "14. März 1985", "year": 1985, "jd": 2446139 },
               "place": { "name": "Hannover, Niedersachsen, Deutschland", "short": "…", "lat": null, "lng": null } },
    "death": null,
    "thumb": "<base>/index.php?route=/tree/falkenrath/media-thumbnail&xref=X88&…",
    "url": "<base>/index.php?route=/tree/falkenrath/individual/I1/Jonas-Falkenrath"
  },
  "relationship": "",
  "canEdit": true,
  "facts": [
    { "id": "8a7b17a3b1a629763d2960f7dffce835", "tag": "BIRT", "label": "Geburt", "known": true, "value": "", "type": "",
      "date": { "text": "14. März 1985", "year": 1985, "jd": 2446139, "gedcom": "14 MAR 1985" },
      "place": { "name": "Hannover, Niedersachsen, Deutschland", "short": "…", "lat": 52.3759, "lng": 9.732 },
      "notes": [], "sources": [] },
    …
  ],
  "parentFamilies": [ … ],
  "spouseFamilies": [ { …, "children": [ { …, "marriages": [ { "family": "F12", "spouse": "Dorothea Wichmann",
                        "date": { "text": "1893", "year": 1893, "jd": 2412413 }, "place": null } ] } ] } ],
  "media": [
    { "xref": "X88", "title": "Testbild", "mime": "image/png", "isImage": true,
      "thumb": "<base>/…/media-thumbnail&xref=X88&…", "file": "<base>/…/media-download&xref=X88&…",
      "url": "<base>/…/media/X88/Testbild", "path": "testbild.png",
      "factId": "8de7a5f0af3478f4da6ef3bf0b9c684b", "primary": true }
  ]
}
```

`GET …/Places/falkenrath?q=Cel`

```json
{ "query": "Cel", "data": ["Celle, Niedersachsen, Deutschland"] }
```

`POST …/AddIndividual/falkenrath` – a new person with an occupation and a residence, as child of `I1`:

```json
{ "relation": "child", "relativeTo": "I1", "given": "Test", "surname": "Neu", "sex": "F", "birthDate": "1 JAN 1990",
  "facts": [ { "tag": "OCCU", "value": "Gärtnerin" },
             { "tag": "RESI", "date": "ABT 2020", "place": "Bremen, Deutschland" } ] }
```

```json
{ "ok": true, "xref": "X92", "pending": true, "family": "F1" }
```

An error – HTTP 200, the intended code in `status`:

```json
{ "ok": false, "error": "link-exists", "status": 409 }
```

### Code layout

Start with the header of `Api4WebtreesModule.php`: it is the entry point and lists which part of the module lives
in which file under `src/`.

### Releases

`./build-release.sh` builds `api4webtrees-vX.Y.Z.zip` from the last commit. `latest-version.txt`
on the main branch feeds the update notice in the webtrees control panel.
