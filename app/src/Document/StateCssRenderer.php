<?php

declare(strict_types=1);

namespace EditFront\Document;

use EditFront\Security\SanitizerCss;

/**
 * Turns per-element state styles (data-cms-hover declaration strings) into a
 * managed <style id="ef-state-styles"> block in <head>, scoped by data-cms-id:
 *
 *   [data-cms-id="cms-abc..."]:hover { color: red; background-color: #eee }
 *
 * Run on every save (after ops apply, before serialize). Idempotent: it strips
 * the previous managed block and rebuilds from the current DOM, so a removed
 * hover style disappears. Every declaration is re-validated through SanitizerCss
 * — the attribute is never trusted to be clean. Because the block lives in the
 * saved HTML, hover works on the live static page with no CMS (drop-in §3.3).
 */
final class StateCssRenderer
{
    private const STYLE_ID = 'ef-state-styles';
    private const ATTR = 'data-cms-hover';
    private const ID_ATTR = 'data-cms-id';
    private const ID_RE = '/^cms-[0-9a-f]{12}$/';

    public function __construct(private readonly SanitizerCss $css)
    {
    }

    public function apply(\DOMDocument $doc): void
    {
        // strip the previous managed block (idempotent rebuild)
        $xpath = new \DOMXPath($doc);
        foreach (iterator_to_array($xpath->query('//style[@id="' . self::STYLE_ID . '"]') ?: []) as $old) {
            $old->parentNode?->removeChild($old);
        }

        $rules = [];
        foreach ($xpath->query('//*[@' . self::ATTR . ']') ?: [] as $el) {
            if (!$el instanceof \DOMElement) {
                continue;
            }
            $id = $el->getAttribute(self::ID_ATTR);
            if (preg_match(self::ID_RE, $id) !== 1) {
                continue; // a state style is unrenderable without an addressable id
            }
            $decls = $this->cleanDecls($el->getAttribute(self::ATTR));
            if ($decls === '') {
                // nothing survived sanitization → drop the (possibly dirty) attribute
                $el->removeAttribute(self::ATTR);
                continue;
            }
            // rewrite the attribute to its sanitized form so the saved file never
            // keeps a rejected declaration even on the inert data attribute
            $el->setAttribute(self::ATTR, $decls);
            $rules[] = '[data-cms-id="' . $id . '"]:hover { ' . $decls . ' }';
        }

        if ($rules === []) {
            return; // nothing to render → no empty block
        }

        $head = $doc->getElementsByTagName('head')->item(0);
        if (!$head instanceof \DOMElement) {
            $html = $doc->getElementsByTagName('html')->item(0);
            if (!$html instanceof \DOMElement) {
                return;
            }
            $head = $doc->createElement('head');
            $html->insertBefore($head, $html->firstChild);
        }

        $style = $doc->createElement('style');
        $style->setAttribute('id', self::STYLE_ID);
        $style->textContent = implode("\n", $rules);
        $head->appendChild($style);
    }

    /** Re-validate each declaration; drop anything the CSS sanitizer rejects. */
    private function cleanDecls(string $raw): string
    {
        $kept = [];
        foreach (explode(';', $raw) as $decl) {
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
