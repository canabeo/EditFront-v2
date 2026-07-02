<?php

declare(strict_types=1);

namespace EditFront\Plugin;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\Security\SanitizerHtml;

/**
 * Turns props into the inner HTML of a plugin block and builds/updates the
 * wrapping <div data-cms-block>. Core sanitize ALWAYS runs on top of the
 * kind's render() (§6.7) — a buggy plugin can degrade its node but never punch
 * through the whitelist.
 */
final class KindRenderer
{
    public function __construct(
        private readonly SanitizerHtml $sanitizer,
        private readonly Html5 $html5,
        private readonly KindCtxFactory $ctxFactory,
    ) {
    }

    /** @param array<string, mixed> $props */
    public function renderInner(RegisteredKind $rk, array $props, string $currentInner = '', string $page = ''): string
    {
        $ctx = $this->ctxFactory->make($rk, $page);
        $html = $rk->block()->render($props, $currentInner, $ctx);
        $html = $rk->block()->sanitize($html);
        return $this->sanitizer->sanitizeStructural($html);
    }

    public function buildBlock(\DOMDocument $doc, RegisteredKind $rk, string $newId, string $innerHtml): \DOMElement
    {
        $div = $doc->createElement('div');
        $div->setAttribute('data-cms-block', $rk->kind());
        $div->setAttribute(Annotator::ID_ATTR, $newId);
        $div->setAttribute('data-cms-v', (string) $rk->version());
        // a kind with layout:"contents" gets a transparent wrapper so adopting a
        // structural themed element (a CSS grid item, a direct-child-styled node)
        // never shifts the page — the wrapper is the CMS marker, the inner content
        // keeps participating in the parent's layout (§6 adopt). The inline style
        // survives the live page (CMS removed) and node.restore sanitize.
        if (($rk->manifestKind()['layout'] ?? '') === 'contents') {
            $div->setAttribute('class', 'cms-block-contents');
            $div->setAttribute('style', 'display:contents');
        }
        $this->fill($doc, $div, $innerHtml);
        return $div;
    }

    public function setInner(\DOMDocument $doc, \DOMElement $node, string $innerHtml): void
    {
        while ($node->firstChild !== null) {
            $node->removeChild($node->firstChild);
        }
        $this->fill($doc, $node, $innerHtml);
    }

    private function fill(\DOMDocument $doc, \DOMElement $node, string $innerHtml): void
    {
        foreach ($this->html5->importFragment($doc, $innerHtml) as $child) {
            $node->appendChild($child);
        }
    }
}
