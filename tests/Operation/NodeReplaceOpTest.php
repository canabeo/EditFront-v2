<?php

declare(strict_types=1);

namespace EditFront\Tests\Operation;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\Operation\OperationApplyException;
use EditFront\Operation\Ops\NodeReplaceOp;
use PHPUnit\Framework\TestCase;

final class NodeReplaceOpTest extends TestCase
{
    private NodeReplaceOp $op;
    private Annotator $annotator;
    private Html5 $html5;

    protected function setUp(): void
    {
        $this->annotator = new Annotator();
        $this->html5 = new Html5();
        $this->op = new NodeReplaceOp($this->annotator, $this->html5, ef2_sanitizer_html());
    }

    private function replace(\DOMDocument $doc, string $targetId, string $html): void
    {
        $target = $this->annotator->findById($doc, $targetId);
        $forward = $this->op->sanitize(['html' => $html]);
        $this->op->apply($doc, $target, $forward);
    }

    public function test_retags_host_keeping_id(): void
    {
        $doc = ef2_doc('<p data-cms-id="cms-aaaaaaaaaaaa">hello</p>');
        $this->replace($doc, 'cms-aaaaaaaaaaaa', '<h2 data-cms-id="cms-aaaaaaaaaaaa">hello</h2>');
        $out = $this->html5->serialize($doc);
        $this->assertStringContainsString('<h2 data-cms-id="cms-aaaaaaaaaaaa">hello</h2>', $out);
        $this->assertStringNotContainsString('<p data-cms-id="cms-aaaaaaaaaaaa"', $out);
    }

    public function test_forces_root_id_to_target_even_without_one(): void
    {
        $doc = ef2_doc('<p data-cms-id="cms-aaaaaaaaaaaa">hi</p>');
        $this->replace($doc, 'cms-aaaaaaaaaaaa', '<blockquote>hi</blockquote>');
        $this->assertNotNull($this->annotator->findById($doc, 'cms-aaaaaaaaaaaa'));
        $this->assertSame('blockquote', $this->annotator->findById($doc, 'cms-aaaaaaaaaaaa')->tagName);
    }

    public function test_wraps_as_list(): void
    {
        $doc = ef2_doc('<p data-cms-id="cms-aaaaaaaaaaaa">item</p>');
        $this->replace($doc, 'cms-aaaaaaaaaaaa', '<ul data-cms-id="cms-aaaaaaaaaaaa"><li>item</li></ul>');
        $out = $this->html5->serialize($doc);
        $this->assertStringContainsString('<ul data-cms-id="cms-aaaaaaaaaaaa"><li>item</li></ul>', $out);
    }

    public function test_strips_descendant_id_that_collides_with_live_node(): void
    {
        $doc = ef2_doc(
            '<p data-cms-id="cms-aaaaaaaaaaaa">a</p>'
            . '<div data-cms-id="cms-bbbbbbbbbbbb">b</div>'
        );
        // the replacement reuses cms-bbbb (still alive on the div) → must be stripped
        $this->replace($doc, 'cms-aaaaaaaaaaaa', '<h2 data-cms-id="cms-aaaaaaaaaaaa"><span data-cms-id="cms-bbbbbbbbbbbb">x</span></h2>');
        // the live div keeps its id; the colliding span lost its id
        $this->assertNotNull($this->annotator->findById($doc, 'cms-bbbbbbbbbbbb'));
        $this->assertSame('div', $this->annotator->findById($doc, 'cms-bbbbbbbbbbbb')->tagName);
    }

    public function test_keeps_descendant_id_from_old_subtree(): void
    {
        $doc = ef2_doc('<p data-cms-id="cms-aaaaaaaaaaaa">w <b data-cms-id="cms-cccccccccccc">x</b></p>');
        $this->replace($doc, 'cms-aaaaaaaaaaaa', '<h2 data-cms-id="cms-aaaaaaaaaaaa">w <b data-cms-id="cms-cccccccccccc">x</b></h2>');
        // the nested id was inside the replaced subtree → freed → kept
        $b = $this->annotator->findById($doc, 'cms-cccccccccccc');
        $this->assertNotNull($b);
        $this->assertSame('b', $b->tagName);
    }

    public function test_target_required(): void
    {
        $doc = ef2_doc('<p data-cms-id="cms-aaaaaaaaaaaa">x</p>');
        $this->expectException(OperationApplyException::class);
        $this->op->apply($doc, null, $this->op->sanitize(['html' => '<h2>x</h2>']));
    }
}
