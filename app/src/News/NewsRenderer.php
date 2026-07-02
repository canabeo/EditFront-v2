<?php

declare(strict_types=1);

namespace EditFront\News;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;

/**
 * Pure DOM rendering: clone the skin / card node, fill data-nf slots by
 * tag-rule, regenerate the JSON-LD NewsArticle block, strip all marker
 * attributes, ensure annotated, serialize. No filesystem I/O.
 */
final class NewsRenderer
{
    /** Every marker attribute stripped from published output. */
    private const MARKER_ATTRS = [
        'data-nf', 'data-nf-attr',
        'data-news-template', 'data-news-card',
        'data-news-list', 'data-news-limit',
    ];

    /** Russian month names for date formatting (date_locale=ru). */
    private const RU_MONTHS = [
        1 => 'января', 2 => 'февраля', 3 => 'марта', 4 => 'апреля',
        5 => 'мая', 6 => 'июня', 7 => 'июля', 8 => 'августа',
        9 => 'сентября', 10 => 'октября', 11 => 'ноября', 12 => 'декабря',
    ];

    /** alt for the cover <img> differs article vs card; set per render call. */
    private ?string $coverAlt = null;

    public function __construct(
        private readonly NewsTemplate $template,
        private readonly Annotator $annotator,
        private readonly Html5 $html5,
        private readonly NewsStore $store,
    ) {
    }

    /**
     * Full serialized <html> article page: markers stripped, JSON-LD
     * regenerated, data-cms-id stamped, canonical serialize.
     *
     * $prev / $next are this article's date-neighbours (see Publisher): $prev
     * is the OLDER news, $next is the NEWER news. When given, a prev/next nav is
     * inserted right after the .aside-card block. Both default to null so every
     * existing call site / test keeps working unchanged.
     *
     * @param array<string,mixed>      $item
     * @param array<string,mixed>|null $prev older news (or null when none)
     * @param array<string,mixed>|null $next newer news (or null when none)
     */
    public function renderArticle(array $item, ?array $prev = null, ?array $next = null): string
    {
        $this->template->validate();

        $skin = $this->template->articleSkin();
        $doc = clone $skin; // deep clone the whole document so caching is preserved

        $values = $this->articleValues($item);

        $this->fillSlots($doc->documentElement, $values, skipCardTemplates: true);
        // Insert the gallery block (if any) BEFORE markers are stripped — it is
        // anchored on the still-marked [data-nf="body"] element.
        $this->insertGallery($doc, $item);
        // Prev/next nav after the .aside-card. The nav carries no markers, so it
        // is built here (order vs stripMarkers does not matter) and survives the
        // strip pass — it is positioned by the static `aside-card` class, not a
        // data-nf slot.
        $this->insertPrevNext($doc, $prev, $next);
        $this->regenerateJsonLd($doc, $item);
        $this->removeCardTemplates($doc);
        $this->stripMarkers($doc->documentElement);

        $this->annotator->ensureAnnotated($doc);

        return $this->html5->serialize($doc);
    }

    // ---- gallery block ----------------------------------------------------

