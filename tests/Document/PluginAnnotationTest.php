<?php

declare(strict_types=1);

namespace EditFront\Tests\Document;

use EditFront\Document\Annotator;
use PHPUnit\Framework\TestCase;

/**
 * A plugin block (§6) is editable (selectable, target of props ops) but its
 * rendered subtree is NOT independently annotated or editable — the content is
 * owned by the kind and changed through props.
 */
final class PluginAnnotationTest extends TestCase
{
    public function test_block_is_stamped_but_subtree_is_not(): void
    {
        $annotator = new Annotator();
        $doc = ef2_doc(
            '<div data-cms-block="pricing-table">'
            . '<div class="ef-pt"><div class="ef-pt-tier"><h3>X</h3></div></div>'
            . '</div>'
            . '<p>plain</p>'
        );
        $annotator->ensureAnnotated($doc);

        $ids = $annotator->collectIds($doc);
        // exactly two stamped: the block node + the plain paragraph; nothing inside the block
        $this->assertCount(2, $ids);

        $xpath = new \DOMXPath($doc);
        $block = $xpath->query('//*[@data-cms-block]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $block);
        $this->assertTrue($block->hasAttribute('data-cms-id'));

        // inner nodes carry no id
        foreach ($block->getElementsByTagName('*') as $inner) {
            $this->assertFalse($inner->hasAttribute('data-cms-id'), $inner->tagName . ' should not be stamped');
        }
    }

    public function test_is_editable_rules_for_blocks(): void
    {
        $annotator = new Annotator();
        $doc = ef2_doc('<div data-cms-block="pricing-table"><div class="ef-pt"><h3>X</h3></div></div>');
        $xpath = new \DOMXPath($doc);

        $block = $xpath->query('//*[@data-cms-block]')->item(0);
        $h3 = $doc->getElementsByTagName('h3')->item(0);

        $this->assertInstanceOf(\DOMElement::class, $block);
        $this->assertInstanceOf(\DOMElement::class, $h3);
        $this->assertTrue($annotator->isEditable($block), 'the block node itself is editable');
        $this->assertFalse($annotator->isEditable($h3), 'a node inside a block is not directly editable');
    }
}
