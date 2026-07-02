# Changelog

All notable changes to EditFront v2 are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

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

[1.0.0]: https://github.com/canabeo/EditFront-v2/releases/tag/v1.0.0
