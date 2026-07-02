<?php

declare(strict_types=1);

namespace EditFront\Tests\Security;

use EditFront\Security\SanitizerUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SanitizerUrlTest extends TestCase
{
    private SanitizerUrl $urls;

    protected function setUp(): void
    {
        $this->urls = new SanitizerUrl();
    }

    /** @return iterable<string, array{string}> */
    public static function safeUrls(): iterable
    {
        yield 'relative' => ['/about.html'];
        yield 'relative dot' => ['./x.html'];
        yield 'anchor' => ['#section'];
        yield 'query' => ['?page=2'];
        yield 'http' => ['http://example.com/'];
        yield 'https' => ['https://example.com/'];
        yield 'mailto' => ['mailto:a@b.c'];
        yield 'tel' => ['tel:+371234'];
        yield 'bare name' => ['page.html'];
    }

    #[DataProvider('safeUrls')]
    public function test_accepts_safe(string $url): void
    {
        $this->assertSame($url, $this->urls->sanitize($url));
    }

    /** @return iterable<string, array{string}> */
    public static function badUrls(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'mixed case' => ['JaVaScRiPt:alert(1)'];
        yield 'tab trick' => ["java\tscript:alert(1)"];
        yield 'newline trick' => ["java\nscript:alert(1)"];
        yield 'vbscript' => ['vbscript:x'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'data text' => ['data:text/html,<script>1</script>'];
        yield 'blob' => ['blob:https://x'];
    }

    #[DataProvider('badUrls')]
    public function test_rejects_dangerous(string $url): void
    {
        $this->assertNull($this->urls->sanitize($url));
        $this->assertNull($this->urls->sanitize($url, true));
    }

    public function test_data_image_only_when_allowed(): void
    {
        $img = 'data:image/png;base64,AAAA';
        $this->assertNull($this->urls->sanitize($img));
        $this->assertSame($img, $this->urls->sanitize($img, true));
        $this->assertNull($this->urls->sanitize('data:application/pdf;base64,AAAA', true));
    }
}
