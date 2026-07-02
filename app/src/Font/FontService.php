<?php

declare(strict_types=1);

namespace EditFront\Font;

use EditFront\Http\UrlHelper;
use EditFront\Support\Config;

/**
 * Self-hosted web fonts (§ fonts). UPLOADED font files are stored content-
 * addressable in {siteRoot}/fonts/ — a web-served directory OUTSIDE cms/, so the
 * live static pages keep these fonts even if the CMS is removed (drop-in §3.3).
 * A small registry (storage/fonts.json, private) maps a family name → file.
 *
 * BUILT-IN PRESETS (Inter, Roboto, …) ship inside the CMS bundle at
 * cms/assets/fonts/ and are referenced via asset() URLs. Tradeoff: presets are a
 * CMS convenience — if the CMS directory is removed, preset-styled text falls
 * back to a system font (graceful). Only uploaded fonts are CMS-independent.
 *
 * One uploaded file == one family (the admin names it). The file type is decided
 * from the real magic bytes, never the client extension. The @font-face block is
 * emitted by FontFaceRenderer from presets + this registry on every save/preview.
 */
final class FontService
{
    private const FONTS_REL = 'fonts';
    private const MAX_BYTES = 8 * 1024 * 1024; // 8 MB — covers ttf/otf, plenty for woff2
    private const MAX_FONTS = 100;
    private const FAMILY_RE = '/^[A-Za-z0-9][A-Za-z0-9 _-]{0,40}$/';

    /** magic-byte signature → [stored extension, CSS format()] */
    private const SIGNATURES = [
        'wOF2' => ['woff2', 'woff2'],
        'wOFF' => ['woff', 'woff'],
        'OTTO' => ['otf', 'opentype'],
        "\x00\x01\x00\x00" => ['ttf', 'truetype'],
        'true' => ['ttf', 'truetype'],
        'ttcf' => ['ttf', 'truetype'],
    ];

    /**
     * Built-in self-hosted fonts with full Cyrillic + Latin coverage, shipped in
     * cms/assets/fonts/ (OFL/Apache). Always available in the editor picker; not
     * user-deletable. Each has a -latin.woff2 + -cyrillic.woff2 subset, emitted
     * with unicode-range so only the needed bytes download.
     * @var list<array{family: string, slug: string}>
     */
    private const PRESETS = [
        ['family' => 'Inter', 'slug' => 'inter'],
        ['family' => 'Roboto', 'slug' => 'roboto'],
        ['family' => 'Open Sans', 'slug' => 'open-sans'],
        ['family' => 'Montserrat', 'slug' => 'montserrat'],
        ['family' => 'Nunito', 'slug' => 'nunito'],
        ['family' => 'PT Sans', 'slug' => 'pt-sans'],
        ['family' => 'Lora', 'slug' => 'lora'],
        ['family' => 'PT Serif', 'slug' => 'pt-serif'],
        ['family' => 'Merriweather', 'slug' => 'merriweather'],
        ['family' => 'JetBrains Mono', 'slug' => 'jetbrains-mono'],
    ];

    private const RANGE_LATIN = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,'
        . 'U+0304,U+0308,U+0329,U+2000-206F,U+2074,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
    private const RANGE_CYRILLIC = 'U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116';

    public function __construct(
        private readonly Config $config,
        private readonly UrlHelper $url,
    ) {
    }

    /**
     * Validate + store a font file under a (unique) family name.
     * @return array{family: string, file: string, format: string, size: int}
     */
    public function store(string $content, string $family): array
    {
        $family = trim($family);
        if (preg_match(self::FAMILY_RE, $family) !== 1) {
            throw new FontException('invalid family name (letters, digits, spaces, - and _; up to 41 chars)', 422);
        }
        if ($content === '') {
            throw new FontException('empty file', 422);
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new FontException('file too large (max ' . (int) (self::MAX_BYTES / 1048576) . ' MB)', 413);
        }

        [$ext, $format] = $this->detect($content);

        // reject names that clash with a built-in preset — otherwise two @font-face
        // declarations (preset + upload) would fight over the same family name
        foreach (self::PRESETS as $p) {
            if (strcasecmp($p['family'], $family) === 0) {
                throw new FontException('a font with this name already exists', 409);
            }
        }

        $registry = $this->registry();
        if (count($registry) >= self::MAX_FONTS) {
            throw new FontException('too many fonts', 422);
        }
        foreach ($registry as $entry) {
            if (strcasecmp((string) ($entry['family'] ?? ''), $family) === 0) {
                throw new FontException('a font with this name already exists', 409);
            }
        }

        $hash = substr(hash('sha256', $content), 0, 16);
        $file = $hash . '.' . $ext;
        $this->writeFontFile($file, $content);

        $entry = ['family' => $family, 'file' => $file, 'format' => $format, 'size' => strlen($content)];
        $registry[] = $entry;
        $this->saveRegistry($registry);

        return $entry;
    }

    /** @return list<array{family: string, file: string, format: string, size: int, url: string}> */
    public function list(): array
    {
        $out = [];
        foreach ($this->registry() as $entry) {
            if (!is_array($entry) || !is_string($entry['file'] ?? null)) {
                continue;
            }
            $out[] = [
                'family' => (string) ($entry['family'] ?? ''),
                'file' => (string) $entry['file'],
                'format' => (string) ($entry['format'] ?? 'woff2'),
                'size' => (int) ($entry['size'] ?? 0),
                'url' => $this->url->siteUrl(self::FONTS_REL . '/' . $entry['file']),
            ];
        }
        return $out;
    }

