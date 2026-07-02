<?php

declare(strict_types=1);

namespace EditFront\Tests\Plugin;

use EditFront\Plugin\OrphanResolver;
use EditFront\Plugin\PluginManager;
use EditFront\Plugin\PropsStore;
use PHPUnit\Framework\TestCase;

/**
 * §6.5.1 — the main Free case: open an existing site that may carry foreign or
 * sidecar-less plugin blocks. The page must NEVER crash and NEVER stay silent:
 * each block is recovered from the DOM or marked read-only with a reason.
 */
final class OrphanResolverTest extends TestCase
{
    private PluginManager $pm;
    private PropsStore $props;
    private OrphanResolver $resolver;
    private string $page = 'p.html';

    protected function setUp(): void
    {
        $cms = ef2_plugin_cms([ef2_pricing_plugin_dir()]);
        $storage = ef2_temp_dir('orphan-storage');
        $config = ef2_test_config(['cms_dir' => $cms, 'storage_dir' => $storage]);
        $this->pm = ef2_plugin_manager($config);
        $this->props = ef2_props_store($config);
        $this->resolver = new OrphanResolver($this->pm, $this->props);
    }

    private function block(string $id, int $tierCount = 1): string
    {
        $tiers = '';
        for ($i = 0; $i < $tierCount; $i++) {
            $tiers .= '<div class="ef-pt-tier" data-pt-title="T' . $i . '" data-pt-price="' . ($i + 1) . '" data-pt-featured="0"><h3>T' . $i . '</h3></div>';
        }
        return '<div data-cms-block="pricing-table" data-cms-id="' . $id . '" data-cms-v="1">'
            . '<div class="ef-pt" data-pt-currency="EUR" data-pt-period="mo">' . $tiers . '</div></div>';
    }

    public function test_ok_when_sidecar_present(): void
    {
        $id = 'cms-bbbbbbbbbbbb';
        $this->props->sync($this->page, [$id => [
            'kind' => 'pricing-table', 'version' => 1,
            'props' => ['currency' => 'EUR', 'period' => 'mo', 'tiers' => [['title' => 'T0', 'price' => 1, 'featured' => false]]],
        ]]);
        $map = $this->resolver->classify(ef2_doc($this->block($id)), $this->page);

        $this->assertSame('ok', $map[$id]['status']);
        $this->assertTrue($map[$id]['editable']);
        $this->assertSame('EUR', $map[$id]['props']['currency']);
    }

    public function test_restored_from_dom_when_no_sidecar(): void
    {
        $id = 'cms-cccccccccccc';
        $map = $this->resolver->classify(ef2_doc($this->block($id, 2)), $this->page);

        $this->assertSame('restored', $map[$id]['status']);
        $this->assertTrue($map[$id]['editable']);
        $this->assertCount(2, $map[$id]['props']['tiers']);
    }

    public function test_unknown_kind_is_read_only(): void
    {
        $id = 'cms-dddddddddddd';
        $html = '<div data-cms-block="mystery-widget" data-cms-id="' . $id . '"><p>foreign</p></div>';
        $map = $this->resolver->classify(ef2_doc($html), $this->page);

        $this->assertSame('unknown-kind', $map[$id]['status']);
        $this->assertFalse($map[$id]['editable']);
        $this->assertSame('mystery-widget', $map[$id]['kind']);
    }

    public function test_invalid_import_is_read_only(): void
    {
        $id = 'cms-eeeeeeeeeeee';
        // 7 tiers > schema max 6 → props don't validate → read-only degrade
        $map = $this->resolver->classify(ef2_doc($this->block($id, 7)), $this->page);

        $this->assertSame('invalid-import', $map[$id]['status']);
        $this->assertFalse($map[$id]['editable']);
    }

    public function test_rest_of_page_unaffected(): void
    {
        $id = 'cms-ffffffffffff';
        $html = $this->block($id) . '<p data-cms-id="cms-111111111111">normal</p>';
        $map = $this->resolver->classify(ef2_doc($html), $this->page);
        // only block nodes are classified; the normal paragraph is untouched
        $this->assertArrayHasKey($id, $map);
        $this->assertArrayNotHasKey('cms-111111111111', $map);
    }
}
