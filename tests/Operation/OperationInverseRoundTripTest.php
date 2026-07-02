<?php

declare(strict_types=1);

namespace EditFront\Tests\Operation;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance C2: inverse(forward(S)) ≡ S for EVERY base op (invariant 0.2.4 —
 * no action exists that undo cannot revert). The inverse mapping below is
 * exactly what the client CommandLog builds (preview-inject capture-before),
 * expressed through the same server ops — this test pins that contract.
 */
final class OperationInverseRoundTripTest extends TestCase
{
    private Html5 $html5;
    private Annotator $annotator;

    protected function setUp(): void
    {
        $this->html5 = new Html5();
        $this->annotator = new Annotator();
    }

    private function doc(): \DOMDocument
    {
        // canonical-friendly fixture: our own style format, attrs in stable order
        return ef2_doc(
            '<h1 data-cms-id="cms-aaaaaaaaaaaa" style="color: red">One</h1>'
            . '<p data-cms-id="cms-bbbbbbbbbbbb" class="x">World <b data-cms-id="cms-cccccccccccc">bold</b></p>'
            . '<div data-cms-id="cms-dddddddddddd"><span data-cms-id="cms-eeeeeeeeeeee">in</span></div>'
            . '<a data-cms-id="cms-ffffffffffff" href="/x" title="t">link</a>'
        );
    }

    private function apply(\DOMDocument $doc, string $key, ?string $target, array $forward): void
    {
        $spec = ef2_registry()->get($key);
        $this->assertNotNull($spec, $key);
        $forward = $spec->sanitize($forward);
        $el = $target !== null ? $this->annotator->findById($doc, $target) : null;
        $spec->apply($doc, $el, $forward);
    }

    /**
     * @param list<array{0: string, 1: ?string, 2: array}> $forwardOps
     * @param list<array{0: string, 1: ?string, 2: array}> $inverseOps
     */
    private function assertRoundTrip(array $forwardOps, array $inverseOps): void
    {
        $doc = $this->doc();
        $snapshot = $this->html5->serialize($doc);
        foreach ($forwardOps as [$key, $target, $forward]) {
            $this->apply($doc, $key, $target, $forward);
        }
        $this->assertNotSame($snapshot, $this->html5->serialize($doc), 'forward must change the document');
        foreach ($inverseOps as [$key, $target, $forward]) {
            $this->apply($doc, $key, $target, $forward);
        }
        $this->assertSame($snapshot, $this->html5->serialize($doc), 'inverse(forward(S)) must equal S');
    }

    public function test_text_set(): void
    {
        $before = 'World <b data-cms-id="cms-cccccccccccc">bold</b>';
        $this->assertRoundTrip(
            [['text.set', 'cms-bbbbbbbbbbbb', ['html' => 'Brand <b>new</b>']]],
            [['text.set', 'cms-bbbbbbbbbbbb', ['html' => $before]]]
        );
    }

    public function test_attr_set_existing_value(): void
    {
        $this->assertRoundTrip(
            [['attr.set', 'cms-ffffffffffff', ['name' => 'title', 'value' => 'changed']]],
            [['attr.set', 'cms-ffffffffffff', ['name' => 'title', 'value' => 't']]]
        );
    }

    public function test_attr_set_new_attribute_inverse_is_remove(): void
    {
        $this->assertRoundTrip(
            [['attr.set', 'cms-bbbbbbbbbbbb', ['name' => 'title', 'value' => 'fresh']]],
            [['attr.remove', 'cms-bbbbbbbbbbbb', ['name' => 'title']]]
        );
    }

    public function test_attr_remove_inverse_is_set(): void
    {
        // title является ПОСЛЕДНИМ атрибутом <a> — restore keeps attr order
        $this->assertRoundTrip(
            [['attr.remove', 'cms-ffffffffffff', ['name' => 'title']]],
            [['attr.set', 'cms-ffffffffffff', ['name' => 'title', 'value' => 't']]]
        );
    }

    public function test_style_set_change_and_add(): void
    {
        $this->assertRoundTrip(
            [['style.set', 'cms-aaaaaaaaaaaa', ['prop' => 'color', 'value' => 'blue']]],
            [['style.set', 'cms-aaaaaaaaaaaa', ['prop' => 'color', 'value' => 'red']]]
        );
        // adding a prop ↔ removing it (empty value)
        $this->assertRoundTrip(
            [['style.set', 'cms-bbbbbbbbbbbb', ['prop' => 'font-size', 'value' => '1.25em']]],
            [['style.set', 'cms-bbbbbbbbbbbb', ['prop' => 'font-size', 'value' => '']]]
        );
    }

