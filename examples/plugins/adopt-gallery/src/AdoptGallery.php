<?php

declare(strict_types=1);

namespace EditFront\Plugins\AdoptGallery;

use EditFront\Plugin\BlockKind;
use EditFront\Plugin\KindCtx;

/**
 * host-site theme — thumbnail gallery card (§6). Some templates store a
 * whole gallery as a single <div class="project-card" data-images='[...]'> whose
 * JSON attribute (not child <img> elements) is the source of truth for the
 * site's own lightbox script — so the normal element editor can never reach the
 * image set. This kind exposes that set as structured props.
 *
 * render() reproduces the EXACT theme markup (classes + data-images + one dot per
 * image) so the bundled swipe.js / project-lightbox script keep working on the
 * live page with the CMS removed (needs_runtime:false). serialize() is the exact
 * inverse, which is what lets an existing card be adopted straight from the DOM.
 */
final class AdoptGallery implements BlockKind
{
    public function kind(): string
    {
        return 'adopt-gallery';
    }

    public function version(): int
    {
        return 1;
    }

    public function render(array $props, string $innerHtml, KindCtx $ctx): string
    {
        $title = (string) ($props['title'] ?? '');

        $srcs = [];
        foreach ((array) ($props['images'] ?? []) as $im) {
            if (is_array($im) && is_string($im['src'] ?? null) && $im['src'] !== '') {
                $srcs[] = $im['src'];
            }
        }

        $cover = (string) ($props['cover'] ?? '');
        if ($cover === '' && $srcs !== []) {
            $cover = $srcs[0];
        }

        // separator-free JSON array of strings — render and serialize must agree
        // exactly; the site script does JSON.parse(getAttribute('data-images')).
        $dataImages = (string) json_encode($srcs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $dots = str_repeat('<span class="project-card__dot"></span>', count($srcs));

        $img = '<img'
            . ($cover !== '' ? ' src="' . $this->attr($cover) . '"' : '')
            . ' alt="' . $this->attr($title) . '"'
            . ' class="project-card__img">';

        return '<div class="project-card" data-images="' . $this->attr($dataImages) . '">'
            . $img
            . '<div class="project-card__title">' . $this->text($title) . '</div>'
            . '<div class="project-card__dots">' . $dots . '</div>'
            . '</div>';
    }

    public function serialize(\DOMElement $node): array
    {
        $card = $this->firstWithClass($node, 'project-card');
        if ($card === null) {
            return ['title' => '', 'cover' => '', 'images' => []];
        }

        $images = [];
        $raw = $card->getAttribute('data-images');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $src) {
                    if (is_string($src) && $src !== '') {
                        $images[] = ['src' => $src];
                    }
                }
            }
        }

        $imgEl = $this->firstWithClass($card, 'project-card__img');
        $cover = $imgEl !== null ? $imgEl->getAttribute('src') : '';

        $titleEl = $this->firstWithClass($card, 'project-card__title');
        $title = $titleEl !== null ? trim($titleEl->textContent) : '';

        return ['title' => $title, 'cover' => $cover, 'images' => $images];
    }

    public function sanitize(string $html): string
    {
        return $html; // core sanitizeStructural runs on top (§6.7)
    }

    public function validate(array $props): array
    {
        $errors = [];
        if (!is_string($props['title'] ?? null)) {
            $errors[] = 'title';
        }
        if (!is_string($props['cover'] ?? null)) {
            $errors[] = 'cover';
        }
        $images = $props['images'] ?? null;
        if (!is_array($images) || $images !== array_values($images)) {
            $errors[] = 'images';
        } else {
            foreach ($images as $im) {
                if (!is_array($im) || !is_string($im['src'] ?? null)) {
                    $errors[] = 'images';
                    break;
                }
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
