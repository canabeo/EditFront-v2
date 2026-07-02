<?php

declare(strict_types=1);

namespace EditFront\Tests\I18n;

use PHPUnit\Framework\TestCase;

/**
 * §8.4 CI parity lint: every builtin language has the SAME key set, plugin lang
 * pairs match, and every literal t('key') used in templates/JS exists in en.json
 * (no key falls through to a raw-key string at the normal locale, v1 lesson).
 */
final class I18nParityTest extends TestCase
{
    private function cmsDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, string> */
    private function load(string $path): array
    {
        $this->assertFileExists($path);
        $d = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($d, "invalid JSON: $path");
        return $d;
    }

    public function test_builtin_en_ru_have_identical_keys(): void
    {
        $en = array_keys($this->load($this->cmsDir() . '/lang/builtin/en.json'));
        $ru = array_keys($this->load($this->cmsDir() . '/lang/builtin/ru.json'));
        sort($en);
        sort($ru);
        $this->assertSame($en, $ru, 'builtin en.json and ru.json must have the same keys');
    }

    public function test_plugin_lang_pairs_match(): void
    {
        foreach (glob($this->cmsDir() . '/plugins/*/lang/en.json') ?: [] as $enFile) {
            $ruFile = dirname($enFile) . '/ru.json';
            if (!is_file($ruFile)) {
                continue; // en-only plugin is allowed
            }
            $en = array_keys($this->load($enFile));
            $ru = array_keys($this->load($ruFile));
            sort($en);
            sort($ru);
            $this->assertSame($en, $ru, "plugin lang mismatch: $enFile vs $ruFile");
        }
    }

    public function test_no_raw_key_in_templates_or_js(): void
    {
        $en = $this->load($this->cmsDir() . '/lang/builtin/en.json');
        $files = array_merge(
            glob($this->cmsDir() . '/app/templates/*.twig') ?: [],
            [$this->cmsDir() . '/assets/editor-shell.js', $this->cmsDir() . '/assets/preview-inject.js']
        );

        $missing = [];
        foreach ($files as $file) {
            $src = (string) @file_get_contents($file);
            // only COMPLETE literal first-arg calls: t('a.b.c') or t('a.b.c', ...) —
            // constructed keys like t('panel.type_' + x) are skipped (closing quote
            // is followed by '+', not ',' or ')')
            if (preg_match_all("/\\bt\\(\\s*'([a-z0-9][a-z0-9._-]*)'\\s*[,)]/", $src, $m) > 0) {
                foreach ($m[1] as $key) {
                    if (!array_key_exists($key, $en)) {
                        $missing[] = basename($file) . ' → ' . $key;
                    }
                }
            }
        }
        $this->assertSame([], $missing, "t() keys missing from en.json:\n" . implode("\n", $missing));
    }
}