    /** @return list<string> uploaded family names, for the editor font picker */
    public function families(): array
    {
        $out = [];
        foreach ($this->registry() as $entry) {
            $fam = is_array($entry) ? (string) ($entry['family'] ?? '') : '';
            if ($fam !== '') {
                $out[] = $fam;
            }
        }
        return $out;
    }

    /**
     * Presets whose subset files are actually present in the CMS bundle. Gating
     * on file existence means a stripped/partial deploy never emits a broken
     * @font-face, and tests with no bundled fonts simply see zero presets.
     *
     * @return list<array{family: string, slug: string}>
     */
    private function activePresets(): array
    {
        $dir = $this->config->cmsDir() . '/assets/fonts';
        return array_values(array_filter(
            self::PRESETS,
            static fn (array $p): bool => is_file($dir . '/' . $p['slug'] . '-latin.woff2')
        ));
    }

    /** @return list<string> built-in font family names available in this deploy */
    public function presetFamilies(): array
    {
        return array_map(static fn (array $p): string => $p['family'], $this->activePresets());
    }

    public function presetCount(): int
    {
        return count($this->activePresets());
    }

    /**
     * @font-face rules for the managed <style>: built-in presets (2 subset rules
     * each, cms/assets/fonts via asset()) + uploaded fonts ({siteRoot}/fonts via
     * siteUrl()). Every available font is emitted so a font referenced ANY way —
     * editor inline style, the page's own stylesheet, the `font:` shorthand —
     * always has its backing @font-face (no fragile usage scan).
     *
     * @return list<string>
     */
    public function fontFaceRules(): array
    {
        $rules = [];
        foreach ($this->activePresets() as $p) {
            $rules[] = $this->faceRule($p['family'], $this->url->asset('fonts/' . $p['slug'] . '-cyrillic.woff2'), 'woff2', self::RANGE_CYRILLIC);
            $rules[] = $this->faceRule($p['family'], $this->url->asset('fonts/' . $p['slug'] . '-latin.woff2'), 'woff2', self::RANGE_LATIN);
        }
        foreach ($this->userFontFaceRules() as $rule) {
            $rules[] = $rule;
        }
        return $rules;
    }

    /** @return array<string, string> family → @font-face rule for uploaded fonts */
    public function userFontFaceRules(): array
    {
        $rules = [];
        foreach ($this->registry() as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $family = (string) ($entry['family'] ?? '');
            $file = (string) ($entry['file'] ?? '');
            $format = (string) ($entry['format'] ?? 'woff2');
            // defensive: re-validate before emitting CSS (registry could be hand-edited)
            if (preg_match(self::FAMILY_RE, $family) !== 1 || preg_match('/^[0-9a-f]{16}\.[a-z0-9]{2,5}$/', $file) !== 1) {
                continue;
            }
            $url = $this->url->siteUrl(self::FONTS_REL . '/' . $file);
            $rules[$family] = $this->faceRule($family, $url, $format, null);
        }
        return $rules;
    }

    private function faceRule(string $family, string $url, string $format, ?string $range): string
    {
        return "@font-face { font-family: '" . $family . "'; font-style: normal; font-weight: 400; "
            . "src: url('" . $url . "') format('" . $format . "'); "
            . ($range !== null ? 'unicode-range: ' . $range . '; ' : '')
            . 'font-display: swap; }';
    }

    public function delete(string $family): bool
    {
        $registry = $this->registry();
        $kept = [];
        $removedFile = null;
        foreach ($registry as $entry) {
            if (is_array($entry) && strcasecmp((string) ($entry['family'] ?? ''), $family) === 0) {
                $removedFile = (string) ($entry['file'] ?? '');
                continue;
            }
            $kept[] = $entry;
        }
        if ($removedFile === null) {
            return false;
        }
        $this->saveRegistry($kept);

        // delete the physical file only when no remaining family references it (dedup)
        $stillUsed = false;
        foreach ($kept as $entry) {
            if (is_array($entry) && (string) ($entry['file'] ?? '') === $removedFile) {
                $stillUsed = true;
                break;
            }
        }
        if (!$stillUsed && $removedFile !== '') {
            @unlink($this->fontsDir() . '/' . $removedFile);
        }
        return true;
    }

    /** @return list<array<string, mixed>> */
    private function registry(): array
    {
        $file = $this->registryFile();
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @return array{0: string, 1: string} [ext, format] */
    private function detect(string $content): array
    {
        $head = substr($content, 0, 4);
        if (isset(self::SIGNATURES[$head])) {
            return self::SIGNATURES[$head];
        }
        throw new FontException('unsupported font type (use woff2, woff, ttf or otf)', 415);
    }

    private function writeFontFile(string $file, string $content): void
    {
        $dir = $this->fontsDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new FontException('cannot create fonts directory');
        }
        $path = $dir . '/' . $file;
        if (is_file($path)) {
            return; // content-addressable: identical bytes already stored
        }
        $this->atomicWrite($path, $content, 0644);
    }

    /** @param list<array<string, mixed>> $registry */
    private function saveRegistry(array $registry): void
    {
        $dir = dirname($this->registryFile());
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new FontException('cannot create storage directory');
        }
        $this->atomicWrite(
            $this->registryFile(),
            (string) json_encode(array_values($registry), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            0640
        );
    }

    private function atomicWrite(string $file, string $content, int $mode): void
    {
        $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content) !== strlen($content)) {
            @unlink($tmp);
            throw new FontException('write failed');
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new FontException('move failed');
        }
    }

    private function fontsDir(): string
    {
        return $this->config->siteRoot() . '/' . self::FONTS_REL;
    }

    private function registryFile(): string
    {
        return $this->config->cmsDir() . '/storage/fonts.json';
    }
}
