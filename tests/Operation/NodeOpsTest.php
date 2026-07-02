<?php

declare(strict_types=1);

namespace EditFront\Tests\Operation;

use EditFront\Document\Annotator;
use EditFront\Operation\OperationApplyException;
use PHPUnit\Framework\TestCase;

/** node.insert / node.delete / node.move / node.duplicate */
final class NodeOpsTest extends TestCase
{
    private Annotator $annotator;

    protected function setUp(): void
    {
        $this->annotator = new Annotator();
    }

    private function doc(): \DOMDocument
    {
        return ef2_doc(
            '<h1 data-cms-id="cms-aaaaaaaaaaaa">One</h1>'
            . '<p data-cms-id="cms-bbbbbbbbbbbb">Two</p>'
            . '<div data-cms-id="cms-dddddddddddd"><span data-cms-id="cms-eeeeeeeeeeee">in</span></div>'
            . '<img data-cms-id="cms-ffffffffffff" src="x.png" alt="">'
        );
    }

    private function apply(\DOMDocument $doc, string $key, ?string $target, array $forward): void
    {
        $spec = ef2_registry()->get($key);
        $forward = $spec->sanitize($forward);
        $el = $target !== null ? $this->annotator->findById($doc, $target) : null;
        $spec->apply($doc, $el, $forward);
    }

    /** element children tag list of body */
    private function bodyTags(\DOMDocument $doc): array
    {
        $tags = [];
        foreach ($doc->getElementsByTagName('body')->item(0)->childNodes as $n) {
            if ($n instanceof \DOMElement) {
                $tags[] = $n->tagName;
            }
        }
        return $tags;
    }

    public function test_insert_positions(): void
    {
        $doc = $this->doc();
        $this->apply($doc, 'node.insert', null, [
            'refId' => 'cms-aaaaaaaaaaaa', 'position' => 'after', 'template' => 'paragraph', 'newId' => 'cms-111111111111',
        ]);
        $this->assertSame(['h1', 'p', 'p', 'div', 'img'], $this->bodyTags($doc));

        $this->apply($doc, 'node.insert', null, [
            'refId' => 'cms-aaaaaaaaaaaa', 'position' => 'before', 'template' => 'divider', 'newId' => 'cms-222222222222',
        ]);
        $this->assertSame(['hr', 'h1', 'p', 'p', 'div', 'img'], $this->bodyTags($doc));

        $this->apply($doc, 'node.insert', null, [
            'refId' => 'cms-dddddddddddd', 'position' => 'inside-first', 'template' => 'heading', 'newId' => 'cms-333333333333',
        ]);
        $div = $this->annotator->findById($doc, 'cms-dddddddddddd');
        $this->assertSame('h2', $div->firstElementChild->tagName);

        $this->apply($doc, 'node.insert', null, [
            'refId' => 'cms-dddddddddddd', 'position' => 'inside-last', 'template' => 'button', 'newId' => 'cms-444444444444',
        ]);
        $this->assertSame('a', $div->lastElementChild->tagName);
    }

    public function test_insert_section_children_get_fresh_ids(): void
    {
        $doc = $this->doc();
        $this->apply($doc, 'node.insert', null, [
            'refId' => 'cms-aaaaaaaaaaaa', 'position' => 'after', 'template' => 'section', 'newId' => 'cms-111111111111',
        ]);
        $section = $this->annotator->findById($doc, 'cms-111111111111');
        $this->assertNotNull($section);
        foreach ($section->getElementsByTagName('*') as $child) {
            $this->assertMatchesRegularExpression(
                Annotator::ID_PATTERN,
                $child->getAttribute('data-cms-id'),
                'nested template elements must carry server-assigned ids'
            );
        }
    }

