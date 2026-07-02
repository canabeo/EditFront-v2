<?php

declare(strict_types=1);

namespace EditFront\Tests\I18n;

use EditFront\I18n\Translator;
use PHPUnit\Framework\TestCase;

/** §8.2/§8.3 — flat-key merge (user ← builtin/locale ← builtin/en baseline) + t(). */
final class TranslatorTest extends TestCase
{
    public function test_real_dict_translates_both_languages(): void
    {
        $tr = ef2_translator();
        $this->assertSame('Save', $tr->t('common.save', null, null, 'en'));
        $this->assertSame('Сохранить', $tr->t('common.save', null, null, 'ru'));
    }

    public function test_params_replaced(): void
    {
        $tr = ef2_translator();
        $this->assertSame('3 pages · /var/www', $tr->t('dashboard.count', ['n' => 3, 'root' => '/var/www'], null, 'en'));
    }

    public function test_fallback_then_key(): void
    {
        $tr = ef2_translator();
        $this->assertSame('FB', $tr->t('no.such.key', null, 'FB'));
        $this->assertSame('no.such.key', $tr->t('no.such.key'));
    }

    public function test_supported_includes_en_and_ru(): void
    {
        $sup = ef2_translator()->supportedLanguages();
        $this->assertContains('en', $sup);
        $this->assertContains('ru', $sup);
    }

    public function test_set_current_clamps_unknown_to_default(): void
    {
        $tr = ef2_translator();
        $tr->setCurrent('zz');
        $this->assertSame('en', $tr->current());
        $tr->setCurrent('ru');
        $this->assertSame('ru', $tr->current());
    }

    public function test_merge_precedence_user_over_builtin_over_baseline(): void
    {
        $cms = ef2_temp_dir('i18n-cms');
        mkdir($cms . '/lang/builtin', 0777, true);
        mkdir($cms . '/lang/user', 0777, true);
        file_put_contents($cms . '/lang/builtin/en.json', json_encode(['a' => 'EN-A', 'b' => 'EN-B']));
        file_put_contents($cms . '/lang/builtin/ru.json', json_encode(['a' => 'RU-A'])); // 'b' missing
        file_put_contents($cms . '/lang/user/ru.json', json_encode(['a' => 'USER-RU-A']));

        $tr = ef2_translator(['cms_dir' => $cms]);
        $this->assertSame('USER-RU-A', $tr->t('a', null, null, 'ru')); // user override wins
        $this->assertSame('EN-B', $tr->t('b', null, null, 'ru'));      // baseline English fallback, not raw key
    }

    public function test_bad_keys_are_dropped_on_load(): void
    {
        $cms = ef2_temp_dir('i18n-bad');
        mkdir($cms . '/lang/builtin', 0777, true);
        // 'Bad Key' has spaces/caps → rejected by the key regex; 'ok.key' kept
        file_put_contents($cms . '/lang/builtin/en.json', json_encode(['ok.key' => 'fine', 'Bad Key' => 'x']));
        $tr = ef2_translator(['cms_dir' => $cms]);
        $dict = json_decode($tr->dictJson('en'), true);
        $this->assertArrayHasKey('ok.key', $dict);
        $this->assertArrayNotHasKey('Bad Key', $dict);
    }
}
