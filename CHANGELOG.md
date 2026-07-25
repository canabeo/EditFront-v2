# Changelog

All notable changes to EditFront v2 are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.1] — 2026-07-25

A security release. Every item was found by an external review of 1.0.0, then
reproduced against the code and fixed with a regression test. Upgrading is
recommended for every installation.

### Fixed — reachable without an account
- The install wizard stayed open on an instance provisioned through `.env`.
  `isInstalled()` only asked whether `storage/admin.json` existed, and that file
  is created on the first successful login — so a deployment configured but not
  yet logged into could be claimed by whoever reached `/install` first.
- `storage/` sits inside the document root and was protected only by the bundled
  `.htaccess`, which nginx ignores. The project now ships `nginx.conf.example`,
  and the install wizard verifies from your browser that `storage/` is not
  downloadable before it will create the administrator.
- `sitemap.xml` walked and HTML-parsed the entire site on every anonymous
  request. It is now cached on disk and rate-limited, and the indexability check
  no longer parses pages that cannot carry a robots directive.

### Fixed — the CMS could be turned against its own site
- Page delete accepted any path under the site root, including the CMS's own
  credential file — which re-opened the install wizard. Delete now applies the
  same name rule as create.
- `FileStorage` could reach into the CMS folder at all, because the site root
  contains it and no traversal is needed to get there. One chokepoint now
  refuses any path inside the CMS, closing the delete case above, the "publish
  `.env` as a page" case, and anything of that shape in future.
- Opening a page in the editor writes to it, on a GET, which CSRF cannot cover.
  Following a link was enough to have an arbitrary file rewritten through the
  HTML parser. Only real pages of the site may be opened now.

### Fixed — content that reaches visitors
- `attr.set` used a deny-list, so `srcdoc` passed every check and could put
  executable HTML into a published page. It is an allow-list now.
- The editor preview ran same-origin and unsandboxed, so any third-party script
  on the page being edited — analytics, a chat widget — ran inside the admin and
  could act as the logged-in administrator. The preview is now sandboxed, its
  API calls go through the shell, and the CSRF token never enters it.

### Editor
- Fonts and the icon sprite render correctly inside the sandboxed preview, so
  the editor still shows the page as visitors see it.

## [1.0.0] — 2026-07-02

First public release. A flat-file, drop-in CMS for visual editing of existing
static HTML sites — no database, no build step.

### Editing core
- On-page editing: click an element, get a small context panel with only the
  actions that make sense for it (edit text, size, align, replace image,
  duplicate, move, delete). No permanent inspector.
- Stable ids are stamped to disk on open (idempotent, atomic, with a pre-save
  backup); saving never re-annotates.
- Every action is one undo step (Ctrl+Z / Ctrl+Y); Ctrl+S saves.
- Fail-closed saves: no pre-save backup means no save. Atomic writes only
  (flock + tmp + fsync + rename).
- Automatic drafts restore in-progress history when a page is reopened.

### Pages, media, backups
- Create / duplicate / delete pages from the dashboard (delete backs up first).
- Image upload with automatic WebP conversion; uploads live in the site's own
  `images/uploads/` (outside the CMS folder, so they survive CMS removal).
- Pre-save backups with restore; restoring always backs up the current version
  first, so it is never destructive.

### Plugins
- Schema-driven custom block types in `plugins/<slug>/`; a plugin appears in the
  insert palette by dropping its folder in.
- Plugin operations get automatic undo/redo; a plugin that fails its
  round-trip fixtures is loaded read-only and never breaks the page.
- Example plugins under `examples/plugins/` show how to adopt non-standard
  markup on real sites.

### Content features
- Per-page SEO editor (title/description/canonical/robots) with a live Google
  snippet, plus a noindex-aware `sitemap.xml` and `robots.txt`.
- Visual news/blog engine with a WYSIWYG body editor, image galleries and
  prev/next navigation, rendered to static HTML.
- Review moderation: a public submit form feeds an admin queue
  (approve / edit / reject); approved reviews render into the site.
- Self-hosted fonts: upload font files or use bundled Cyrillic presets, picked
  from the editor; delivered via a managed `@font-face` block.

### Admin & i18n
- Install wizard (environment check → page detection → admin creation) that
  closes itself after use (410 Gone).
- Account page for changing the password; translation editor for overriding UI
  strings and adding languages. UI ships in English and Russian.

### Security
- Session + CSRF on all write actions; login is rate-limited with tiered
  captcha/lockout escalation and timing-equalized verification.
- Security headers (HSTS, CSP-friendly), `APP_DEBUG=false` by default.
- Layered HTML/CSS/URL/SVG sanitizers on all content that reaches public pages.
- Bundled `.htaccess` denies web access to internals.

[1.0.1]: https://github.com/canabeo/EditFront-v2/releases/tag/v1.0.1
[1.0.0]: https://github.com/canabeo/EditFront-v2/releases/tag/v1.0.0
