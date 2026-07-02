<?php

declare(strict_types=1);

namespace EditFront\Tests\Operation;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\Operation\OperationValidationException;
use PHPUnit\Framework\TestCase;

/** text.set / attr.set / attr.remove / style.set application + sanitize gates */
final class OpsApplyTest extends TestCase
{
    private Html5 $html5;
    private Annotator $annotator;

    protected function setUp(): void
    {
        $this->html5 = new Html5();
        $this->annotator = new Annotator();
    }

    private function applyOn(\DOMDocument $doc, string $opKey, ?string $target, array $forward): void
    {
        $spec = ef2_registry()->get($opKey);
        $this->assertNotNull($spec);
        $forward = $spec->sanitize($forward);
        $el = $target !== null ? $this->annotator->findById($doc, $target) : null;
        $spec->apply($doc, $el, $forward);
    }

    public function test_text_set_replaces_inner_html_sanitized(): void
    {
        $doc = ef2_doc('<p data-cms-id="cms-aaaaaaaaaaaa">old</p>');
        $this->applyOn($doc, 'text.set', 'cms-aaaaaaaaaaaa', [
            'html' => 'Hi <b>bold</b><script>alert(1)</script><span class="x">span</span>',
        ]);
        $p = $this->annotator->findById($doc, 'cms-aaaaaaaaaaaa');
        $inner = $this->html5->innerHtml($p);
        $this->assertStringContainsString('Hi <b>bold</b>', $inner);
        $this->assertStringNotContainsString('script', $inner);
        $this->assertStringNotContainsString('span class', $inner); // unwrapped
        $this->assertStringContainsString('span', $inner);          // text kept
    }

    public function test_text_set_strips_dangerous_href(): void
    {
        $doc = ef2_doc('<p data-cms-id="cms-aaaaaaaaaaaa">old</p>');
        $this->applyOn($doc, 'text.set', 'cms-aaaaaaaaaaaa', [
            'html' => '<a href="javascript:alert(1)">x</a><a href="/ok">y</a>',
        ]);
        $inner = $this->html5->innerHtml($this->annotator->findById($doc, 'cms-aaaaaaaaaaaa'));
        $this->assertStringNotContainsString('javascript:', $inner);
        $this->assertStringContainsString('href="/ok"', $inner);
    }

    public function test_attr_set_and_remove(): void
    {
        $doc = ef2_doc('<a data-cms-id="cms-aaaaaaaaaaaa" href="/x" title="t">link</a>');
        $this->applyOn($doc, 'attr.set', 'cms-aaaaaaaaaaaa', ['name' => 'href', 'value' => '/about.html']);
        $el = $this->annotator->findById($doc, 'cms-aaaaaaaaaaaa');
        $this->assertSame('/about.html', $el->getAttribute('href'));

        $this->applyOn($doc, 'attr.remove', 'cms-aaaaaaaaaaaa', ['name' => 'title']);
        $this->assertFalse($el->hasAttribute('title'));
    }

    public function test_attr_set_rejects_event_handlers_style_and_reserved(): void
    {
        $registry = ef2_registry();
        foreach ([
            ['name' => 'onclick', 'value' => 'x()'],
            ['name' => 'style', 'value' => 'color:red'],
            ['name' => 'data-cms-id', 'value' => 'cms-bbbbbbbbbbbb'],
        ] as $forward) {
            try {
                $registry->get('attr.set')->sanitize($forward);
                $this->fail('expected reject for ' . $forward['name']);
            } catch (OperationValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_attr_set_url_gate(): void
    {
        $registry = ef2_registry();
        try {
            $registry->get('attr.set')->sanitize(['name' => 'href', 'value' => 'javascript:alert(1)']);
            $this->fail('expected reject');
        } catch (OperationValidationException) {
        }
        // data:image allowed for src only
        $ok = $registry->get('attr.set')->sanitize(['name' => 'src', 'value' => 'data:image/png;base64,AAAA']);
        $this->assertSame('data:image/png;base64,AAAA', $ok['value']);
        try {
            $registry->get('attr.set')->sanitize(['name' => 'href', 'value' => 'data:image/png;base64,AAAA']);
            $this->fail('expected reject');
        } catch (OperationValidationException) {
        }
    }

    public function test_style_set_add_update_remove(): void
    {
        $doc = ef2_doc('<p data-cms-id="cms-aaaaaaaaaaaa" style="color: red">x</p>');
        $el = $this->annotator->findById($doc, 'cms-aaaaaaaaaaaa');

        $this->applyOn($doc, 'style.set', 'cms-aaaaaaaaaaaa', ['prop' => 'font-size', 'value' => '1.25em']);
        $this->assertStringContainsString('font-size: 1.25em', $el->getAttribute('style'));
        $this->assertStringContainsString('color: red', $el->getAttribute('style'));

        $this->applyOn($doc, 'style.set', 'cms-aaaaaaaaaaaa', ['prop' => 'color', 'value' => 'blue']);
        $this->assertStringContainsString('color: blue', $el->getAttribute('style'));

        $this->applyOn($doc, 'style.set', 'cms-aaaaaaaaaaaa', ['prop' => 'font-size', 'value' => '']);
        $this->assertStringNotContainsString('font-size', $el->getAttribute('style'));

        $this->applyOn($doc, 'style.set', 'cms-aaaaaaaaaaaa', ['prop' => 'color', 'value' => '']);
        $this->assertFalse($el->hasAttribute('style'), 'empty style map removes the attribute');
    }

    public function test_style_set_rejects_css_injection(): void
    {
        $registry = ef2_registry();
        foreach (['expression(alert(1))', 'url(javascript:alert(1))', 'red; position: fixed'] as $value) {
            try {
                $registry->get('style.set')->sanitize(['prop' => 'color', 'value' => $value]);
                $this->fail('expected reject for: ' . $value);
            } catch (OperationValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
