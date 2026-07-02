<?php

declare(strict_types=1);

namespace EditFront\Tests\Operation;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\Operation\OperationValidationException;
use EditFront\Operation\Ops\StyleStateOp;
use EditFront\Security\SanitizerCss;
use PHPUnit\Framework\TestCase;

final class StyleStateOpTest extends TestCase
{
    private StyleStateOp $op;
    private Annotator $annotator;
    private Html5 $html5;

    protected function setUp(): void
    {
        $this->op = new StyleStateOp(new SanitizerCss());
        $this->annotator = new Annotator();
        $this->html5 = new Html5();
    }

    private function apply(\DOMElement $el, array $forward): void
    {
        $this->op->apply($el->ownerDocument, $el, $this->op->sanitize($forward));
    }

    private function el(string $body): \DOMElement
    {
        $doc = ef2_doc($body);
        return $this->annotator->findById($doc, 'cms-aaaaaaaaaaaa');
    }

    public function test_sets_hover_prop(): void
    {
        $el = $this->el('<a data-cms-id="cms-aaaaaaaaaaaa">x</a>');
        $this->apply($el, ['state' => 'hover', 'prop' => 'color', 'value' => 'red']);
        $this->assertSame('color: red', $el->getAttribute('data-cms-hover'));
    }

    public function test_merges_props(): void
    {
        $el = $this->el('<a data-cms-id="cms-aaaaaaaaaaaa" data-cms-hover="color: red">x</a>');
        $this->apply($el, ['state' => 'hover', 'prop' => 'background-color', 'value' => '#eee']);
        $this->assertSame('color: red; background-color: #eee', $el->getAttribute('data-cms-hover'));
    }

    public function test_empty_value_removes_prop_and_attr(): void
    {
        $el = $this->el('<a data-cms-id="cms-aaaaaaaaaaaa" data-cms-hover="color: red">x</a>');
        $this->apply($el, ['state' => 'hover', 'prop' => 'color', 'value' => '']);
        $this->assertFalse($el->hasAttribute('data-cms-hover'));
    }

    public function test_invalid_state_rejected(): void
    {
        $this->expectException(OperationValidationException::class);
        $this->op->sanitize(['state' => 'active', 'prop' => 'color', 'value' => 'red']);
    }

    public function test_dangerous_value_rejected(): void
    {
        $this->expectException(OperationValidationException::class);
        $this->op->sanitize(['state' => 'hover', 'prop' => 'background', 'value' => 'url(javascript:alert(1))']);
    }
}
