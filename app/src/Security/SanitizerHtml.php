<?php

declare(strict_types=1);

namespace EditFront\Security;

use Masterminds\HTML5;

/**
 * HTML whitelist sanitizer (§13), two modes:
 *
 * - RICH (text.set): inline rich-text — allowed tags keep going, unknown tags
 *   are unwrapped, the only surviving attributes are sanitized href/target/rel
 *   on <a>.
 * - STRUCTURAL (node.restore — undo-restore of previously existing page
 *   content): extended tag set + a curated attribute whitelist incl. class,
 *   style (per-declaration through SanitizerCss), src/href (URL-gated) and
 *   data-cms-id (so restored nodes stay addressable by later ops).
 *
 * Both modes drop dangerous subtrees entirely and never let on*-handlers,
 * javascript: URLs or <script>/<style>/<iframe> through.
 */
final class SanitizerHtml
{
    private const ALLOWED_RICH = [
        'b', 'strong', 'i', 'em', 'u', 'a', 'br',
        'ul', 'ol', 'li', 'blockquote', 'code', 'p',
        'h2', 'h3', 'h4', 'h5', 'h6',
        // presentational containers real sites put INSIDE editable text —
        // badges, dots, icon holders. Dropping them silently deleted the
        // author's markup whenever someone corrected a typo.
        'span',
    ];

