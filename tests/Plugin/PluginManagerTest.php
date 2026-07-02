<?php

declare(strict_types=1);

namespace EditFront\Tests\Plugin;

use EditFront\Operation\SchemaValidator;
use EditFront\Plugin\PluginManager;
use EditFront\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

/**
 * §6.4.2 / §6.5 / §6.6: discovery, registration into the shared registry, the
 * inverse fixtures-round-trip GATE (a broken plugin must degrade to read-only,
 * never crash boot), trust-model decision, and that plugin ops pass the same
 * schema machinery as core ops (whitelist-drift cure extends automatically).
 */
final class PluginManagerTest extends TestCase
{
    private function manager(array $sourceDirs): PluginManager
    {
        $cms = ef2_plugin_cms($sourceDirs);
        $storage = ef2_temp_dir('pm-storage');
        $config = ef2_test_config(['cms_dir' => $cms, 'storage_dir' => $storage]);
        return ef2_plugin_manager($config);
    }

    public function test_discovers_and_enables_reference_plugin(): void
    {
        $pm = $this->manager([ef2_pricing_plugin_dir()]);
        $pm->boot();
        $statuses = $pm->statuses();

        $this->assertArrayHasKey('pricing-table', $statuses);
        $this->assertSame('enabled', $statuses['pricing-table']['status']);
        $this->assertTrue($pm->elementTypes()->has('pricing-table'));
    }

    public function test_registers_all_five_plugin_ops(): void
    {
        $pm = $this->manager([ef2_pricing_plugin_dir()]);
        $keys = array_map(static fn ($op) => $op->key(), $pm->operationSpecs());
        foreach ([
            'plugin.pricing-table.insert',
            'plugin.pricing-table.setprop',
            'plugin.pricing-table.arrayinsert',
            'plugin.pricing-table.arrayremove',
            'plugin.pricing-table.arraymove',
        ] as $expected) {
            $this->assertContains($expected, $keys);
        }
    }

    public function test_broken_invert_degrades_and_boot_survives(): void
    {
        $pm = $this->manager([ef2_pricing_plugin_dir(), ef2_broken_plugin_dir()]);
        $pm->boot(); // must NOT throw — isolation (§6.6)
        $statuses = $pm->statuses();

        $this->assertSame('degraded', $statuses['broken-invert']['status']);
        $this->assertNotSame('', $statuses['broken-invert']['reason']);
        $this->assertFalse($pm->elementTypes()->has('broken-block'));

        // the good plugin next to it stays fully enabled
        $this->assertSame('enabled', $statuses['pricing-table']['status']);

        // no ops registered for the degraded plugin
        $keys = array_map(static fn ($op) => $op->key(), $pm->operationSpecs());
        foreach ($keys as $k) {
            $this->assertStringNotContainsString('broken-invert', $k);
        }
    }

    public function test_plugin_ops_pass_the_same_schema_validator(): void
    {
        // "plugin op passes OperationSchemaParityTest automatically" (§6, acceptance):
        // the FormRenderer's emissions validate against each op's own schema()
        $pm = $this->manager([ef2_pricing_plugin_dir()]);
        $validator = new SchemaValidator();
        $byKey = [];
        foreach ($pm->operationSpecs() as $op) {
            $byKey[$op->key()] = $op;
        }

        $emissions = [
            'plugin.pricing-table.insert' => ['kind' => 'pricing-table', 'refId' => 'cms-aaaaaaaaaaaa', 'position' => 'after', 'newId' => 'cms-bbbbbbbbbbbb', 'props' => ['currency' => 'USD']],
            'plugin.pricing-table.setprop' => ['path' => 'currency', 'value' => 'USD'],
            'plugin.pricing-table.arrayinsert' => ['path' => 'tiers', 'index' => 0, 'item' => ['title' => 'A', 'price' => 9, 'featured' => false]],
            'plugin.pricing-table.arrayremove' => ['path' => 'tiers', 'index' => 0],
            'plugin.pricing-table.arraymove' => ['path' => 'tiers', 'from' => 0, 'to' => 1],
        ];
        foreach ($emissions as $key => $forward) {
            $op = $byKey[$key];
            $clean = $op->sanitize($forward);
            $errors = $validator->validate($op->schema(), $clean);
            $this->assertSame([], $errors, "emission for $key failed schema: " . implode('; ', $errors));
        }
    }

    public function test_trust_model_runtime_decision(): void
    {
        $pm = $this->manager([ef2_pricing_plugin_dir()]);
        $pm->boot();
        // author core ≥ verified → runtime allowed without an explicit approve
        $this->assertTrue($pm->runtimeAllowed('pricing-table'));
        $this->assertFalse($pm->runtimeAllowed('does-not-exist'));
    }

    public function test_writes_registry_file(): void
    {
        $cms = ef2_plugin_cms([ef2_pricing_plugin_dir()]);
        $storage = ef2_temp_dir('pm-storage');
        $pm = ef2_plugin_manager(ef2_test_config(['cms_dir' => $cms, 'storage_dir' => $storage]));
        $pm->boot();
        $this->assertFileExists($storage . '/plugins.json');
        $reg = json_decode((string) file_get_contents($storage . '/plugins.json'), true);
        $this->assertSame('enabled', $reg['pricing-table']['status']);
    }

    public function test_no_plugins_dir_is_fine(): void
    {
        $pm = ef2_plugin_manager(ef2_test_config(['cms_dir' => ef2_temp_dir('empty-cms'), 'storage_dir' => ef2_temp_dir('empty-st')]));
        $pm->boot();
        $this->assertSame([], $pm->operationSpecs());
        $this->assertSame([], $pm->statuses());
    }

    public function test_trust_rank_table_sane(): void
    {
        $this->assertGreaterThan(PluginManifest::TRUST_RANK['local'], PluginManifest::TRUST_RANK['verified']);
        $this->assertGreaterThan(PluginManifest::TRUST_RANK['marketplace'], PluginManifest::TRUST_RANK['local']);
    }
}
