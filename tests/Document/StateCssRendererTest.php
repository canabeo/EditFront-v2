<?php

declare(strict_types=1);

namespace EditFront\Tests\Document;

use EditFront\Document\Html5;
use EditFront\Document\StateCssRenderer;
use EditFront\Security\SanitizerCss;
use PHPUnit\Framework\TestCase;

final class StateCssRendererTest extends TestCase
{
    private StateCssRenderer $renderer;
    private Html5 $html5;

    protected function setUp(): void
    {
        $this->renderer = new StateCssRenderer(new SanitizerCss());
        $this->html5 = new Html5();
    }

    private function render(string $body): string
    {
        $doc = $this->html5->parse('<!DOCTYPE html><html><head><title>t</title></head><body>' . $body . '</body></html>');
        $this->renderer->apply($doc);
        return $this->html5->serialize($doc);
    }

    public function test_renders_scoped_hover_rule(): void
    {
        $out = $this->render('<a data-cms-id="cms-aaaaaaaaaaaa" data-cms-hover="color: red; background-color: #eee">x</a>');
        $this->assertStringContainsString('<style id="ef-state-styles">', $out);
        $this->assertStringContainsString('[data-cms-id="cms-aaaaaaaaaaaa"]:hover { color: red; background-color: #eee }', $out);
    }

    public function test_no_hover_no_block(): void
    {
        $out = $this->render('<a data-cms-id="cms-aaaaaaaaaaaa">x</a>');
        $this->assertStringNotContainsString('ef-state-styles', $out);
    }

    public function test_idempotent_rebuild(): void
    {
        $doc = $this->html5->parse('<!DOCTYPE html><html><head><title>t</title></head><body><a data-cms-id="cms-aaaaaaaaaaaa" data-cms-hover="color: red">x</a></body></html>');
        $this->renderer->apply($doc);
        $this->renderer->apply($doc); // second run must not duplicate the block
        $out = $this->html5->serialize($doc);
        $this->assertSame(1, substr_count($out, 'ef-state-styles'));
    }

    public function test_removed_hover_strips_block(): void
    {
        $doc = $this->html5->parse('<!DOCTYPE html><html><head><title>t</title></head><body><a data-cms-id="cms-aaaaaaaaaaaa" data-cms-hover="color: red">x</a></body></html>');
        $this->renderer->apply($doc);
        // simulate the hover being cleared, then re-render
        $a = (new \DOMXPath($doc))->query('//a')->item(0);
        $a->removeAttribute('data-cms-hover');
        $this->renderer->apply($doc);
        $this->assertStringNotContainsString('ef-state-styles', $this->html5->serialize($doc));
    }

    public function test_element_without_id_is_skipped(): void
    {
        $out = $this->render('<a data-cms-hover="color: red">x</a>');
        $this->assertStringNotContainsString('ef-state-styles', $out);
    }

    public function test_dangerous_declaration_dropped(): void
    {
        $out = $this->render('<a data-cms-id="cms-aaaaaaaaaaaa" data-cms-hover="color: red; background: url(javascript:alert(1))">x</a>');
        $this->assertStringContainsString('color: red', $out);
        $this->assertStringNotContainsString('javascript', $out);
    }
}
