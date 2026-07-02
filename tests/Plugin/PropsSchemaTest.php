<?php

declare(strict_types=1);

namespace EditFront\Tests\Plugin;

use EditFront\Plugin\PluginException;
use EditFront\Plugin\PropsSchema;
use PHPUnit\Framework\TestCase;

/** §6.4.1 — the schema engine the core form, validator and mirror all read. */
final class PropsSchemaTest extends TestCase
{
    private function schema(): PropsSchema
    {
        return new PropsSchema([
            'currency' => ['type' => 'enum', 'values' => ['USD', 'EUR', 'UAH'], 'default' => 'EUR'],
            'period' => ['type' => 'enum', 'values' => ['mo', 'yr'], 'default' => 'mo'],
            'tiers' => [
                'type' => 'array', 'of' => 'object', 'max' => 6,
                'shape' => [
                    'title' => ['type' => 'string', 'max' => 60, 'default' => 'Tier'],
                    'price' => ['type' => 'number', 'min' => 0, 'max' => 1000000, 'default' => 0],
                    'featured' => ['type' => 'bool', 'default' => false],
                ],
            ],
        ]);
    }

    public function test_defaults_built_from_schema(): void
    {
        $this->assertSame(
            ['currency' => 'EUR', 'period' => 'mo', 'tiers' => []],
            $this->schema()->defaults()
        );
    }

    public function test_validate_accepts_valid_props(): void
    {
        $props = ['currency' => 'USD', 'period' => 'yr', 'tiers' => [
            ['title' => 'Basic', 'price' => 9, 'featured' => false],
        ]];
        $this->assertSame([], $this->schema()->validate($props));
    }

    public function test_validate_rejects_unknown_field_and_bad_enum(): void
    {
        $errors = $this->schema()->validate(['currency' => 'BTC', 'bogus' => 1, 'period' => 'mo', 'tiers' => []]);
        $this->assertNotSame([], $errors);
        $joined = implode('|', $errors);
        $this->assertStringContainsString('not in enum', $joined);
        $this->assertStringContainsString('unknown field', $joined);
    }

    public function test_validate_rejects_array_over_max_and_bad_item(): void
    {
        $tiers = array_fill(0, 7, ['title' => 'x', 'price' => 1, 'featured' => false]);
        $this->assertNotSame([], $this->schema()->validate(['currency' => 'EUR', 'period' => 'mo', 'tiers' => $tiers]));

        $bad = ['currency' => 'EUR', 'period' => 'mo', 'tiers' => [['title' => 'x', 'price' => 'nine', 'featured' => false]]];
        $this->assertNotSame([], $this->schema()->validate($bad));
    }

    public function test_field_spec_at_dotted_path(): void
    {
        $s = $this->schema();
        $this->assertSame('number', $s->fieldSpecAt('tiers.0.price')['type'] ?? null);
        $this->assertSame('enum', $s->fieldSpecAt('currency')['type'] ?? null);
        $this->assertNull($s->fieldSpecAt('bogus'));
        $this->assertNull($s->fieldSpecAt('tiers.0.bogus'));
        $this->assertNull($s->fieldSpecAt('currency.x')); // cannot descend a scalar
    }

    public function test_coerce_clamps_number_and_truncates_string(): void
    {
        $s = $this->schema();
        $this->assertSame(1000000, $s->coerce($s->fieldSpecAt('tiers.0.price'), 9_999_999));
        $this->assertSame(0, $s->coerce($s->fieldSpecAt('tiers.0.price'), -5));
        $long = str_repeat('a', 100);
        $this->assertSame(60, strlen($s->coerce($s->fieldSpecAt('tiers.0.title'), $long)));
        $this->assertTrue($s->coerce($s->fieldSpecAt('tiers.0.featured'), 1));
    }

    public function test_coerce_rejects_bad_enum(): void
    {
        $this->expectException(PluginException::class);
        $s = $this->schema();
        $s->coerce($s->fieldSpecAt('currency'), 'BTC');
    }

    public function test_get_by_path(): void
    {
        $props = ['currency' => 'EUR', 'period' => 'mo', 'tiers' => [['title' => 'A', 'price' => 9, 'featured' => true]]];
        $this->assertSame(9, $this->schema()->getByPath($props, 'tiers.0.price'));
        $this->assertTrue($this->schema()->getByPath($props, 'tiers.0.featured'));
        $this->assertNull($this->schema()->getByPath($props, 'tiers.5.price'));
    }

    public function test_default_item_for_array(): void
    {
        $this->assertSame(
            ['title' => 'Tier', 'price' => 0, 'featured' => false],
            $this->schema()->defaultItemAt('tiers')
        );
    }
}