    public function test_insert_guards(): void
    {
        $doc = $this->doc();
        try {
            $this->apply($doc, 'node.insert', null, [
                'refId' => 'cms-ffffffffffff', 'position' => 'inside-last', 'template' => 'paragraph', 'newId' => 'cms-111111111111',
            ]);
            $this->fail('inside void must fail');
        } catch (OperationApplyException) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->apply($doc, 'node.insert', null, [
                'refId' => 'cms-aaaaaaaaaaaa', 'position' => 'after', 'template' => 'paragraph', 'newId' => 'cms-bbbbbbbbbbbb',
            ]);
            $this->fail('duplicate newId must fail');
        } catch (OperationApplyException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_delete(): void
    {
        $doc = $this->doc();
        $this->apply($doc, 'node.delete', 'cms-bbbbbbbbbbbb', []);
        $this->assertNull($this->annotator->findById($doc, 'cms-bbbbbbbbbbbb'));
        $this->assertSame(['h1', 'div', 'img'], $this->bodyTags($doc));
    }

    public function test_move_within_body(): void
    {
        $doc = $this->doc();
        $this->apply($doc, 'node.move', 'cms-aaaaaaaaaaaa', [
            'before' => ['parentId' => null, 'index' => 0],
            'after' => ['parentId' => null, 'index' => 2],
        ]);
        $this->assertSame(['p', 'div', 'h1', 'img'], $this->bodyTags($doc));
    }

    public function test_move_into_other_parent(): void
    {
        $doc = $this->doc();
        $this->apply($doc, 'node.move', 'cms-bbbbbbbbbbbb', [
            'before' => ['parentId' => null, 'index' => 1],
            'after' => ['parentId' => 'cms-dddddddddddd', 'index' => 0],
        ]);
        $div = $this->annotator->findById($doc, 'cms-dddddddddddd');
        $this->assertSame('p', $div->firstElementChild->tagName);
        $this->assertSame(['h1', 'div', 'img'], $this->bodyTags($doc));
    }

    public function test_move_cycle_guard(): void
    {
        $doc = $this->doc();
        $this->expectException(OperationApplyException::class);
        $this->apply($doc, 'node.move', 'cms-dddddddddddd', [
            'before' => ['parentId' => null, 'index' => 2],
            'after' => ['parentId' => 'cms-eeeeeeeeeeee', 'index' => 0],
        ]);
    }

    public function test_duplicate_regenerates_subtree_ids(): void
    {
        $doc = $this->doc();
        $this->apply($doc, 'node.duplicate', 'cms-dddddddddddd', ['newId' => 'cms-999999999999']);

        $clone = $this->annotator->findById($doc, 'cms-999999999999');
        $this->assertNotNull($clone);
        $this->assertSame('div', $clone->tagName);

        $cloneSpan = $clone->getElementsByTagName('span')->item(0);
        $this->assertNotSame('cms-eeeeeeeeeeee', $cloneSpan->getAttribute('data-cms-id'));
        $this->assertMatchesRegularExpression(Annotator::ID_PATTERN, $cloneSpan->getAttribute('data-cms-id'));

        // all ids unique across the document
        $ids = [];
        foreach ((new \DOMXPath($doc))->query('//*[@data-cms-id]') as $el) {
            $ids[] = $el->getAttribute('data-cms-id');
        }
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_duplicate_applies_provided_child_ids(): void
    {
        $doc = $this->doc();
        $this->apply($doc, 'node.duplicate', 'cms-dddddddddddd', [
            'newId' => 'cms-999999999999',
            'childIds' => ['cms-111111111111'],
        ]);
        $clone = $this->annotator->findById($doc, 'cms-999999999999');
        $span = $clone->getElementsByTagName('span')->item(0);
        $this->assertSame('cms-111111111111', $span->getAttribute('data-cms-id'));
    }

    public function test_duplicate_falls_back_when_childids_count_mismatch(): void
    {
        $doc = $this->doc();
        // two ids for one editable descendant → list ignored, fresh ids assigned
        $this->apply($doc, 'node.duplicate', 'cms-dddddddddddd', [
            'newId' => 'cms-999999999999',
            'childIds' => ['cms-111111111111', 'cms-222222222222'],
        ]);
        $span = $this->annotator->findById($doc, 'cms-999999999999')->getElementsByTagName('span')->item(0);
        $this->assertNotSame('cms-111111111111', $span->getAttribute('data-cms-id'));
        $this->assertMatchesRegularExpression(Annotator::ID_PATTERN, $span->getAttribute('data-cms-id'));
    }

    public function test_duplicate_falls_back_when_childid_collides(): void
    {
        $doc = $this->doc();
        // childId collides with an existing id → reject, fresh ids assigned
        $this->apply($doc, 'node.duplicate', 'cms-dddddddddddd', [
            'newId' => 'cms-999999999999',
            'childIds' => ['cms-aaaaaaaaaaaa'],
        ]);
        $span = $this->annotator->findById($doc, 'cms-999999999999')->getElementsByTagName('span')->item(0);
        $this->assertNotSame('cms-aaaaaaaaaaaa', $span->getAttribute('data-cms-id'));
        $ids = [];
        foreach ((new \DOMXPath($doc))->query('//*[@data-cms-id]') as $el) {
            $ids[] = $el->getAttribute('data-cms-id');
        }
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_duplicate_schema_accepts_child_ids(): void
    {
        $spec = ef2_registry()->get('node.duplicate');
        $errors = (new \EditFront\Operation\SchemaValidator())->validate(
            $spec->schema(),
            ['newId' => 'cms-999999999999', 'childIds' => ['cms-111111111111', 'cms-222222222222']]
        );
        $this->assertSame([], $errors);
    }
}
