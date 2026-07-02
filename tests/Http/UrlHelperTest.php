<?php

declare(strict_types=1);

namespace EditFront\Tests\Http;

use EditFront\Http\UrlHelper;
use PHPUnit\Framework\TestCase;

final class UrlHelperTest extends TestCase
{
    private function helper(string $basePath): UrlHelper
    {
        return new UrlHelper(ef2_test_config(['base_path' => $basePath]));
    }

    public function test_path_with_subfolder_base(): void
    {
        $url = $this->helper('/cms');
        $this->assertSame('/cms/', $url->path(''));
        $this->assertSame('/cms/login', $url->path('login'));
        $this->assertSame('/cms/login', $url->path('/login'));
        $this->assertSame('/cms/api/save', $url->path('api/save'));
    }

    public function test_path_with_empty_base(): void
    {
        $url = $this->helper('');
        $this->assertSame('/', $url->path(''));
        $this->assertSame('/login', $url->path('login'));
    }

    public function test_cookie_path(): void
    {
        $this->assertSame('/cms', $this->helper('/cms')->cookiePath());
        $this->assertSame('/', $this->helper('')->cookiePath());
    }

    public function test_is_api_path(): void
    {
        $url = $this->helper('/cms');
        $this->assertTrue($url->isApiPath('/cms/api/save'));
        $this->assertFalse($url->isApiPath('/cms/login'));
        $this->assertFalse($url->isApiPath('/api/save'));
    }

    public function test_asset_has_cache_buster(): void
    {
        $url = $this->helper('/cms');
        $this->assertMatchesRegularExpression('~^/cms/assets/admin\.css\?v=\d+$~', $url->asset('admin.css'));
    }
}
