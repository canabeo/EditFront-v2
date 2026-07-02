<?php

declare(strict_types=1);

namespace EditFront\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The mini-panel is built from this registry, never from hardcoded HTML —
 * unit acceptance for requirement №1 (§0.3: «кнопки мини-панели строятся из
 * quickActions() реестра»). Plugins extend the same registry in C3.
 */
final class ElementTypesJsonTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $registry;

    protected function setUp(): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__) . '/assets/element-types.json');
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'element-types.json must be valid JSON');
        $this->registry = $decoded;
    }

    public function test_structure(): void
    {
        $this->assertIsInt($this->registry['version']);
        $this->assertIsArray($this->registry['actionCatalog']);
        $this->assertNotEmpty($this->registry['actionCatalog']);
        $this->assertIsArray($this->registry['types']);
        $this->assertNotEmpty($this->registry['types']);
    }

    public function test_catalog_unique(): void
    {
        $catalog = $this->registry['actionCatalog'];
        $this->assertSame(count($catalog), count(array_unique($catalog)));
    }

    public function test_every_type_is_well_formed(): void
    {
        $catalog = $this->registry['actionCatalog'];
        $keys = [];
        foreach ($this->registry['types'] as $type) {
            $this->assertIsString($type['key']);
            $this->assertIsString($type['label']);
            $this->assertNotSame('', $type['label']);
            $this->assertIsString($type['match']);
            $this->assertNotSame('', $type['match']);
            $keys[] = $type['key'];

            $quick = $type['quickActions'] ?? [];
            $this->assertGreaterThanOrEqual(1, count($quick), $type['key']);
            $this->assertLessThanOrEqual(6, count($quick), $type['key'] . ': mini-panel is 3–6 buttons (§5.3)');

            foreach (array_merge($quick, $type['more'] ?? []) as $action) {
                $this->assertContains($action, $catalog, "{$type['key']}: action '$action' not in catalog");
            }
        }
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_generic_fallback_exists(): void
    {
        $matches = array_column($this->registry['types'], 'match');
        $this->assertContains('*', $matches, 'a generic * fallback type is required');
    }
}
