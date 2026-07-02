<?php

declare(strict_types=1);

namespace EditFront\Tests\Plugin;

use EditFront\Plugin\PluginException;
use EditFront\Plugin\PropsMutator;
use EditFront\Plugin\PropsSchema;
use PHPUnit\Framework\TestCase;

/**
 * §6.4.1 / §12 FormRendererTest (server mirror): every schema-driven mutation
 * applies and INVERTS exactly — invert(apply(S)) ≡ S — which is what makes
 * plugin undo correct (§7.6) and the enable-gate meaningful (§6.4.2).
 */
final class PropsMutatorTest extends TestCase
{
    private PropsMutator $m;
    private PropsSchema $schema;

    protected function setUp(): void
    {
        $this->m = new PropsMutator();
        $this->schema = new PropsSchema([
            'currency' => ['type' => 'enum', 'values' => ['USD', 'EUR', 'UAH'], 'default' => 'EUR'],
            'tiers' => [
                'type' => 'array', 'of' => 'object', 'max' => 3,
                'shape' => [
                    'title' => ['type' => 'string', 'max' => 60, 'default' => 'Tier'],
                    'price' => ['type' => 'number', 'min' => 0, 'max' => 1000, 'default' => 0],
                    'featured' => ['type' => 'bool', 'default' => false],
                ],
            ],
        ]);
    }

    private function base(): array
    {
        return ['currency' => 'EUR', 'tiers' => [
            ['title' => 'Basic', 'price' => 9, 'featured' => false],
            ['title' => 'Pro', 'price' => 19, 'featured' => true],
        ]];
    }

    private function assertRoundTrip(string $mode, array $before, array $forward): array
    {
        $after = $this->m->apply($mode, $before, $forward, $this->schema);
        $inv = $this->m->invert($mode, $forward, $before, $this->schema);
        $back = $this->m->apply($inv['mode'], $after, $inv['forward'], $this->schema);
        $this->assertSame(
            json_encode($before, JSON_UNESCAPED_UNICODE),
            json_encode($back, JSON_UNESCAPED_UNICODE),
            "inverse($mode) did not restore the original"
        );
        return $after;
    }

    public function test_setprop_applies_and_inverts(): void
    {
        $after = $this->assertRoundTrip('setprop', $this->base(), ['path' => 'tiers.1.price', 'value' => 29]);
        $this->assertSame(29, $after['tiers'][1]['price']);
    }

    public function test_setprop_clamps_to_schema(): void
    {
        $after = $this->m->apply('setprop', $this->base(), ['path' => 'tiers.0.price', 'value' => 99999], $this->schema);
        $this->assertSame(1000, $after['tiers'][0]['price']); // clamped to max
    }

    public function test_setprop_unknown_path_rejected(): void
    {
        $this->expectException(PluginException::class);
        $this->m->apply('setprop', $this->base(), ['path' => 'nope', 'value' => 1], $this->schema);
    }

    public function test_arrayinsert_applies_and_inverts(): void
    {
        $after = $this->assertRoundTrip('arrayinsert', $this->base(), [
            'path' => 'tiers', 'index' => 1, 'item' => ['title' => 'Team', 'price' => 49, 'featured' => false],
        ]);
        $this->assertCount(3, $after['tiers']);
        $this->assertSame('Team', $after['tiers'][1]['title']);
    }

    public function test_arrayinsert_respects_max(): void
    {
        $full = ['currency' => 'EUR', 'tiers' => [
            ['title' => 'a', 'price' => 1, 'featured' => false],
            ['title' => 'b', 'price' => 1, 'featured' => false],
            ['title' => 'c', 'price' => 1, 'featured' => false],
        ]];
        $this->expectException(PluginException::class);
        $this->m->apply('arrayinsert', $full, ['path' => 'tiers', 'index' => 0, 'item' => ['title' => 'd', 'price' => 1, 'featured' => false]], $this->schema);
    }

    public function test_arrayremove_applies_and_inverts(): void
    {
        $after = $this->assertRoundTrip('arrayremove', $this->base(), ['path' => 'tiers', 'index' => 0]);
        $this->assertCount(1, $after['tiers']);
        $this->assertSame('Pro', $after['tiers'][0]['title']);
    }

    public function test_arrayremove_out_of_range_rejected(): void
    {
        $this->expectException(PluginException::class);
        $this->m->apply('arrayremove', $this->base(), ['path' => 'tiers', 'index' => 9], $this->schema);
    }

    public function test_arraymove_applies_and_inverts(): void
    {
        $after = $this->assertRoundTrip('arraymove', $this->base(), ['path' => 'tiers', 'from' => 0, 'to' => 1]);
        $this->assertSame('Pro', $after['tiers'][0]['title']);
        $this->assertSame('Basic', $after['tiers'][1]['title']);
    }

    public function test_arrayinsert_default_item_when_omitted(): void
    {
        $after = $this->m->apply('arrayinsert', $this->base(), ['path' => 'tiers', 'index' => 2], $this->schema);
        $this->assertSame(['title' => 'Tier', 'price' => 0, 'featured' => false], $after['tiers'][2]);
    }
}
