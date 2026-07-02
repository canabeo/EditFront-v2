<?php

declare(strict_types=1);

namespace EditFront\Tests\I18n;

use EditFront\I18n\TranslationStore;
use PHPUnit\Framework\TestCase;

final class TranslationStoreTest extends TestCase
{
    private string $cms;
    private TranslationStore $store;

    protected function setUp(): void
    {
        $this->cms = ef2_temp_dir('tr-cms');
        @mkdir($this->cms . '/lang/builtin', 0777, true);
        @mkdir($this->cms . '/lang/user', 0777, true);
        file_put_contents($this->cms . '/lang/builtin/en.json', (string) json_encode([
            'a.x' => 'X', 'a.y' => 'Y', 'common.only_en' => 'EN',
        ]));
        file_put_contents($this->cms . '/lang/builtin/ru.json', (string) json_encode([
            'a.x' => 'Икс', 'a.y' => 'Игрек', 'common.only_en' => 'ОНЛИ',
        ], JSON_UNESCAPED_UNICODE));
        $this->store = new TranslationStore(ef2_test_config(['cms_dir' => $this->cms]));
    }

    public function test_baseline_merges_en_then_locale(): void
    {
        $base = $this->store->baseline('ru');
        $this->assertSame('Икс', $base['a.x']);
        $this->assertArrayHasKey('common.only_en', $base);
    }

    public function test_baseline_en_is_english(): void
    {
        $this->assertSame('X', $this->store->baseline('en')['a.x']);
    }

    public function test_save_keeps_only_changed_values(): void
    {
        $n = $this->store->saveOverrides('ru', [
            'a.x' => 'Новый Икс',     // changed → kept
            'a.y' => 'Игрек',          // == baseline → dropped
            'common.only_en' => '',    // empty → dropped
            'unknown.key' => 'zzz',    // unknown key → dropped
        ]);
        $this->assertSame(1, $n);
        $this->assertSame(['a.x' => 'Новый Икс'], $this->store->overrides('ru'));
    }

    public function test_clearing_back_to_baseline_deletes_file(): void
    {
        $this->store->saveOverrides('ru', ['a.x' => 'Custom']);
        $this->assertNotSame([], $this->store->overrides('ru'));
        $this->store->saveOverrides('ru', ['a.x' => 'Икс']); // equals baseline → removed
        $this->assertSame([], $this->store->overrides('ru'));
        $this->assertFileDoesNotExist($this->cms . '/lang/user/ru.json');
    }

    public function test_bad_locale_throws_on_save(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->store->saveOverrides('../evil', ['a.x' => 'z']);
    }

    public function test_overlong_value_is_dropped(): void
    {
        $this->assertSame(0, $this->store->saveOverrides('ru', ['a.x' => str_repeat('щ', 2001)]));
    }

    public function test_add_language_creates_empty_user_file(): void
    {
        $this->store->addLanguage('de');
        $this->assertFileExists($this->cms . '/lang/user/de.json');
        $this->assertSame([], $this->store->overrides('de'));
        // baseline for a brand-new language falls back to English
        $this->assertSame('X', $this->store->baseline('de')['a.x']);
    }

    public function test_add_language_rejects_existing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->store->addLanguage('ru'); // builtin already exists
    }

    public function test_add_language_rejects_bad_code(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->store->addLanguage('Deutsch-1');
    }

    public function test_empty_overrides_keep_file_for_user_added_language(): void
    {
        $this->store->addLanguage('de');
        $this->store->saveOverrides('de', ['a.x' => 'Iks']);
        $this->assertSame(['a.x' => 'Iks'], $this->store->overrides('de'));
        // clearing all overrides must NOT remove a user-added language
        $this->store->saveOverrides('de', ['a.x' => '']);
        $this->assertFileExists($this->cms . '/lang/user/de.json');
        $this->assertSame([], $this->store->overrides('de'));
    }
}
