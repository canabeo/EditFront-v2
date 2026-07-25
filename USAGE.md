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

## Plugins

Custom block types live in `plugins/<slug>/`. Drop a plugin folder in, and its
element type appears in the insert palette. A plugin that fails its
correctness checks is loaded read-only and never breaks the page. Plugin PHP is
trusted code (like a WordPress plugin) — only install plugins you trust.

## Security notes

- `.env`, `storage/`, `vendor/`, `app/`, `tests/` and plugin sources are denied
  direct web access by the bundled `.htaccess`.
- All write actions require a logged-in session and a CSRF token; logins and
  the API are rate-limited.
- Keep `APP_DEBUG=false` in production.
