<?php

declare(strict_types=1);

namespace EditFront\Tests\Document;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use PHPUnit\Framework\TestCase;

final class AnnotatorTest extends TestCase
{
    private Html5 $html5;
    private Annotator $annotator;

    protected function setUp(): void
    {
        $this->html5 = new Html5();
        $this->annotator = new Annotator();
    }

    private function fixtureDoc(): \DOMDocument
    {
        return $this->html5->parse((string) file_get_contents(dirname(__DIR__) . '/fixtures/page.html'));
    }

    public function test_stamps_ids_on_pristine_document(): void
    {
        $doc = $this->fixtureDoc();
        $changed = $this->annotator->ensureAnnotated($doc);
        $this->assertTrue($changed);

        // header, nav, a, main, h1, p, b, img — protected div subtree and script skipped
        $ids = $this->annotator->collectIds($doc);
        $this->assertCount(8, $ids);
        foreach (array_keys($ids) as $id) {
            $this->assertMatchesRegularExpression(Annotator::ID_PATTERN, $id);
        }
    }

    public function test_is_idempotent(): void
    {
        $doc = $this->fixtureDoc();
        $this->annotator->ensureAnnotated($doc);
        $idsBefore = array_keys($this->annotator->collectIds($doc));

        $changedAgain = $this->annotator->ensureAnnotated($doc);
        $this->assertFalse($changedAgain, 'second pass must be a no-op');
        $this->assertSame($idsBefore, array_keys($this->annotator->collectIds($doc)));
    }

    public function test_protected_tags_and_subtrees_are_skipped(): void
    {
        $doc = $this->fixtureDoc();
        $this->annotator->ensureAnnotated($doc);
        $xpath = new \DOMXPath($doc);

        $this->assertSame(0, $xpath->query('//script[@data-cms-id]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-cms-protected]//*[@data-cms-id]')->length);
        $this->assertSame(0, $xpath->query('//body[@data-cms-id]')->length);
    }

    public function test_valid_existing_id_is_preserved_invalid_replaced(): void
    {
        $doc = ef2_doc(
            '<p data-cms-id="cms-aaaaaaaaaaaa">keep</p>'
            . '<p data-cms-id="not-valid">replace</p>'
            . '<p data-cms-id="cms-aaaaaaaaaaaa">duplicate-replace</p>'
        );
        $this->annotator->ensureAnnotated($doc);

        $ps = $doc->getElementsByTagName('p');
        $this->assertSame('cms-aaaaaaaaaaaa', $ps->item(0)->getAttribute('data-cms-id'));
        $this->assertMatchesRegularExpression(Annotator::ID_PATTERN, $ps->item(1)->getAttribute('data-cms-id'));
        $this->assertNotSame('not-valid', $ps->item(1)->getAttribute('data-cms-id'));
        $this->assertNotSame('cms-aaaaaaaaaaaa', $ps->item(2)->getAttribute('data-cms-id'));
    }

    public function test_find_by_id(): void
    {
        $doc = ef2_doc('<p data-cms-id="cms-aaaaaaaaaaaa">x</p>');
        $el = $this->annotator->findById($doc, 'cms-aaaaaaaaaaaa');
        $this->assertNotNull($el);
        $this->assertSame('p', $el->tagName);
        $this->assertNull($this->annotator->findById($doc, 'cms-000000000000'));
        $this->assertNull($this->annotator->findById($doc, "evil' or '1"));
    }

    public function test_is_editable(): void
    {
        $doc = ef2_doc('<div data-cms-protected="true"><p id="inside">x</p></div><p id="free">y</p>');
        $xpath = new \DOMXPath($doc);
        $inside = $xpath->query('//p[@id="inside"]')->item(0);
        $free = $xpath->query('//p[@id="free"]')->item(0);
        $body = $doc->getElementsByTagName('body')->item(0);

        $this->assertFalse($this->annotator->isEditable($inside));
        $this->assertTrue($this->annotator->isEditable($free));
        $this->assertFalse($this->annotator->isEditable($body));
    }

    /* --- protection must hold against the ancestor, not just the node ------ */

    public function test_contains_protected_sees_marked_descendant(): void
    {
        $doc = ef2_doc('<div id="holder"><p>text <span data-cms-protected="true">auto</span></p></div>');
        $holder = $doc->getElementById('holder');
        $this->assertInstanceOf(\DOMElement::class, $holder);
        $this->assertTrue($this->annotator->containsProtected($holder));

        $p = $doc->getElementsByTagName('p')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $p);
        $this->assertTrue($this->annotator->containsProtected($p));
    }

    public function test_contains_protected_sees_protected_tag(): void
    {
        $doc = ef2_doc('<div id="holder"><p>x</p><script>var a = 1;</script></div>');
        $holder = $doc->getElementById('holder');
        $this->assertInstanceOf(\DOMElement::class, $holder);
        $this->assertTrue($this->annotator->containsProtected($holder));
    }

    public function test_contains_protected_is_false_for_the_protected_node_itself(): void
    {
        $doc = ef2_doc('<div data-cms-protected="true" id="auto">12:00:00</div><p id="free">y</p>');
        $auto = $doc->getElementById('auto');
        $free = $doc->getElementById('free');
        $this->assertInstanceOf(\DOMElement::class, $auto);
        $this->assertInstanceOf(\DOMElement::class, $free);
        // it IS protected, but it holds nothing protected — a text edit of the
        // node is refused by isEditable(), not by this check
        $this->assertFalse($this->annotator->containsProtected($auto));
        $this->assertFalse($this->annotator->isEditable($auto));
        $this->assertFalse($this->annotator->containsProtected($free));
    }

    public function test_count_protected_counts_body_only(): void
    {
        $doc = ef2_doc(
            '<div data-cms-protected="true">a</div>'
            . '<p><span data-cms-protected="true">b</span></p>'
            . '<script>x</script>'
        );
        // two marked nodes + one script in <body>; <title> in <head> is not counted
        $this->assertSame(3, $this->annotator->countProtected($doc));
    }
}
