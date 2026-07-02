# Security policy

## Reporting a vulnerability

If you find a security issue in EditFront v2, please report it privately:

- Open a [GitHub security advisory](https://github.com/canabeo/EditFront-v2/security/advisories/new) (preferred), or
- email **null.citizen0@gmail.com** with `EditFront security` in the subject.

Please include the affected file/route, a proof-of-concept, and the impact.
Do **not** open a public issue for undisclosed vulnerabilities. We aim to
acknowledge reports within a few days.

## Threat model

EditFront is a **single-admin, drop-in** CMS that edits a site's real HTML
files in place. Understanding the trust boundaries helps you judge severity:

- **The administrator is trusted.** Anyone who signs in can edit the site,
  upload files, add raw CSS/JS via plugins, and install plugins. Plugin PHP is
  trusted code (like a WordPress plugin) — only install plugins you trust.
- **Anonymous visitors** can reach only a small surface: the login and install
  pages, `sitemap.xml`, and — if the review moderation feature is used — the
  public review-submit endpoint. Everything else requires a session and a CSRF
  token.
- **Submitted review text is untrusted.** It is stored pending moderation and
  is neutralized before it can appear on a public page.

Issues that a **visitor** can trigger (auth bypass, path traversal, stored XSS
reaching the public site, secret disclosure, denial of service) are treated as
high severity. Issues that require an already-authenticated admin acting against
their own site are lower severity unless they cross a trust boundary.

## Hardening checklist for operators

- Keep `APP_DEBUG=false` in production (the installer sets this).
- Serve over HTTPS. The bundled headers include HSTS.
- Keep the bundled `.htaccess` (or the equivalent nginx rule) in place — it
  denies web access to `.env`, `storage/`, `app/`, `vendor/`, `tests/` and
  plugin sources.
- Run the install wizard immediately after upload; it closes itself (410 Gone)
  once an administrator exists.
- Change the admin password from the account page after first sign-in.
- Only install plugins you trust.

## Supported versions

The latest tagged release receives security fixes.