    public function test_node_insert_inverse_is_delete(): void
    {
        $this->assertRoundTrip(
            [['node.insert', null, ['refId' => 'cms-aaaaaaaaaaaa', 'position' => 'after', 'template' => 'paragraph', 'newId' => 'cms-111111111111']]],
            [['node.delete', 'cms-111111111111', []]]
        );
    }

    public function test_node_delete_inverse_is_restore(): void
    {
        $doc = $this->doc();
        $snapshot = $this->html5->serialize($doc);
        $victim = $this->annotator->findById($doc, 'cms-bbbbbbbbbbbb');
        // capture-before exactly like the client does (§7.4)
        $outerHtml = $this->html5->serialize($doc); // full doc — not what we need
        $victimHtml = $victim->ownerDocument->saveHTML($victim) ?: '';
        // index among element children of <body>
        $body = $doc->getElementsByTagName('body')->item(0);
        $index = 0;
        foreach ($body->childNodes as $child) {
            if ($child === $victim) {
                break;
            }
            if ($child instanceof \DOMElement) {
                $index++;
            }
        }

        $this->apply($doc, 'node.delete', 'cms-bbbbbbbbbbbb', []);
        $this->assertNotSame($snapshot, $this->html5->serialize($doc));

        $this->apply($doc, 'node.restore', null, ['parentId' => null, 'index' => $index, 'html' => $victimHtml]);
        $this->assertSame($snapshot, $this->html5->serialize($doc), 'delete → restore must be lossless');
    }

    public function test_node_move_inverse_is_move_back(): void
    {
        $this->assertRoundTrip(
            [['node.move', 'cms-aaaaaaaaaaaa', [
                'before' => ['parentId' => null, 'index' => 0],
                'after' => ['parentId' => null, 'index' => 2],
            ]]],
            [['node.move', 'cms-aaaaaaaaaaaa', [
                'before' => ['parentId' => null, 'index' => 2],
                'after' => ['parentId' => null, 'index' => 0],
            ]]]
        );
        // cross-parent move round-trips too
        $this->assertRoundTrip(
            [['node.move', 'cms-bbbbbbbbbbbb', [
                'before' => ['parentId' => null, 'index' => 1],
                'after' => ['parentId' => 'cms-dddddddddddd', 'index' => 0],
            ]]],
            [['node.move', 'cms-bbbbbbbbbbbb', [
                'before' => ['parentId' => 'cms-dddddddddddd', 'index' => 0],
                'after' => ['parentId' => null, 'index' => 1],
            ]]]
        );
    }

    public function test_node_duplicate_inverse_is_delete(): void
    {
        $this->assertRoundTrip(
            [['node.duplicate', 'cms-dddddddddddd', ['newId' => 'cms-999999999999']]],
            [['node.delete', 'cms-999999999999', []]]
        );
    }

    public function test_node_restore_inverse_is_delete(): void
    {
        $this->assertRoundTrip(
            [['node.restore', null, ['parentId' => null, 'index' => 1, 'html' => '<div data-cms-id="cms-777777777777" class="restored"><p data-cms-id="cms-888888888888">x</p></div>']]],
            [['node.delete', 'cms-777777777777', []]]
        );
    }

    public function test_node_replace_inverse_is_replace_back(): void
    {
        // host-retag: <h1> → <h2> keeping the id/style, inverse retags back
        $this->assertRoundTrip(
            [['node.replace', 'cms-aaaaaaaaaaaa', ['html' => '<h2 data-cms-id="cms-aaaaaaaaaaaa" style="color: red">One</h2>']]],
            [['node.replace', 'cms-aaaaaaaaaaaa', ['html' => '<h1 data-cms-id="cms-aaaaaaaaaaaa" style="color: red">One</h1>']]]
        );
    }

    public function test_style_state_inverse_removes_hover_prop(): void
    {
        // adding a hover prop; inverse (empty value) removes it back to no attr
        $this->assertRoundTrip(
            [['style.state', 'cms-aaaaaaaaaaaa', ['state' => 'hover', 'prop' => 'color', 'value' => 'blue']]],
            [['style.state', 'cms-aaaaaaaaaaaa', ['state' => 'hover', 'prop' => 'color', 'value' => '']]]
        );
    }

    public function test_every_registered_op_has_a_round_trip_case(): void
    {
        $covered = [
            'text.set', 'attr.set', 'attr.remove', 'style.set', 'style.state',
            'node.insert', 'node.delete', 'node.move', 'node.duplicate', 'node.restore', 'node.replace',
        ];
        foreach (array_keys(ef2_registry()->all()) as $key) {
            $this->assertContains($key, $covered, "op '$key' lacks an inverse round-trip test");
        }
    }
}
