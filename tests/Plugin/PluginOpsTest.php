<?php

declare(strict_types=1);

namespace EditFront\Tests\Plugin;

use EditFront\Document\Annotator;
use EditFront\Operation\OperationApplyException;
use EditFront\Operation\OperationSpec;
use EditFront\Plugin\PluginManager;
use PHPUnit\Framework\TestCase;

/**
 * Plugin ops apply through the same OperationSpec contract as core ops (§7.6).
 * Insert renders a block from props; the mutate ops read props from the DOM
 * (serialize), mutate, re-render — and round-trip on the DOM exactly.
 */
final class PluginOpsTest extends TestCase
{
    private PluginManager $pm;
    /** @var array<string, OperationSpec> */
    private array $ops;

    protected function setUp(): void
    {
        $cms = ef2_plugin_cms([ef2_pricing_plugin_dir()]);
        $storage = ef2_temp_dir('ops-storage');
        $this->pm = ef2_plugin_manager(ef2_test_config(['cms_dir' => $cms, 'storage_dir' => $storage]));
        $this->ops = [];
        foreach ($this->pm->operationSpecs() as $op) {
            $this->ops[$op->key()] = $op;
        }
    }

    private function serialize(\DOMDocument $doc, string $id): array
    {
        $node = (new Annotator())->findById($doc, $id);
        $this->assertNotNull($node);
        return $this->pm->elementTypes()->get('pricing-table')->block()->serialize($node);
    }

    public function test_insert_renders_a_block(): void
    {
        $doc = ef2_doc('<p data-cms-id="cms-aaaaaaaaaaaa">ref</p>');
        $this->ops['plugin.pricing-table.insert']->apply($doc, null, [
            'kind' => 'pricing-table',
            'refId' => 'cms-aaaaaaaaaaaa',
            'position' => 'after',
            'newId' => 'cms-bbbbbbbbbbbb',
            'props' => ['tiers' => [['title' => 'Basic', 'price' => 9, 'featured' => false]]],
        ]);

        $block = (new Annotator())->findById($doc, 'cms-bbbbbbbbbbbb');
        $this->assertNotNull($block);
        $this->assertSame('pricing-table', $block->getAttribute('data-cms-block'));

        $props = $this->serialize($doc, 'cms-bbbbbbbbbbbb');
        $this->assertSame('EUR', $props['currency']);
        $this->assertCount(1, $props['tiers']);
        $this->assertSame(9, $props['tiers'][0]['price']);
    }

    public function test_setprop_round_trips_on_the_dom(): void
    {
        $doc = $this->seededDoc();
        $block = (new Annotator())->findById($doc, 'cms-bbbbbbbbbbbb');

        $this->ops['plugin.pricing-table.setprop']->apply($doc, $block, ['path' => 'tiers.0.price', 'value' => 50]);
        $this->assertSame(50, $this->serialize($doc, 'cms-bbbbbbbbbbbb')['tiers'][0]['price']);

        // applying the inverse forward restores the DOM
        $this->ops['plugin.pricing-table.setprop']->apply($doc, $block, ['path' => 'tiers.0.price', 'value' => 9]);
        $this->assertSame(9, $this->serialize($doc, 'cms-bbbbbbbbbbbb')['tiers'][0]['price']);
    }

    public function test_arrayinsert_and_arrayremove_on_the_dom(): void
    {
        $doc = $this->seededDoc();
        $block = (new Annotator())->findById($doc, 'cms-bbbbbbbbbbbb');

        $this->ops['plugin.pricing-table.arrayinsert']->apply($doc, $block, [
            'path' => 'tiers', 'index' => 1, 'item' => ['title' => 'Pro', 'price' => 19, 'featured' => true],
        ]);
        $this->assertCount(2, $this->serialize($doc, 'cms-bbbbbbbbbbbb')['tiers']);

        $this->ops['plugin.pricing-table.arrayremove']->apply($doc, $block, ['path' => 'tiers', 'index' => 1]);
        $this->assertCount(1, $this->serialize($doc, 'cms-bbbbbbbbbbbb')['tiers']);
    }

    public function test_mutate_on_non_block_target_rejected(): void
    {
        $doc = ef2_doc('<p data-cms-id="cms-aaaaaaaaaaaa">plain</p>');
        $p = (new Annotator())->findById($doc, 'cms-aaaaaaaaaaaa');
        $this->expectException(OperationApplyException::class);
        $this->ops['plugin.pricing-table.setprop']->apply($doc, $p, ['path' => 'currency', 'value' => 'USD']);
    }

    private function seededDoc(): \DOMDocument
    {
        $doc = ef2_doc('<p data-cms-id="cms-aaaaaaaaaaaa">ref</p>');
        $this->ops['plugin.pricing-table.insert']->apply($doc, null, [
            'kind' => 'pricing-table',
            'refId' => 'cms-aaaaaaaaaaaa',
            'position' => 'after',
            'newId' => 'cms-bbbbbbbbbbbb',
            'props' => ['tiers' => [['title' => 'Basic', 'price' => 9, 'featured' => false]]],
        ]);
        return $doc;
    }

    // The editor now inserts INTO a selected container (a grid, a flex row) as
    // its last child rather than beside it — that path leans on these two facts.
    public function test_insert_inside_last_appends_into_the_container(): void
    {
        $doc = ef2_doc(
            '<div data-cms-id="cms-aaaaaaaaaaaa">'
            . '<p data-cms-id="cms-cccccccccccc">first</p>'
            . '<p data-cms-id="cms-dddddddddddd">second</p>'
            . '</div>'
        );
        $this->ops['plugin.pricing-table.insert']->apply($doc, null, [
            'kind' => 'pricing-table',
            'refId' => 'cms-aaaaaaaaaaaa',
            'position' => 'inside-last',
            'newId' => 'cms-bbbbbbbbbbbb',
            'props' => ['tiers' => [['title' => 'Basic', 'price' => 9, 'featured' => false]]],
        ]);

        $container = (new Annotator())->findById($doc, 'cms-aaaaaaaaaaaa');
        $block = (new Annotator())->findById($doc, 'cms-bbbbbbbbbbbb');
        $this->assertNotNull($block);
        $this->assertSame($container, $block->parentNode, 'block must be a child of the ref, not its sibling');
        $this->assertSame($block, $container->lastChild, 'block must be the last child');
        $this->assertSame(3, $container->childNodes->length, 'existing children stay in place');
    }

    public function test_insert_inside_a_void_element_is_rejected(): void
    {
        $doc = ef2_doc('<img data-cms-id="cms-aaaaaaaaaaaa" src="x.jpg" alt="">');
        $this->expectException(OperationApplyException::class);
        $this->ops['plugin.pricing-table.insert']->apply($doc, null, [
            'kind' => 'pricing-table',
            'refId' => 'cms-aaaaaaaaaaaa',
            'position' => 'inside-last',
            'newId' => 'cms-bbbbbbbbbbbb',
            'props' => ['tiers' => [['title' => 'Basic', 'price' => 9, 'featured' => false]]],
        ]);
    }
}