    private const ALLOWED_STRUCTURAL_EXTRA = [
        'div', 'section', 'article', 'aside', 'header', 'footer', 'span',
        'img', 'figure', 'figcaption', 'picture',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption',
        'h1', 'hr', 'small', 'sup', 'sub', 'address', 'time', 'mark', 'dl', 'dt', 'dd',
    ];

    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math',
        'template', 'noscript', 'head', 'title', 'meta', 'link', 'base', 'frame', 'frameset',
    ];

    /** structural-mode simple attributes copied verbatim */
    private const STRUCTURAL_PLAIN_ATTRS = [
        'class', 'alt', 'title', 'width', 'height', 'id', 'role',
        'colspan', 'rowspan', 'datetime', 'loading', 'decoding',
    ];

    private HTML5 $html5;

    public function __construct(
        private readonly SanitizerUrl $urls,
        private readonly SanitizerCss $css,
    ) {
        $this->html5 = new HTML5(['disable_html_ns' => true]);
    }

    public function sanitize(string $html): string
    {
        return $this->run($html, false);
    }

    public function sanitizeStructural(string $html): string
    {
        return $this->run($html, true);
    }

    private function run(string $html, bool $structural): string
    {
        if (trim($html) === '') {
            return '';
        }
        $fragment = $this->html5->loadHTMLFragment($html);
        $doc = $fragment->ownerDocument;
        $clean = $doc->createDocumentFragment();
        foreach (iterator_to_array($fragment->childNodes) as $child) {
            foreach ($this->sanitizeNode($child, $structural) as $node) {
                $clean->appendChild($node);
            }
        }
        $out = '';
        foreach ($clean->childNodes as $node) {
            $out .= $this->html5->saveHTML($node);
        }
        return $out;
    }

    /** @return list<\DOMNode> sanitized replacement nodes */
    private function sanitizeNode(\DOMNode $node, bool $structural): array
    {
        if ($node instanceof \DOMText) {
            return [$node->cloneNode()];
        }
        if (!$node instanceof \DOMElement) {
            return []; // comments, CDATA, PIs — dropped
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
            return [];
        }

        $children = [];
        foreach (iterator_to_array($node->childNodes) as $child) {
            foreach ($this->sanitizeNode($child, $structural) as $sanitized) {
                $children[] = $sanitized;
            }
        }

        $allowed = in_array($tag, self::ALLOWED_RICH, true)
            || ($structural && in_array($tag, self::ALLOWED_STRUCTURAL_EXTRA, true));
        if (!$allowed) {
            return $children; // unwrap: keep content, drop the tag
        }

        $doc = $node->ownerDocument;
        $el = $doc->createElement($tag);

        // link attributes survive in both modes
        if ($tag === 'a') {
            $href = $this->urls->sanitize($node->getAttribute('href'));
            if ($href !== null && $href !== '') {
                $el->setAttribute('href', $href);
            }
            if ($node->getAttribute('target') === '_blank') {
                $el->setAttribute('target', '_blank');
                $el->setAttribute('rel', 'noopener noreferrer');
            }
        }

        if ($structural) {
            $this->copyStructuralAttrs($node, $el);
        } else {
            // valid persistent id survives rich mode too — undo of text.set must
            // not strip ids from nested elements (they stay addressable by ops)
            $cmsId = $node->getAttribute('data-cms-id');
            if ($cmsId !== '' && preg_match('/^cms-[0-9a-f]{12}$/', $cmsId) === 1) {
                $el->setAttribute('data-cms-id', $cmsId);
            }
            // On <span> and <i> the class is not styling ON the text, it IS the
            // thing: the dot in a badge, the glyph of an icon font. Dropping it
            // turned "fix a typo" into "delete the decoration". Formatting tags
            // (b, p, h2 …) keep the stricter policy — their class is decoration
            // about text that survives without it, and letting it through would
            // also carry paste junk in from word processors.
            if ($tag === 'span' || $tag === 'i') {
                $class = trim($node->getAttribute('class'));
                if ($class !== '' && preg_match('/^[A-Za-z0-9 _-]{1,200}$/', $class) === 1) {
                    $el->setAttribute('class', $class);
                }
            }
            // A <span> with no class carries nothing — it is what the browser
            // emits for styleWithCSS bold and what word processors paste. Keep
            // unwrapping those, so the only spans that survive are the ones the
            // author put there to draw something.
            if ($tag === 'span' && !$el->hasAttribute('class')) {
                return $children;
            }
        }

        foreach ($children as $child) {
            $el->appendChild($child);
        }
        return [$el];
    }

    private function copyStructuralAttrs(\DOMElement $from, \DOMElement $to): void
    {
        foreach ($from->attributes as $attr) {
            $name = strtolower($attr->name);
            $value = (string) $attr->value;

            if (str_starts_with($name, 'on')) {
                continue;
            }
            if (in_array($name, self::STRUCTURAL_PLAIN_ATTRS, true)) {
                $to->setAttribute($name, $value);
                continue;
            }
            if (str_starts_with($name, 'aria-') && preg_match('/^aria-[a-z-]{1,30}$/', $name) === 1) {
                $to->setAttribute($name, $value);
                continue;
            }
            // persistent id survives so later ops can still address the node;
            // other data-cms-* are reserved, generic data-* pass through
            if ($name === 'data-cms-id') {
                if (preg_match('/^cms-[0-9a-f]{12}$/', $value) === 1) {
                    $to->setAttribute($name, $value);
                }
                continue;
            }
            if (str_starts_with($name, 'data-cms-')) {
                continue;
            }
            if (str_starts_with($name, 'data-') && preg_match('/^data-[a-z0-9-]{1,40}$/', $name) === 1) {
                $to->setAttribute($name, $value);
                continue;
            }
            if ($name === 'src') {
                $src = $this->urls->sanitize($value, true);
                if ($src !== null) {
                    $to->setAttribute('src', $src);
                }
                continue;
            }
            if ($name === 'style') {
                $cleanStyle = $this->sanitizeStyleAttr($value);
                if ($cleanStyle !== '') {
                    $to->setAttribute('style', $cleanStyle);
                }
                continue;
            }
        }
    }

    /** keep only declarations whose prop and value pass SanitizerCss */
    private function sanitizeStyleAttr(string $style): string
    {
        $kept = [];
        foreach (explode(';', $style) as $decl) {
            $colon = strpos($decl, ':');
            if ($colon === false) {
                continue;
            }
            $prop = strtolower(trim(substr($decl, 0, $colon)));
            $value = trim(substr($decl, $colon + 1));
            if (!$this->css->validProp($prop)) {
                continue;
            }
            $clean = $this->css->value($value);
            if ($clean !== null && $clean !== '') {
                $kept[] = $prop . ': ' . $clean;
            }
        }
        return implode('; ', $kept);
    }
}
