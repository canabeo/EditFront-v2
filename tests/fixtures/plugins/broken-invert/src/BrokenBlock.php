<?php

declare(strict_types=1);

namespace EditFront\Plugins\BrokenInvert;

use EditFront\Plugin\BlockKind;
use EditFront\Plugin\KindCtx;

/**
 * Test fixture (§6.4.2): a kind whose serialize() DROPS `count`, so
 * serialize(render(p)) != p when count != 0. The fixtures-round-trip gate must
 * catch this and refuse to enable the plugin (read-only degrade), without
 * crashing boot. Never shipped — lives under tests/.
 */
final class BrokenBlock implements BlockKind
{
    public function kind(): string
    {
        return 'broken-block';
    }

    public function version(): int
    {
        return 1;
    }

    public function render(array $props, string $innerHtml, KindCtx $ctx): string
    {
        $label = htmlspecialchars((string) ($props['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $count = (int) ($props['count'] ?? 0);
        return '<div class="bb" data-bb-label="' . $label . '" data-bb-count="' . $count . '">'
            . $label . ' x' . $count . '</div>';
    }

    public function serialize(\DOMElement $node): array
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->getAttribute('class') === 'bb') {
                // BUG ON PURPOSE: count is dropped → round-trip is not stable
                return ['label' => $child->getAttribute('data-bb-label'), 'count' => 0];
            }
        }
        return ['label' => '', 'count' => 0];
    }

    public function sanitize(string $html): string
    {
        return $html;
    }

    public function validate(array $props): array
    {
        return [];
    }

    public function migrate(array $props, int $fromV, int $toV): array
    {
        return $props;
    }
}
