<?php

declare(strict_types=1);

namespace EditFront\News;

use EditFront\Security\SanitizerHtml;
use EditFront\Security\SanitizerUrl;
use Masterminds\HTML5;

/**
 * Article-body sanitizer (spec §5). The core SanitizerHtml STRUCTURAL pass is
 * the security boundary: it removes all dangerous content/handlers, URL-gates
 * src/href, drops <script>/<style>/<iframe>/etc., and keeps a curated attribute
 * set INCLUDING class — which the RICH pass would strip, breaking the host site's
 * body design hooks (.lead/.dots/.callout/.inline). We run that structural pass
 * and then NARROW the survivors to the news whitelist:
 *
 *   - unwrap any tag not in ALLOWED_TAGS (keep content, drop tag);
 *   - on <a> keep only href/target/rel;
 *   - on <img> keep only a re-gated src + alt + loading="lazy" + numeric
 *     width/height + class tokens from the allowlist;
 *   - on block tags keep a style attribute ONLY when it is exactly a single
 *     `text-align: left|center|right|justify` declaration (alignment);
 *   - class tokens everywhere are limited to ALLOWED_CLASSES.
 *
 * Phase A (rich body) additions: inline <img> + <u> + text-align alignment.
 *
 * SECURITY: img src is re-run through SanitizerUrl WITHOUT $allowDataImage, so
 * even a data:image/... that the core structural pass would tolerate for src is
 * REJECTED here — news body images are http(s)/relative gallery URLs only. No
 * data:/javascript:/control-char tricks survive.
 */
final class NewsBodySanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'h2', 'h3', 'ul', 'ol', 'li', 'b', 'strong', 'i', 'em', 'u', 'a', 'br', 'blockquote', 'img',
    ];
    private const ALLOWED_CLASSES = ['lead', 'dots', 'callout', 'inline', 'ef-news-img'];

    /** block tags that may carry a text-align alignment style */
    private const ALIGNABLE_TAGS = ['p', 'h2', 'h3', 'li', 'blockquote'];

    /** allowed text-align keywords */
    private const ALIGN_VALUES = ['left', 'center', 'right', 'justify'];

    private HTML5 $html5;

    public function __construct(
        private readonly SanitizerHtml $sanitizer,
        private readonly SanitizerUrl $urls,
    ) {
        $this->html5 = new HTML5(['disable_html_ns' => true]);
    }

    public function sanitize(string $html): string
    {
        $safe = $this->sanitizer->sanitizeStructural($html);
        if (trim($safe) === '') {
            return '';
        }
        $fragment = $this->html5->loadHTMLFragment($safe);
        $clean = $fragment->ownerDocument->createDocumentFragment();
        foreach (iterator_to_array($fragment->childNodes) as $child) {
            foreach ($this->narrow($child) as $node) {
                $clean->appendChild($node);
            }
        }
        $out = '';
        foreach ($clean->childNodes as $node) {
            $out .= $this->html5->saveHTML($node);
        }
        return $out;
    }

    /** @return list<\DOMNode> */
    private function narrow(\DOMNode $node): array
    {
        if ($node instanceof \DOMText) {
            return [$node->cloneNode()];
        }
        if (!$node instanceof \DOMElement) {
            return [];
        }
        $tag = strtolower($node->tagName);

        $children = [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            foreach ($this->narrow($child) as $sanitized) {
                $children[] = $sanitized;
            }
        }
        if (!in_array($tag, self::ALLOWED_TAGS, true)) {
            return $children; // unwrap: keep content, drop the tag
        }

        $el = $node->ownerDocument->createElement($tag);

        if ($tag === 'a') {
            foreach (['href', 'target', 'rel'] as $attr) {
                if ($node->hasAttribute($attr)) {
                    $el->setAttribute($attr, $node->getAttribute($attr));
                }
            }
        } elseif ($tag === 'img') {
            // src is already URL-gated by the structural pass; re-gate WITHOUT
            // data-image allowance so no data:/javascript: src survives in the
            // news body. Drop the whole <img> if it has no safe src.
            $src = $this->urls->sanitize($node->getAttribute('src'), false);
            if ($src === null || $src === '') {
                return $children; // unwrap: no element to keep without a safe src
            }
            // Extra news-only gate (scoped to this img branch, NOT SanitizerUrl):
            // reject protocol-relative URLs (`//host/path`). SanitizerUrl treats a
            // leading `//` as schemeless and lets it through, but the news body
            // contract is http(s)/relative gallery URLs only — a `//evil/x` would
            // load an attacker-controlled third-party image (tracking pixel /
            // mixed-content). Trim leading whitespace + control chars first so a
            // `\t\n//evil` obfuscation is caught too. Drop the whole <img> on hit.
            if (str_starts_with(ltrim($src, " \t\n\r\0\x0B\x0C"), '//')) {
                return $children; // unwrap: protocol-relative src is not allowed
            }
            $el->setAttribute('src', $src);
            if ($node->hasAttribute('alt')) {
                $el->setAttribute('alt', $node->getAttribute('alt'));
            }
            // loading is forced to lazy whenever present (or absent → lazy)
            $el->setAttribute('loading', 'lazy');
            foreach (['width', 'height'] as $dim) {
                if ($node->hasAttribute($dim) && preg_match('/^\d{1,5}$/', $node->getAttribute($dim)) === 1) {
                    $el->setAttribute($dim, $node->getAttribute($dim));
                }
            }
        }

        if ($node->hasAttribute('class')) {
            $kept = array_values(array_filter(
                preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [],
                static fn (string $c): bool => in_array($c, self::ALLOWED_CLASSES, true)
            ));
            if ($kept !== []) {
                $el->setAttribute('class', implode(' ', $kept));
            }
        }

        // alignment: keep a style attribute ONLY when it is exactly a single
        // text-align: <keyword> declaration; anything else is dropped.
        if (in_array($tag, self::ALIGNABLE_TAGS, true) && $node->hasAttribute('style')) {
            $align = $this->textAlignOnly($node->getAttribute('style'));
            if ($align !== null) {
                $el->setAttribute('style', 'text-align: ' . $align);
            }
        }

        foreach ($children as $child) {
            $el->appendChild($child);
        }
        return [$el];
    }

    /**
     * Parse an inline style and return the valid text-align keyword if present.
     * Other declarations (color, etc.) are simply ignored/dropped — the result
     * is a clean `text-align: <keyword>` and nothing else. Returns null when no
     * valid text-align declaration is found (style dropped entirely), or when a
     * text-align declaration carries an unknown/unsafe value (reject rather than
     * silently keep a bogus alignment).
     */
    private function textAlignOnly(string $style): ?string
    {
        $found = null;
        foreach (explode(';', $style) as $decl) {
            $decl = trim($decl);
            if ($decl === '') {
                continue;
            }
            $colon = strpos($decl, ':');
            if ($colon === false) {
                continue; // malformed fragment → ignore it, keep scanning
            }
            $prop = strtolower(trim(substr($decl, 0, $colon)));
            if ($prop !== 'text-align') {
                continue; // any non-text-align property → drop that declaration
            }
            $value = strtolower(trim(substr($decl, $colon + 1)));
            if (!in_array($value, self::ALIGN_VALUES, true)) {
                return null; // text-align present but value is unknown/unsafe
            }
            $found = $value; // last valid wins
        }
        return $found;
    }
}