    /**
     * Build a flat horizontal gallery block from item['gallery'] and insert it
     * INSIDE the article body's text flow, directly above or below the body text
     * per item['gallery_position'].
     *
     * Placement: the gallery lives INSIDE the [data-nf="body"] element (the
     * host site's <article class="article"> content column). For 'before' it is
     * inserted as the FIRST child of the body (a strip above the article text);
     * for 'after' it is appended as the LAST child (a strip below the text). It
     * therefore sits in the narrower content/text column — not full-width below
     * the whole article+aside section, and not in the aside. The site-template
     * CSS scrolls the thumbnail row horizontally within the column width.
     *
     * No-op when the gallery is empty / not a list, or when no body anchor
     * exists. URLs are already validated upstream (NewsStore::normalizeGallery
     * / NewsPublisher::normalize), but we still set them via the DOM API
     * (setAttribute) so no markup is ever built by string concatenation.
     *
     * Markup: <div class="ef-news-gallery"><img class="ef-news-img"
     *   src="<url>" alt="" loading="lazy"> …</div>. The .ef-news-img class is
     * the hook the site-template lightbox binds to; the site-template CSS lays
     * the row out horizontally with an overflow-x scroll.
     *
     * @param array<string,mixed> $item
     */
    private function insertGallery(\DOMDocument $doc, array $item): void
    {
        $urls = $item['gallery'] ?? null;
        if (!\is_array($urls) || $urls === []) {
            return;
        }

        $body = $this->findBodyElement($doc);
        if ($body === null) {
            return; // no body anchor — skip gracefully (gallery is dropped)
        }

        $gallery = $doc->createElement('div');
        $gallery->setAttribute('class', 'ef-news-gallery');
        foreach ($urls as $url) {
            if (!\is_string($url) || $url === '') {
                continue;
            }
            $img = $doc->createElement('img');
            $img->setAttribute('class', 'ef-news-img');
            $img->setAttribute('src', $url);
            $img->setAttribute('alt', '');
            $img->setAttribute('loading', 'lazy');
            $gallery->appendChild($img);
        }
        if (!$this->hasElementChild($gallery)) {
            return; // every url was unusable — emit nothing rather than an empty box
        }

        // IN-FLOW PLACEMENT: insert the gallery as a child of the body element
        // (the article text column) so it sits directly above/below the text.
        //   'before' → first child (strip above the text)
        //   'after'  → last child  (strip below the text)
        $position = ($item['gallery_position'] ?? 'after') === 'before' ? 'before' : 'after';
        if ($position === 'before') {
            // prepend: before the body's current first child, or append when empty
            if ($body->firstChild !== null) {
                $body->insertBefore($gallery, $body->firstChild);
            } else {
                $body->appendChild($gallery);
            }
        } else {
            $body->appendChild($gallery); // append as the last child
        }
    }

    /**
     * Locate the body-slot element ([data-nf="body"]) the renderer fills. This
     * is the same anchor fillSlots() wrote the body innerHTML into; the gallery
     * is positioned as its sibling. Returns null when no such element exists.
     */
    private function findBodyElement(\DOMDocument $doc): ?\DOMElement
    {
        $xpath = new \DOMXPath($doc);
        $node = $xpath->query('//*[@data-nf="body"]')?->item(0);
        return $node instanceof \DOMElement ? $node : null;
    }

    // ---- prev/next nav ----------------------------------------------------

