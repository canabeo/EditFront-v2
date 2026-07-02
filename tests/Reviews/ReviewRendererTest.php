<?php

declare(strict_types=1);

namespace EditFront\Tests\Reviews;

use EditFront\Reviews\ReviewRenderer;
use PHPUnit\Framework\TestCase;

final class ReviewRendererTest extends TestCase
{
    private function review(array $overrides = []): array
    {
        return array_merge([
            'id'      => 'r-00000001',
            'name'    => 'Александр',
            'country' => 'Эмираты',
            'text'    => 'Отличная компания.',
            'year'    => '2024',
            'status'  => 'approved',
        ], $overrides);
    }

    private function html(\DOMElement $el): string
    {
        return (string) $el->ownerDocument->saveHTML($el);
    }

    /** First descendant whose @class equals $cls, or null. */
    private function byClass(\DOMElement $el, string $cls): ?\DOMElement
    {
        $xp = new \DOMXPath($el->ownerDocument);
        $node = $xp->query(".//*[@class='$cls']", $el)->item(0);
        return $node instanceof \DOMElement ? $node : null;
    }

    public function test_page_mode_has_badge_with_country(): void
    {
        $card = (new ReviewRenderer())->renderCard($this->review(), 'page');
        $badge = $this->byClass($card, 'badge');
        $this->assertNotNull($badge);
        $this->assertSame('Эмираты', $badge->textContent);
        // page mode has no decorative quote mark
        $this->assertNull($this->byClass($card, 'mark'));
    }

    public function test_home_mode_has_mark_not_badge(): void
    {
        $card = (new ReviewRenderer())->renderCard($this->review(), 'home');
        $this->assertNotNull($this->byClass($card, 'mark'));
        $this->assertNull($this->byClass($card, 'badge'));
    }

    public function test_always_five_stars(): void
    {
        foreach (['page', 'home'] as $mode) {
            $card = (new ReviewRenderer())->renderCard($this->review(), $mode);
            $st = $this->byClass($card, 'st');
            $this->assertNotNull($st);
            $this->assertSame('★★★★★', $st->textContent);
        }
    }

    public function test_page_meta_is_year_home_meta_is_country_year(): void
    {
        $page = (new ReviewRenderer())->renderCard($this->review(), 'page');
        $home = (new ReviewRenderer())->renderCard($this->review(), 'home');

        $whoPage = $this->byClass($page, 'who');
        $whoHome = $this->byClass($home, 'who');

        // both carry the author name
        $this->assertStringContainsString('Александр', $whoPage->textContent);
        $this->assertStringContainsString('Александр', $whoHome->textContent);

        // page meta = year only (country already in the badge)
        $this->assertStringContainsString('2024', $whoPage->textContent);
        // home meta = "Country, Year" (no badge to carry the country)
        $this->assertStringContainsString('Эмираты, 2024', $whoHome->textContent);
    }

    public function test_avatar_initial_is_uppercase_first_letter(): void
    {
        $card = (new ReviewRenderer())->renderCard($this->review(['name' => 'екатерина']), 'page');
        $av = $this->byClass($card, 'av');
        $this->assertSame('Е', $av->textContent);
    }

    public function test_text_is_inert_no_markup_injection(): void
    {
        $card = (new ReviewRenderer())->renderCard(
            $this->review(['text' => 'Привет <b>мир</b> <img src=x onerror=alert(1)>']),
            'page'
        );
        $html = $this->html($card);
        // the angle brackets are escaped — no live <b>/<img> element is created
        // (the payload survives only as inert, escaped text inside the <p>).
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>мир</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringContainsString('Привет', $html);
    }

    public function test_empty_country_omits_badge_and_uses_year_only_on_home(): void
    {
        $page = (new ReviewRenderer())->renderCard($this->review(['country' => '']), 'page');
        $home = (new ReviewRenderer())->renderCard($this->review(['country' => '']), 'home');
        $this->assertNull($this->byClass($page, 'badge'));
        $whoHome = $this->byClass($home, 'who');
        $this->assertStringContainsString('2024', $whoHome->textContent);
    }

    public function test_home_truncates_long_text_page_keeps_full(): void
    {
        $long = trim(str_repeat('Очень длинный отзыв про путешествие в тёплые страны. ', 30));
        $home = (new ReviewRenderer())->renderCard($this->review(['text' => $long]), 'home');
        $page = (new ReviewRenderer())->renderCard($this->review(['text' => $long]), 'page');
        $homeP = $home->getElementsByTagName('p')->item(0)->textContent;
        $pageP = $page->getElementsByTagName('p')->item(0)->textContent;

        $this->assertLessThan(mb_strlen($long), mb_strlen($homeP));
        $this->assertLessThanOrEqual(225, mb_strlen($homeP)); // ~220 + ellipsis
        $this->assertStringEndsWith('…', $homeP);
        // page card is NOT truncated
        $this->assertStringContainsString($long, $pageP);
    }

    public function test_newlines_become_br(): void
    {
        $card = (new ReviewRenderer())->renderCard($this->review(['text' => "Первая строка\nВторая строка"]), 'page');
        $html = $this->html($card);
        $this->assertStringContainsString('<br', $html);
        $this->assertStringContainsString('Первая строка', $html);
        $this->assertStringContainsString('Вторая строка', $html);
    }
}
