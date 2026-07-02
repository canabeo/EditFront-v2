<?php

declare(strict_types=1);

namespace EditFront\Plugins\AdoptBeforeAfter;

use EditFront\Plugin\BlockKind;
use EditFront\Plugin\KindCtx;

/**
 * host-site theme — "before / after" pair (§6). A <div
 * class="project-showcase__ba-grid"> with two <div class="project-showcase__ba">
 * (image + caption) that the theme's lightbox script keys off. The two images
 * are real <img> the core could already touch, but as one managed block they
 * gain paired editing, image-picker pickers and a guaranteed exact markup on
 * every re-render (needs_runtime:false — the bundled lightbox keeps working).
 *
 * render() reproduces the exact theme markup; serialize() is the exact inverse,
 * which is what lets an existing pair be adopted straight from the DOM.
 */
final class AdoptBeforeAfter implements BlockKind
{
    public function kind(): string
    {
        return 'adopt-before-after';
    }

    public function version(): int
    {
        return 1;
    }

    public function render(array $props, string $innerHtml, KindCtx $ctx): string
    {
        return '<div class="project-showcase__ba-grid">'
            . $this->item(
                (string) ($props['beforeSrc'] ?? ''),
                (string) ($props['beforeAlt'] ?? ''),
                (string) ($props['beforeLabel'] ?? '')
            )
            . $this->item(
                (string) ($props['afterSrc'] ?? ''),
                (string) ($props['afterAlt'] ?? ''),
                (string) ($props['afterLabel'] ?? '')
            )
            . '</div>';
    }

    private function item(string $src, string $alt, string $label): string
    {
        return '<div class="project-showcase__ba">'
            . '<img' . ($src !== '' ? ' src="' . $this->attr($src) . '"' : '') . ' alt="' . $this->attr($alt) . '">'
            . '<span class="project-showcase__ba-label">' . $this->text($label) . '</span>'
            . '</div>';
    }

    public function serialize(\DOMElement $node): array
    {
        $defaults = [
            'beforeSrc' => '', 'beforeAlt' => '', 'beforeLabel' => '',
            'afterSrc' => '', 'afterAlt' => '', 'afterLabel' => '',
        ];
        $grid = $this->firstWithClass($node, 'project-showcase__ba-grid');
        if ($grid === null) {
            return $defaults;
        }

        $pairs = [];
        foreach ($grid->childNodes as $child) {
            if ($child instanceof \DOMElement && $this->hasClass($child, 'project-showcase__ba')) {
                $pairs[] = $child;
            }
        }

        $read = function (?\DOMElement $ba): array {
            if ($ba === null) {
                return ['', ''];
            }
            $img = $ba->getElementsByTagName('img')->item(0);
            $src = $img instanceof \DOMElement ? $img->getAttribute('src') : '';
            $alt = $img instanceof \DOMElement ? $img->getAttribute('alt') : '';
            return [$src, $alt];
        };
        $label = function (?\DOMElement $ba): string {
            if ($ba === null) {
                return '';
            }
            $lab = $this->firstWithClass($ba, 'project-showcase__ba-label');
            return $lab !== null ? trim($lab->textContent) : '';
        };

        $before = $pairs[0] ?? null;
        $after = $pairs[1] ?? null;
        [$bs, $balt] = $read($before);
        [$as, $aalt] = $read($after);

        return [
            'beforeSrc' => $bs, 'beforeAlt' => $balt, 'beforeLabel' => $label($before),
            'afterSrc' => $as, 'afterAlt' => $aalt, 'afterLabel' => $label($after),
        ];
    }

    public function sanitize(string $html): string
    {
        return $html; // core sanitizeStructural runs on top (§6.7)
    }

    public function validate(array $props): array
    {
        $errors = [];
        foreach (['beforeSrc', 'beforeAlt', 'beforeLabel', 'afterSrc', 'afterAlt', 'afterLabel'] as $k) {
            if (!is_string($props[$k] ?? null)) {
                $errors[] = $k;
            }
        }
        return $errors;
    }

    public function migrate(array $props, int $fromV, int $toV): array
    {
        return $props; // v1 only
    }

    private function firstWithClass(\DOMElement $node, string $class): ?\DOMElement
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $this->hasClass($child, $class)) {
                return $child;
            }
        }
        return null;
    }

    private function hasClass(\DOMElement $el, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [], true);
    }

    private function attr(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }

    private function text(string $v): string
    {
        return htmlspecialchars($v, ENT_NOQUOTES, 'UTF-8');
    }
}