    /**
     * Build a <nav class="ef-news-prevnext"> with a "previous (older)" and/or a
     * "next (newer)" link and insert it as a sibling immediately AFTER the
     * .aside-card block ("Нужна помощь с туром?"). $prev is the OLDER news,
     * $next the NEWER news (see Publisher). When neither neighbour exists, no
     * nav is emitted.
     *
     * Anchor: the first element carrying the `aside-card` class (the article
     * template's CTA card). If no .aside-card exists, fall back to appending the
     * nav at the end of <body>; if there is no <body> either, skip gracefully.
     *
     * Every href/title is written via the DOM API (setAttribute / textContent),
     * never string-concatenated, so slugs and titles are escaped by the
     * serializer — no injection surface.
     *
     * @param array<string,mixed>|null $prev
     * @param array<string,mixed>|null $next
     */
    private function insertPrevNext(\DOMDocument $doc, ?array $prev, ?array $next): void
    {
        if ($prev === null && $next === null) {
            return; // no neighbours — nothing to navigate to
        }

        $nav = $doc->createElement('nav');
        $nav->setAttribute('class', 'ef-news-prevnext');
        $nav->setAttribute('aria-label', 'Навигация по новостям');

        // PREVIOUS = older news (left side, "← Предыдущая").
        if ($prev !== null) {
            $nav->appendChild($this->prevNextLink($doc, $prev, 'prev', '← Предыдущая'));
        }
        // NEXT = newer news (right side, "Следующая →").
        if ($next !== null) {
            $nav->appendChild($this->prevNextLink($doc, $next, 'next', 'Следующая →'));
        }

        // Anchor on the .aside-card element; insert the nav right after it.
        $aside = $this->findAsideCard($doc);
        if ($aside !== null) {
            $parent = $aside->parentNode;
            if ($parent instanceof \DOMElement) {
                $next2 = $aside->nextSibling;
                if ($next2 !== null) {
                    $parent->insertBefore($nav, $next2);
                } else {
                    $parent->appendChild($nav);
                }
                return;
            }
        }

        // Fallback: no usable .aside-card anchor — append at end of <body>.
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body instanceof \DOMElement) {
            $body->appendChild($nav);
        }
        // else: no body — skip silently rather than throw.
    }

    /**
     * One prev/next <a class="ef-news-prevnext-link ef-news-prevnext-<dir>">
     * with a small direction label span and the neighbour's title span. The
     * href is the site-RELATIVE '/<slug>' (same form as a news card href), so
     * articles navigate on whatever host serves them.
     *
     * When the neighbour has a non-empty cover, the link also carries the cover
     * as a background image (inline `background-image:url('<cover>')` style) plus
     * an `ef-news-prevnext-link--bg` marker class so the site-template CSS can
     * lay a dark overlay over it and render the label/title white + readable. The
     * cover URL is already validated upstream (NewsStore / NewsPublisher); we
     * still set it via the DOM API (setAttribute) so the serializer escapes it —
     * the value can never break out of its quoted attribute. A neighbour without
     * a cover gets no background (no marker class, no style) and keeps the plain
     * readable link style.
     *
     * @param array<string,mixed> $item
     */
    private function prevNextLink(\DOMDocument $doc, array $item, string $dir, string $label): \DOMElement
    {
        $a = $doc->createElement('a');
        $cls = 'ef-news-prevnext-link ef-news-prevnext-' . $dir;

        // Cover as background (when present): add the marker class + an inline
        // background-image. setAttribute escapes the value, so even a hostile
        // cover string stays inert inside the quoted style attribute.
        $cover = $this->str($item, 'cover');
        if ($cover !== '') {
            $cls .= ' ef-news-prevnext-link--bg';
        }

        $a->setAttribute('class', $cls);
        $a->setAttribute('href', $this->relativeUrl($item));
        if ($cover !== '') {
            $a->setAttribute('style', "background-image:url('" . $cover . "')");
        }

        $labelSpan = $doc->createElement('span');
        $labelSpan->setAttribute('class', 'ef-news-prevnext-label');
        $labelSpan->textContent = $label;
        $a->appendChild($labelSpan);

        $titleSpan = $doc->createElement('span');
        $titleSpan->setAttribute('class', 'ef-news-prevnext-title');
        $titleSpan->textContent = $this->neighbourTitle($item);
        $a->appendChild($titleSpan);

        $rawDate = $this->str($item, 'date');
        if ($rawDate !== '') {
            $dateSpan = $doc->createElement('span');
            $dateSpan->setAttribute('class', 'ef-news-prevnext-date');
            $dateSpan->textContent = $this->formatDate($rawDate);
            $a->appendChild($dateSpan);
        }

        return $a;
    }

    /** Neighbour link text: prefer title_short, fall back to title. */
    private function neighbourTitle(array $item): string
    {
        $short = $this->str($item, 'title_short');
        return $short !== '' ? $short : $this->str($item, 'title');
    }

    /**
     * Locate the .aside-card element (matched by class token, robust to the
     * inline style/extra classes the template carries). Returns null when none.
     */
    private function findAsideCard(\DOMDocument $doc): ?\DOMElement
    {
        $xpath = new \DOMXPath($doc);
        $node = $xpath
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' aside-card ')]")
            ?->item(0);
        return $node instanceof \DOMElement ? $node : null;
    }

    /**
     * A fresh card .post element (cloned from the card skin, filled, stripped).
     * The element is owned by the article-skin's document — the Publisher must
     * importNode() it into each list container's document before insertion.
     *
     * @param array<string,mixed> $item
     */
    public function renderCard(array $item): \DOMElement
    {
        $this->template->validate();

        $cardSkin = $this->template->cardNode();
        $clone = $cardSkin->cloneNode(true);
        \assert($clone instanceof \DOMElement);

        $values = $this->cardValues($item);

        $this->fillSlots($clone, $values, skipCardTemplates: false);
        $this->stripMarkers($clone);

        return $clone;
    }

    // ---- value maps -------------------------------------------------------

    /**
     * @param array<string,mixed> $item
     * @return array<string,string>
     */
    private function articleValues(array $item): array
    {
        $config = $this->store->config();
        $suffix = (string) ($config['title_suffix'] ?? '');
        $title = (string) ($item['title'] ?? '');
        $titleShort = $this->str($item, 'title_short') !== '' ? $this->str($item, 'title_short') : $title;
        $cover = $this->str($item, 'cover');
        $coverOg = $this->str($item, 'cover_og') !== '' ? $this->str($item, 'cover_og') : $cover;

        return [
            'title_tag' => $title . $suffix,
            'excerpt' => $this->str($item, 'excerpt'),
            'url' => $this->absoluteUrl($item),
            'cover_og' => $coverOg,
            'title_short' => $titleShort,
            // article order: "{category} · {date}"
            'meta_line' => $this->str($item, 'category') . ' · ' . $this->formatDate($this->str($item, 'date')),
            'title' => $title,
            'cover' => $cover,
            'body' => $this->str($item, 'body_html'),
            // OPTIONAL managed slot (Fix 1): robots is keyed off the article's
            // `published` flag. A published article is INDEXABLE; an unpublished
            // one (or a missing flag treated as published) is noindex. The slot
            // is optional — NewsTemplate::validate() does NOT require it, and a
            // template without a [data-nf="robots"] meta simply has no target to
            // fill. The skin's static content="noindex, nofollow" stays the
            // protective default so the un-rendered template page stays noindex.
            'robots' => (($item['published'] ?? true) ? 'index, follow' : 'noindex, nofollow'),
            // renderer-internal (double-underscore): alt for the cover <img>.
            '__cover_alt' => $title,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,string>
     */
    private function cardValues(array $item): array
    {
        $title = (string) ($item['title'] ?? '');
        $titleShort = $this->str($item, 'title_short') !== '' ? $this->str($item, 'title_short') : $title;

        return [
            // Spec §1.2: the CARD url slot is a site-RELATIVE '/<slug>' so cards
            // stay on whatever host serves the page (universal-engine portability
            // + correct on-site navigation). Only the ARTICLE head url (§1.1) is
            // the absolute canonical — see articleValues()/regenerateJsonLd().
            'url' => $this->relativeUrl($item),
            'cover' => $this->str($item, 'cover'),
            // card order: "{date} · {category}"
            'meta_line' => $this->formatDate($this->str($item, 'date')) . ' · ' . $this->str($item, 'category'),
            'title_short' => $titleShort,
            // renderer-internal (double-underscore): alt for the cover <img>.
            '__cover_alt' => $titleShort,
        ];
    }

    // ---- slot filling -----------------------------------------------------

    /**
     * Walk $scope, write each data-nf slot to its resolved target. When
     * $skipCardTemplates is true, do not descend into [data-news-card]
     * <template> subtrees (those are removed / rendered separately).
     *
     * @param array<string,string> $values
     */
    private function fillSlots(\DOMElement $scope, array $values, bool $skipCardTemplates): void
    {
        // alt for cover <img> differs article vs card; passed via $values.
        $this->coverAlt = $values['__cover_alt'] ?? null;

        // Collect targets first (filling body via importFragment mutates the
        // tree, so we snapshot the element list before writing).
        $targets = [];
        $this->collectSlotTargets($scope, $targets, $skipCardTemplates);
        foreach ($targets as [$el, $field]) {
            if ($field === 'jsonld') {
                continue; // regenerated wholesale in regenerateJsonLd()
            }
            if (!\array_key_exists($field, $values)) {
                continue; // unknown slot field — leave as-is (validation caught required ones)
            }
            $this->writeSlot($el, $field, $values[$field]);
        }

        $this->coverAlt = null;
    }

    /**
     * @param list<array{0:\DOMElement,1:string}> $out
     */
    private function collectSlotTargets(\DOMNode $scope, array &$out, bool $skipCardTemplates): void
    {
        if ($scope instanceof \DOMElement) {
            $nf = $scope->getAttribute('data-nf');
            if ($nf !== '') {
                $out[] = [$scope, $nf];
            }
        }
        foreach ($scope->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($skipCardTemplates && $child->tagName === 'template' && $child->hasAttribute('data-news-card')) {
                continue;
            }
            $this->collectSlotTargets($child, $out, $skipCardTemplates);
        }
    }

    /**
     * Write $value into $el using the tag-resolution rule (or data-nf-attr
     * override). jsonld is handled separately (skipped in fillSlots).
     */
    private function writeSlot(\DOMElement $el, string $field, string $value): void
    {
        $override = $el->getAttribute('data-nf-attr');
        if ($override !== '') {
            $el->setAttribute($override, $value);
            return;
        }

        $tag = $el->tagName;
        switch ($tag) {
            case 'meta':
                $el->setAttribute('content', $value);
                return;
            case 'link':
                $el->setAttribute('href', $value);
                return;
            case 'a':
                $el->setAttribute('href', $value);
                return;
            case 'img':
                // Empty cover ⇒ never emit a broken <img src="">. Remove the
                // cover <img> entirely, and if its wrapper (.media in a card,
                // .article-cover in an article) is left with no element
                // children, remove the wrapper too so there's no empty box.
                if ($field === 'cover' && $value === '') {
                    $this->removeCoverImage($el);
                    return;
                }
                $el->setAttribute('src', $value);
                // article cover alt = title; card cover alt = title_short.
                if ($field === 'cover' && $this->coverAlt !== null) {
                    $el->setAttribute('alt', $this->coverAlt);
                }
                return;
            default:
                if ($field === 'body') {
                    $this->setInnerHtml($el, $value);
                    return;
                }
                $el->textContent = $value;
                return;
        }
    }

    /**
     * Remove a coverless <img> from the published output. After detaching the
     * <img>, if its parent wrapper element (e.g. the card's .media span or the
     * article's .article-cover figure) is left with NO element children, the
     * empty wrapper is removed too — no empty styled box survives. Guarded so
     * we never remove <body> or a top-level node with no parent.
     */
    private function removeCoverImage(\DOMElement $img): void
    {
        $parent = $img->parentNode;
        $img->parentNode?->removeChild($img);

        if (!$parent instanceof \DOMElement) {
            return; // detached / document-level — nothing more to prune
        }
        if (\in_array(strtolower($parent->tagName), ['body', 'html', 'head'], true)) {
            return; // never strip structural ancestors
        }
        if ($this->hasElementChild($parent)) {
            return; // wrapper still holds other elements — keep it
        }

        $grandparent = $parent->parentNode;
        if (!$grandparent instanceof \DOMElement) {
            return; // wrapper is top-level — leave it in place
        }
        $grandparent->removeChild($parent);
    }

    /** True when $el has at least one child that is itself an element node. */
    private function hasElementChild(\DOMElement $el): bool
    {
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                return true;
            }
        }
        return false;
    }

    /** Replace innerHTML of $el with parsed-and-imported $html fragment. */
    private function setInnerHtml(\DOMElement $el, string $html): void
    {
        while ($el->firstChild !== null) {
            $el->removeChild($el->firstChild);
        }
        if ($html === '') {
            return;
        }
        $doc = $el->ownerDocument;
        \assert($doc instanceof \DOMDocument);
        foreach ($this->html5->importFragment($doc, $html) as $node) {
            $el->appendChild($node);
        }
    }

    // ---- JSON-LD ----------------------------------------------------------

    /**
     * Refresh the NewsArticle JSON-LD by MERGING per-article fields onto the
     * template's own JSON-LD. Targets the [data-nf="jsonld"]
     * <script type=application/ld+json>; replaces its text content.
     *
     * Universal-engine philosophy: site constants (publisher, author,
     * inLanguage, …) live in the marked template, so they must be preserved.
     * We start from the template's existing JSON-LD object and overwrite only
     * the keys that must never drift from stored data; everything else the
     * template author put there survives untouched.
     *
     * @param array<string,mixed> $item
     */
    private function regenerateJsonLd(\DOMDocument $doc, array $item): void
    {
        $xpath = new \DOMXPath($doc);
        $script = $xpath->query('//script[@data-nf="jsonld"]')?->item(0);
        if (!$script instanceof \DOMElement) {
            return; // validate() guarantees presence; defensive no-op otherwise
        }

        // 1. START from the template's existing JSON-LD (carries the site
        //    constants: publisher, author, inLanguage, …). Fall back to a
        //    minimal NewsArticle base when the placeholder is empty/invalid
        //    (e.g. the common "{}" stub).
        $existing = json_decode($script->textContent, true);
        $jsonld = (\is_array($existing) && $existing !== [])
            ? $existing
            : ['@context' => 'https://schema.org', '@type' => 'NewsArticle'];

        $url = $this->absoluteUrl($item);
        $title = (string) ($item['title'] ?? '');
        $datePublished = $this->isoDateTime($this->str($item, 'created_at'), $this->str($item, 'date'));
        $dateModified = $this->isoDateTime($this->str($item, 'updated_at'), $this->str($item, 'date'));
        $cover = $this->str($item, 'cover_og') !== '' ? $this->str($item, 'cover_og') : $this->str($item, 'cover');

        // 2. OVERWRITE only the per-article keys (these must never drift).
        //    Keep @context/@type as NewsArticle (overwrite @type defensively).
        $jsonld['@context'] = $jsonld['@context'] ?? 'https://schema.org';
        $jsonld['@type'] = 'NewsArticle';
        $jsonld['headline'] = $title;
        $jsonld['description'] = $this->str($item, 'excerpt');
        $jsonld['articleSection'] = $this->str($item, 'category');
        $jsonld['datePublished'] = $datePublished;
        $jsonld['dateModified'] = $dateModified;
        $jsonld['mainEntityOfPage'] = [
            '@type' => 'WebPage',
            '@id' => $url,
        ];
        $jsonld['url'] = $url;

        // Set image only when a cover resolves; if empty, leave any
        // template-provided image as-is rather than injecting an empty one.
        $imageUrl = $this->absoluteImage($cover);
        if ($imageUrl !== '') {
            $jsonld['image'] = [$imageUrl];
        }

        $encoded = json_encode(
            $jsonld,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_PRETTY_PRINT,
        );
        if ($encoded === false) {
            $encoded = '{}';
        }

        // Clear existing children (the template's JSON-LD) then write fresh text.
        while ($script->firstChild !== null) {
            $script->removeChild($script->firstChild);
        }
        $script->appendChild($doc->createTextNode($encoded));
    }

    /**
     * Pick the best ISO-8601 datetime: prefer an already-ISO timestamp
     * ('YYYY-MM-DDTHH:MM:SSZ'); else promote a plain date to midnight UTC;
     * else fall back to the raw value.
     */
    private function isoDateTime(string $timestamp, string $dateFallback): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $timestamp)) {
            return $timestamp;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFallback)) {
            return $dateFallback . 'T00:00:00Z';
        }
        return $timestamp !== '' ? $timestamp : $dateFallback;
    }

    /**
     * Make a cover path absolute for JSON-LD image[] using config.base_url.
     * Already-absolute (http/https/protocol-relative) values pass through.
     */
    private function absoluteImage(string $cover): string
    {
        if ($cover === '') {
            return '';
        }
        if (preg_match('~^(?:https?:)?//~i', $cover)) {
            return $cover;
        }
        $base = trim((string) ($this->store->config()['base_url'] ?? ''));
        if ($base === '') {
            return $cover; // relative; can't absolutise without a base
        }
        return rtrim($base, '/') . '/' . ltrim($cover, '/');
    }

    // ---- marker stripping -------------------------------------------------

    private function removeCardTemplates(\DOMDocument $doc): void
    {
        $xpath = new \DOMXPath($doc);
        foreach (iterator_to_array($xpath->query('//template[@data-news-card]') ?: []) as $tpl) {
            if ($tpl instanceof \DOMElement) {
                $tpl->parentNode?->removeChild($tpl);
            }
        }
    }

    private function stripMarkers(\DOMElement $root): void
    {
        $this->stripMarkersOn($root);
        foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
            if ($el instanceof \DOMElement) {
                $this->stripMarkersOn($el);
            }
        }
    }

    private function stripMarkersOn(\DOMElement $el): void
    {
        foreach (self::MARKER_ATTRS as $attr) {
            if ($el->hasAttribute($attr)) {
                $el->removeAttribute($attr);
            }
        }
    }

    // ---- helpers ----------------------------------------------------------

    /** @param array<string,mixed> $item */
    private function str(array $item, string $key): string
    {
        $v = $item[$key] ?? '';
        return \is_string($v) ? $v : '';
    }

    /**
     * Clean absolute URL: {base}/{slug} — NO .html extension (the live
     * .htaccess 301-strips .html, so the canonical must be extension-less).
     *
     * @param array<string,mixed> $item
     */
    private function absoluteUrl(array $item): string
    {
        $config = $this->store->config();
        $base = trim((string) ($config['base_url'] ?? ''));
        $slug = $this->str($item, 'slug');
        if ($base === '') {
            return $slug; // relative fallback; controller/publisher may pass an origin via config
        }
        return rtrim($base, '/') . '/' . $slug;
    }

    /**
     * Site-RELATIVE URL: '/{slug}' — host-independent, used ONLY for the news
     * CARD url slot (spec §1.2) so cards navigate on whatever host serves the
     * page. Mirrors absoluteUrl() minus the base; the article head canonical /
     * og:url stays absolute via absoluteUrl().
     *
     * @param array<string,mixed> $item
     */
    private function relativeUrl(array $item): string
    {
        return '/' . ltrim($this->str($item, 'slug'), '/');
    }

    /** ISO 'YYYY-MM-DD' → '11 июня 2026' (ru) or the raw string on parse failure. */
    private function formatDate(string $iso): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return $iso;
        }
        $month = (int) $m[2];
        $day = (int) $m[3];
        $year = $m[1];
        $monthName = self::RU_MONTHS[$month] ?? $m[2];
        return $day . ' ' . $monthName . ' ' . $year;
    }
}
