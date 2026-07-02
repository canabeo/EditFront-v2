<?php

declare(strict_types=1);

namespace EditFront\Document;

use EditFront\Font\FontService;

/**
 * Injects the CMS-managed @font-face block into <head>:
 *
 *   <style id="ef-fonts">@font-face { font-family: 'Brand'; src: url('/fonts/..') ... }</style>
 *
 * Run on every save (after ops apply, before serialize) and on every preview
 * render. Idempotent: strips the previous managed block and rebuilds from the
 * font set, so a deleted font disappears from pages on their next save.
 *
 * EVERY available font (built-in presets + uploaded) is emitted — not just the
 * ones a fragile scan thinks are used — so a font referenced through the editor,
 * the page's own stylesheet, or the `font:` shorthand always has its @font-face.
 * Uploaded fonts ({site}/fonts/) work with no CMS (drop-in §3.3); presets ship in
 * the CMS bundle and gracefully fall back to a system font if the CMS is removed.
 */
final class FontFaceRenderer
{
    private const STYLE_ID = 'ef-fonts';

    public function __construct(private readonly FontService $fonts)
    {
    }

    public function apply(\DOMDocument $doc): void
    {
        $xpath = new \DOMXPath($doc);
        foreach (iterator_to_array($xpath->query('//style[@id="' . self::STYLE_ID . '"]') ?: []) as $old) {
            $old->parentNode?->removeChild($old);
        }

        $rules = $this->fonts->fontFaceRules();
        if ($rules === []) {
            return; // no fonts at all → no managed block
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
        // first in <head> so the user's own stylesheets can still override usage
        $head->insertBefore($style, $head->firstChild);
    }
}
