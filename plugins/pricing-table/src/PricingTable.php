<?php

declare(strict_types=1);

namespace EditFront\Plugins\PricingTable;

use EditFront\Plugin\BlockKind;
use EditFront\Plugin\KindCtx;

/**
 * Reference plugin (§6.8.1). No runtime_js — the rendered table is fully static
 * (inline styles), so it works on the live page with the CMS removed and on any
 * trust level. Props are mirrored into data-pt-* attributes so serialize() is an
 * exact inverse of render() (§6.3) — that is what lets an imported/orphan block
 * be recovered straight from the DOM (§6.5.1).
 */
final class PricingTable implements BlockKind
{
    private const CURRENCY = ['USD' => '$', 'EUR' => '€', 'UAH' => '₴'];
    private const PERIOD = ['mo' => '/mo', 'yr' => '/yr'];

    public function kind(): string
    {
        return 'pricing-table';
    }

    public function version(): int
    {
        return 1;
    }

    public function render(array $props, string $innerHtml, KindCtx $ctx): string
    {
        $currency = (string) ($props['currency'] ?? 'EUR');
        $period = (string) ($props['period'] ?? 'mo');
        $symbol = self::CURRENCY[$currency] ?? '€';
        $per = self::PERIOD[$period] ?? '/mo';

        $cards = '';
        foreach ((array) ($props['tiers'] ?? []) as $tier) {
            $title = (string) ($tier['title'] ?? '');
            $price = $tier['price'] ?? 0;
            $featured = !empty($tier['featured']);
            // separator-FREE numeric string: render and serialize must agree
            // exactly (§6.3). A thousands separator here would make is_numeric()
            // fail on re-read and silently round-trip the price to 0.
            $priceStr = is_float($price) && $price != (int) $price
                ? number_format((float) $price, 2, '.', '')
                : (string) (int) $price;

            $cardStyle = 'flex:1 1 180px;min-width:160px;padding:20px;border-radius:12px;text-align:center;'
                . ($featured
                    ? 'border:2px solid #2563eb;box-shadow:0 6px 24px rgba(37,99,235,0.15)'
                    : 'border:1px solid #e5e7eb');

            $cards .= '<div class="ef-pt-tier"'
                . ' data-pt-title="' . $this->attr($title) . '"'
                . ' data-pt-price="' . $this->attr($priceStr) . '"'
                . ' data-pt-featured="' . ($featured ? '1' : '0') . '"'
                . ' style="' . $cardStyle . '">'
                . '<h3 class="ef-pt-title" style="margin:0 0 8px;font-size:1.1em">' . $this->text($title) . '</h3>'
                . '<div class="ef-pt-price" style="font-size:1.8em;font-weight:700">'
                . $this->text($symbol) . $this->text($priceStr)
                . '<span class="ef-pt-per" style="font-size:0.5em;font-weight:400;color:#6b7280">' . $this->text($per) . '</span>'
                . '</div>'
                . '</div>';
        }

        return '<div class="ef-pt"'
            . ' data-pt-currency="' . $this->attr($currency) . '"'
            . ' data-pt-period="' . $this->attr($period) . '"'
            . ' style="display:flex;gap:16px;flex-wrap:wrap;align-items:stretch">'
            . $cards
            . '</div>';
    }

    public function serialize(\DOMElement $node): array
    {
        $container = $this->firstWithClass($node, 'ef-pt');
        if ($container === null) {
            return ['currency' => 'EUR', 'period' => 'mo', 'tiers' => []];
        }
        $currency = $container->getAttribute('data-pt-currency') ?: 'EUR';
        $period = $container->getAttribute('data-pt-period') ?: 'mo';

        $tiers = [];
        foreach ($container->childNodes as $child) {
            if (!$child instanceof \DOMElement || !$this->hasClass($child, 'ef-pt-tier')) {
                continue;
            }
            // tolerate any stray spaces (e.g. legacy/imported markup) before parsing
            $priceRaw = str_replace(' ', '', $child->getAttribute('data-pt-price'));
            $price = is_numeric($priceRaw) ? $priceRaw + 0 : 0;
            $tiers[] = [
                'title' => $child->getAttribute('data-pt-title'),
                'price' => ($price == (int) $price) ? (int) $price : (float) $price,
                'featured' => $child->getAttribute('data-pt-featured') === '1',
            ];
        }

        return ['currency' => $currency, 'period' => $period, 'tiers' => $tiers];
    }

    public function sanitize(string $html): string
    {
        return $html; // core sanitizeStructural runs on top (§6.7)
    }

    public function validate(array $props): array
    {
        $errors = [];
        if (!in_array($props['currency'] ?? null, ['USD', 'EUR', 'UAH'], true)) {
            $errors[] = 'currency';
        }
        if (!in_array($props['period'] ?? null, ['mo', 'yr'], true)) {
            $errors[] = 'period';
        }
        $tiers = $props['tiers'] ?? null;
        if (!is_array($tiers) || $tiers !== array_values($tiers) || count($tiers) > 6) {
            $errors[] = 'tiers';
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
