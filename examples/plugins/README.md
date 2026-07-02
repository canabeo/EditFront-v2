# Example plugins

These are **reference plugins**, not part of the default install — they are not
shipped in the release ZIP and are not auto-loaded. They exist to show how to
build custom block types, especially the harder case of **adopting markup that
already exists on a real site**.

To try one, copy its folder into the CMS `plugins/` directory:

```bash
cp -r examples/plugins/adopt-gallery plugins/
```

It appears in the insert palette on the next request (if it passes its
round-trip fixtures gate).

## What's here

- **adopt-gallery** — adopts a gallery stored as
  `<div class="project-card" data-images='[...]'>` (a JSON list of image URLs
  behind a cover + dots) and renders it back exactly, so the site's own
  lightbox keeps working. Shows a custom `mountEditor`, `matches()`/
  `extractProps()` for adopting raw markup, and a transparent
  `display:contents` block wrapper that stays out of the layout.

- **adopt-before-after** — adopts a before/after image pair
  (`.project-showcase__ba-grid`) with per-image picker, caption and alt text.

Both target one specific real-world template's markup;
treat them as worked examples rather than drop-in features. The bundled
`plugins/pricing-table` is a simpler, self-contained example that inserts new
markup instead of adopting existing markup.

> Plugin PHP is trusted code (like a WordPress plugin). Only install plugins you
> trust.
