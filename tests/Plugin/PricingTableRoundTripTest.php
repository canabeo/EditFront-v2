<?php

declare(strict_types=1);

namespace EditFront\Tests\Plugin;

use EditFront\Plugin\KindCtxFactory;
use EditFront\Plugin\RegisteredKind;
use PHPUnit\Framework\TestCase;

/**
 * §6.3 render↔serialize exact-inverse — regression for the thousands-separator
 * bug: a fractional price ≥ 1000 must survive render → serialize unchanged
 * (a space separator in the attribute used to make is_numeric() reset it to 0).
 */
final class PricingTableRoundTripTest extends TestCase
{
    private \EditFront\Plugin\BlockKind $kind;
    private \EditFront\Plugin\KindCtx $ctx;

    protected function setUp(): void
    {
        require_once ef2_pricing_plugin_dir() . '/src/PricingTable.php';
        $class = 'EditFront\\Plugins\\PricingTable\\PricingTable';
        $this->kind = new $class();
        $rk = new RegisteredKind('pricing-table', 'core', ['kind' => 'pricing-table', 'props_schema' => []], $this->kind);
        $this->ctx = (new KindCtxFactory(ef2_test_config()))->make($rk);
    }

    private function roundTrip(array $props): array
    {
        $html = '<div data-cms-block="pricing-table">' . $this->kind->render($props, '', $this->ctx) . '</div>';
        $doc = ef2_doc($html);
        $node = $doc->getElementsByTagName('body')->item(0)->firstElementChild;
        return $this->kind->serialize($node);
    }

    public function test_fractional_price_over_1000_round_trips(): void
    {
        $props = ['currency' => 'USD', 'period' => 'yr', 'tiers' => [
            ['title' => 'Enterprise', 'price' => 1234.5, 'featured' => true],
        ]];
        $out = $this->roundTrip($props);
        $this->assertSame(1234.5, $out['tiers'][0]['price']);
        $this->assertSame('USD', $out['currency']);
        $this->assertSame('yr', $out['period']);
        $this->assertTrue($out['tiers'][0]['featured']);
    }

    public function test_integer_and_small_fractional_prices_round_trip(): void
    {
        $props = ['currency' => 'EUR', 'period' => 'mo', 'tiers' => [
            ['title' => 'A', 'price' => 9, 'featured' => false],
            ['title' => 'B', 'price' => 19.99, 'featured' => false],
            ['title' => 'C', 'price' => 999999, 'featured' => false],
        ]];
        $out = $this->roundTrip($props);
        $this->assertSame(9, $out['tiers'][0]['price']);
        $this->assertSame(19.99, $out['tiers'][1]['price']);
        $this->assertSame(999999, $out['tiers'][2]['price']);
    }
}
