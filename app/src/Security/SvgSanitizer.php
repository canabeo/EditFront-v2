<?php

declare(strict_types=1);

namespace EditFront\Security;

/**
 * Whitelist SVG sanitizer (§13). SVG is XML that can carry script, external
 * references and XXE — so an uploaded SVG is parsed, stripped to a curated
 * element/attribute whitelist and re-serialized. Anything outside the
 * whitelist (script, foreignObject, event handlers, external/js/data hrefs,
 * <style>, DOCTYPE/ENTITY) is removed. Returns the clean SVG or null when the
 * input is not a usable SVG at all.
 *
 * Element/attribute names are matched case-INSENSITIVELY but preserved
 * verbatim — SVG is case-sensitive (viewBox, gradientUnits, …).
 */
final class SvgSanitizer
{
    private const ALLOWED_TAGS = [
        'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline',
        'polygon', 'text', 'tspan', 'defs', 'lineargradient', 'radialgradient',
        'stop', 'clippath', 'mask', 'use', 'symbol', 'title', 'desc', 'marker',
        'pattern', 'metadata', 'switch',
    ];

    private const ALLOWED_ATTRS = [
        'id', 'class', 'fill', 'fill-opacity', 'fill-rule', 'stroke',
        'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray',
        'stroke-dashoffset', 'stroke-opacity', 'stroke-miterlimit', 'opacity',
        'd', 'points', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx',
        'ry', 'width', 'height', 'viewbox', 'transform', 'gradientunits',
        'gradienttransform', 'spreadmethod', 'offset', 'stop-color',
        'stop-opacity', 'xmlns', 'xmlns:xlink', 'version', 'preserveaspectratio',
        'clip-rule', 'clip-path', 'mask', 'text-anchor', 'font-family',
        'font-size', 'font-weight', 'font-style', 'letter-spacing', 'dy', 'dx',
        'marker-end', 'marker-start', 'marker-mid', 'markerwidth', 'markerheight',
        'refx', 'refy', 'orient', 'patternunits', 'patterncontentunits',
        'patterntransform', 'href', 'xlink:href', 'style', 'role', 'aria-label',
        'aria-hidden', 'fx', 'fy', 'maskunits', 'clippathunits',
    ];

    private const MAX_BYTES = 2 * 1024 * 1024;

    public function sanitize(string $svg): ?string
    {
        if (strlen($svg) > self::MAX_BYTES) {
            return null;
        }
        // strip DOCTYPE / ENTITY declarations up front (XXE defence) before parse
        $svg = (string) preg_replace('/<!DOCTYPE[^>]*(\[[^\]]*\])?[^>]*>/is', '', $svg);
        if (str_contains(strtolower($svg), '<!entity')) {
            return null;
        }
        if (trim($svg) === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // LIBXML_NONET blocks network; we deliberately do NOT pass LIBXML_NOENT,
        // so entity substitution stays off (XXE).
        $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded || $doc->documentElement === null) {
            return null;
        }
        if (strtolower($doc->documentElement->localName ?? '') !== 'svg') {
            return null;
        }

        $this->cleanElement($doc->documentElement);

        $out = $doc->saveXML($doc->documentElement);
        return is_string($out) && $out !== '' ? $out : null;
    }

    private function cleanElement(\DOMElement $el): void
    {
        // depth-first so we can remove children safely
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                if (!in_array(strtolower($child->localName), self::ALLOWED_TAGS, true)) {
                    $el->removeChild($child);
                    continue;
                }
                $this->cleanElement($child);
            } elseif (
                $child instanceof \DOMProcessingInstruction
                || $child instanceof \DOMComment
            ) {
                $el->removeChild($child);
            }
        }

        foreach (iterator_to_array($el->attributes ?? []) as $attr) {
            /** @var \DOMAttr $attr */
            $name = strtolower($attr->nodeName);
            if (str_starts_with($name, 'on')) {
                $el->removeAttributeNode($attr);
                continue;
            }
            if (!in_array($name, self::ALLOWED_ATTRS, true)) {
                $el->removeAttributeNode($attr);
                continue;
            }
            $value = (string) $attr->value;
            $probe = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $value));
            if ($name === 'href' || $name === 'xlink:href') {
                // only internal fragment refs survive (#gradient, #clip) — no
                // external / javascript: / data: targets
                if ($probe === '' || $probe[0] !== '#') {
                    $el->removeAttributeNode($attr);
                }
                continue;
            }
            // also reject backslash (CSS hex-escape, e.g. "expressio\6e(") and
            // @-rules — substring checks above are otherwise escapable (review M3)
            if (
                str_contains($probe, 'javascript:') || str_contains($probe, 'expression(')
                || str_contains($probe, 'url(') || str_contains($probe, '\\') || str_contains($probe, '@')
            ) {
                $el->removeAttributeNode($attr);
            }
        }
    }
}
