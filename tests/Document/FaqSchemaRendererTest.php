<?php

declare(strict_types=1);

namespace EditFront\Tests\Document;

use EditFront\Document\FaqSchemaRenderer;
use EditFront\Document\Html5;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FaqSchemaRendererTest extends TestCase
{
    private FaqSchemaRenderer $renderer;
    private Html5 $html5;

    protected function setUp(): void
    {
        $this->renderer = new FaqSchemaRenderer(new NullLogger());
        $this->html5 = new Html5();
    }

    /** @param array<mixed> $graph */
    private function page(string $listHtml, array $graph): \DOMDocument
    {
        $json = json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this->html5->parse(
            '<!DOCTYPE html><html><head><title>t</title>'
            . '<script type="application/ld+json">' . $json . '</script>'
            . '</head><body>' . $listHtml . '</body></html>'
        );
    }

    /** @return array<mixed> */
    private function ldJson(\DOMDocument $doc): array
    {
        $script = (new \DOMXPath($doc))->query('//script[@type="application/ld+json"]')?->item(0);
        $this->assertInstanceOf(\DOMElement::class, $script);
        return (array) json_decode($script->textContent, true, 64, JSON_THROW_ON_ERROR);
    }

    private function faqGraph(string ...$questions): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Organization', 'name' => 'Алюм-Сервіс'],
                [
                    '@type' => ['WebPage', 'FAQPage'],
                    'mainEntity' => array_map(static fn (string $q): array => [
                        '@type' => 'Question',
                        'name' => $q,
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'stale'],
                    ], $questions),
                ],
            ],
        ];
    }

    public function test_rebuilds_questions_from_the_visible_markup(): void
    {
        $doc = $this->page(
            '<div data-cms-faq>'
            . '<div><h3>Яка гарантія?</h3><p>Десять років.</p></div>'
            . '<div><h3>Скільки коштує?</h3><p>Залежить від розміру.</p></div>'
            . '</div>',
            $this->faqGraph('Стара редакція питання')
        );

        $this->renderer->apply($doc);
        $faq = $this->ldJson($doc)['@graph'][1];

        $this->assertCount(2, $faq['mainEntity']);
        $this->assertSame('Яка гарантія?', $faq['mainEntity'][0]['name']);
        $this->assertSame('Десять років.', $faq['mainEntity'][0]['acceptedAnswer']['text']);
        $this->assertSame('Скільки коштує?', $faq['mainEntity'][1]['name']);
        $this->assertSame('Залежить від розміру.', $faq['mainEntity'][1]['acceptedAnswer']['text']);
        // the rest of the graph is untouched
        $this->assertSame('Алюм-Сервіс', $this->ldJson($doc)['@graph'][0]['name']);
    }

    public function test_answer_keeps_link_text_and_collapses_whitespace(): void
    {
        $doc = $this->page(
            "<div data-cms-faq>\n  <div>\n    <h3>Телефон?</h3>\n"
            . "    <p>Телефонуйте: <a href=\"tel:+380973608073\">+38 (097) 360-80-73</a>.</p>\n"
            . "  </div>\n</div>",
            $this->faqGraph('old')
        );

        $this->renderer->apply($doc);
        $entity = $this->ldJson($doc)['@graph'][1]['mainEntity'][0];
        $this->assertSame('Телефонуйте: +38 (097) 360-80-73.', $entity['acceptedAnswer']['text']);
    }

    public function test_unchanged_list_rewrites_nothing(): void
    {
        $doc = $this->page(
            '<div data-cms-faq><div><h3>Q</h3><p>A</p></div></div>',
            $this->faqGraph('Q')
        );
        // first pass writes the answer in
        $this->renderer->apply($doc);
        $before = $this->html5->serialize($doc);
        // second pass must be a no-op, byte for byte — a save may not churn the JSON
        $this->renderer->apply($doc);
        $this->assertSame($before, $this->html5->serialize($doc));
    }

    public function test_page_without_a_marked_list_is_left_alone(): void
    {
        $doc = $this->page(
            '<div class="faq"><div><h3>Q</h3><p>A</p></div></div>',
            $this->faqGraph('Питання, яке ніхто не чіпає')
        );
        $before = $this->html5->serialize($doc);
        $this->renderer->apply($doc);
        $this->assertSame($before, $this->html5->serialize($doc));
    }

    public function test_unparsable_json_ld_is_left_alone(): void
    {
        $doc = $this->html5->parse(
            '<!DOCTYPE html><html><head><title>t</title>'
            . '<script type="application/ld+json">{ not json }</script></head><body>'
            . '<div data-cms-faq><div><h3>Q</h3><p>A</p></div></div></body></html>'
        );
        $before = $this->html5->serialize($doc);
        $this->renderer->apply($doc);
        $this->assertSame($before, $this->html5->serialize($doc));
    }

    public function test_item_without_a_question_is_skipped(): void
    {
        $doc = $this->page(
            '<div data-cms-faq>'
            . '<div><p>просто абзац без заголовка</p></div>'
            . '<div><h3>Справжнє питання</h3><p>Відповідь.</p></div>'
            . '</div>',
            $this->faqGraph('old')
        );

        $this->renderer->apply($doc);
        $entities = $this->ldJson($doc)['@graph'][1]['mainEntity'];
        $this->assertCount(1, $entities);
        $this->assertSame('Справжнє питання', $entities[0]['name']);
    }

    public function test_explicit_question_marker_wins_over_the_heading(): void
    {
        $doc = $this->page(
            '<div data-cms-faq><div>'
            . '<h3>Службовий заголовок</h3>'
            . '<p data-cms-faq-q>Справжнє питання?</p>'
            . '<p>Відповідь.</p>'
            . '</div></div>',
            $this->faqGraph('old')
        );

        $this->renderer->apply($doc);
        $entity = $this->ldJson($doc)['@graph'][1]['mainEntity'][0];
        $this->assertSame('Справжнє питання?', $entity['name']);
        $this->assertStringContainsString('Службовий заголовок', $entity['acceptedAnswer']['text']);
    }

    public function test_flat_faqpage_without_a_graph_is_handled(): void
    {
        $doc = $this->page(
            '<div data-cms-faq><div><h3>Q1</h3><p>A1</p></div></div>',
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []]
        );

        $this->renderer->apply($doc);
        $data = $this->ldJson($doc);
        $this->assertCount(1, $data['mainEntity']);
        $this->assertSame('Q1', $data['mainEntity'][0]['name']);
    }

    public function test_markup_survives_the_read(): void
    {
        $html = '<div data-cms-faq><div><h3>Q</h3><p>A</p></div></div>';
        $doc = $this->page($html, $this->faqGraph('Q'));
        $this->renderer->apply($doc);
        // the question node was lifted out to read the answer — it must be back
        $this->assertStringContainsString('<h3>Q</h3><p>A</p>', $this->html5->serialize($doc));
    }
}
