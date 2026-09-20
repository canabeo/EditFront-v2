# EditFront v2 — usage

EditFront edits your existing static HTML site **right on the page**. No
database, no build step. Unpack it into a subfolder of the site, run the
install wizard, and start editing.

## Requirements

- PHP **8.2+** with `dom`, `mbstring`, `json`, `fileinfo`
- Apache with `mod_rewrite` — the bundled `.htaccess` protects the CMS
  internals. **On nginx you must apply `nginx.conf.example`**: nginx ignores
  `.htaccess`, so without it `storage/admin.json` (your password hash) and
  `.env` are downloadable by anyone. The install wizard verifies this from your
  browser and refuses to create the admin while `storage/` is reachable.
- Write access to the CMS folder during install (to create `.env` and
  `storage/`)

## Install

1. Unzip into your site so you get a folder next to your pages, e.g.
   `https://example.com/cms/`.
2. Open `https://example.com/cms/install` and follow the 3 steps:
   - **Environment check** — confirms PHP, extensions and writable storage.
   - **Your site** — shows how many HTML pages were found.
   - **Create the administrator** — sets your login.
3. After install, `/install` is closed (returns *410 Gone*) and you are sent
   to the sign-in page.

If the CMS lives at a path other than `/cms`, set `BASE_PATH` in `.env` to
match (see `.env.example`).

> **Run the wizard immediately after uploading.** Until an administrator is
> created, anyone who reaches `/install` first could create it. The installer
> closes itself (410 Gone) the moment an admin exists.

## Editing

- Sign in at `/cms/login`.
- The dashboard lists every `.html` page on the site. **Edit** opens it.
- Click an element to select it; a small context panel appears with the
  actions that make sense for that element (edit text, size, align, replace
  image, duplicate, move, delete). There is no permanent inspector — panels
  appear on demand.
- Raw CSS lives behind **Advanced** in the panel; it is collapsed by default.
- Every action is one undo step (**Ctrl+Z** / **Ctrl+Y**). **Ctrl+S** saves.
- Drafts are kept automatically; reopening a page restores your in-progress
  history.

## Pages, images, backups

- **New / duplicate / delete pages** from the dashboard. A deleted page is
  backed up first.
- **Replace image** opens an upload / gallery / URL picker. Uploaded files go
  to `images/uploads/` in your **site** (outside the CMS folder), so they keep
  working even if you later remove the CMS.
- **Backups** (⏱ in the editor) lists pre-save snapshots; **Restore** rolls a
  page back. Restoring always backs up the current version first, so it is
  never destructive.

## Important: the first open normalizes a page

The first time you open a page, EditFront re-serializes its HTML to a
canonical form so future diffs stay clean. **Your original is always saved to a
pre-save backup** before this happens, and you can restore it.

If you would rather keep your original formatting as close as possible, enable
**Minimal-touch mode** on install step 2 (or set `ANNOTATE_ONLY=true` in
`.env`): EditFront then only adds the ids it needs to address elements, without
reformatting the rest of the HTML.

## Fonts in the editor

The editor renders your page in a sandboxed frame, which browsers treat as a
separate origin. Fonts are always fetched under CORS rules, so your web fonts
and icon fonts need one header to show up inside the editor — without it the
page still edits correctly, it just renders in fallback typography.

The bundled `nginx.conf.example` already contains the rule. On Apache, add this
to the `.htaccess` in your **site root** (not the CMS folder):

```apache
<IfModule mod_headers.c>
  <FilesMatch "\.(woff2?|ttf|otf|eot)$">
    Header set Access-Control-Allow-Origin "*"
  </FilesMatch>
</IfModule>
```

Fonts are public files, so allowing any origin to read them changes nothing
about who can see them.

## Marking up a page for the editor

Two attributes you can put in your own HTML change how EditFront treats a part
of the page. Both are optional; a page without them works exactly as before.

### `data-cms-protected="true"` — this part is not yours to edit

Put it on anything a script keeps up to date: a countdown, a date that renews
itself, a price pulled from elsewhere. The editor will not select it, will not
let you type in it, and the server refuses any save that would remove it —
including a save aimed at the box **around** it, which used to wipe such content
silently.

Because the protection covers the whole box you edit, keep the automatic part in
its own element and the words around it in another, so the rest stays editable:

```html
<h2><em>Sale of <span data-cms-protected="true">September</span></em><span>: four reasons to order now</span></h2>
```

Here the tail after the colon is editable; the month is not.

### `data-cms-faq` — keep the FAQ rich result honest

Put it on the container of your FAQ list. On every save EditFront rebuilds the
`FAQPage` JSON-LD from what a reader actually sees, so an edited question can
never leave the search snippet showing the old wording.

Each **direct child** of the container is one question: its first heading is the
question and the rest of its text is the answer. If the question is not a
heading, mark it with `data-cms-faq-q`.

```html
<div class="faq" data-cms-faq>
  <div><h3>How long is the warranty?</h3><p>Ten years.</p></div>
  <div><h3>Do you deliver?</h3><p>Yes, and we install on site.</p></div>
</div>
```

Only `mainEntity` of the `FAQPage` node is rewritten — the rest of your graph is
left alone, and a list you did not change rewrites nothing.

## Plugins

Everything that is not part of every EditFront install lives in
`plugins/<slug>/`. Drop the folder in and it works; delete the folder and every
trace of it goes with it. A plugin that fails its correctness checks is loaded
read-only and never breaks the page. Plugin PHP is trusted code (like a
WordPress plugin) — only install plugins you trust.

A plugin can be two things, separately or together.

**A block type.** A new kind of content you can insert and edit on a page. It
appears in the insert palette; the core edits it through a form generated from
its schema, so the plugin needs no editor code of its own.

**A module.** A piece of the site rather than of a page: its own endpoints, its
own screen in the admin, its own private storage. Contact forms, a booking box,
an export job for one client — the work that differs from site to site.

The core owns exactly two URLs for every module, so a plugin can never shadow a
core path or reach outside its own corner:

```
POST {base}/api/p/{slug}/{action}
GET  {base}/settings/p/{slug}
```

The manifest declares what exists:

```json
"module": {
  "php":     "src/BookingModule.php",
  "class":   "Acme\Booking\BookingModule",
  "actions": ["submit", "list", "config"],
  "public":  ["submit"],
  "admin":   { "label_key": "booking.title", "order": 40 }
}
```

`actions` is a whitelist — an action not listed has no route at all. `public`
must be a subset of it, and means "reachable with no session and no CSRF token",
which is how a form on your static pages posts to it. Every other action
requires a signed-in admin, checked by the core, not by the plugin. An action
listed as public is on its own: it must rate-limit itself and treat every field
as hostile.

The admin screen appears in the dashboard automatically; `label_key` is looked
up in the plugin's own `lang/` files, which the core merges into its
dictionaries with no wiring on your part.

Storage is handed to the module as a directory of its own under
`storage/plugins/<slug>/` — private, outside the web root, created on first use.
A plugin never writes anywhere else.

A module plugin declares no `kinds`, no `server` class and no `fixtures` — those
belong to block types. Its tests live in `plugins/<slug>/tests/` and run with
the rest of the suite.

## Security notes

- `.env`, `storage/`, `vendor/`, `app/`, `tests/` and plugin sources are denied
  direct web access by the bundled `.htaccess`.
- All write actions require a logged-in session and a CSRF token; logins and
  the API are rate-limited.
- Keep `APP_DEBUG=false` in production.
