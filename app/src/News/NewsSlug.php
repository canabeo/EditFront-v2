<?php

declare(strict_types=1);

namespace EditFront\News;

use EditFront\Storage\PagesIndex;

/**
 * Russian→Latin transliteration + URL slug normalization + uniqueness.
 *
 * Uniqueness is checked against BOTH the caller-supplied item slugs and the
 * existing site pages (a '<slug>.html' file on disk). Collisions append a
 * numeric suffix: base, base-2, base-3, …
 */
final class NewsSlug
{
    private const FALLBACK = 'news';

    /** Lowercase Cyrillic → Latin. ъ/ь map to '' (dropped). */
    private const TRANSLIT = [
        'а' => 'a',  'б' => 'b',  'в' => 'v',  'г' => 'g',  'д' => 'd',
        'е' => 'e',  'ё' => 'e',  'ж' => 'zh', 'з' => 'z',  'и' => 'i',
        'й' => 'y',  'к' => 'k',  'л' => 'l',  'м' => 'm',  'н' => 'n',
        'о' => 'o',  'п' => 'p',  'р' => 'r',  'с' => 's',  'т' => 't',
        'у' => 'u',  'ф' => 'f',  'х' => 'h',  'ц' => 'c',  'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',  'ы' => 'y',  'ь' => '',
        'э' => 'e',  'ю' => 'yu', 'я' => 'ya',
    ];

    public function __construct(private readonly PagesIndex $pages)
    {
    }

    /** ru→lat translit, normalize, lower-kebab. Never empty (falls back to 'news'). */
    public function slugify(string $title): string
    {
        // lowercase first so the table only needs lowercase keys (mb-aware)
        $lower = mb_strtolower($title, 'UTF-8');
        $lat   = strtr($lower, self::TRANSLIT);

        // elide apostrophes so "What's" => "whats" (NOT "what-s"); covers the
        // ASCII quote plus the common typographic ' ' ` variants.
        $lat = str_replace(["'", "\u{2019}", "\u{2018}", '`'], '', $lat);

        // keep ASCII letters/digits; everything else becomes a separator
        $lat = preg_replace('/[^a-z0-9]+/u', '-', $lat) ?? '';
        $lat = trim($lat, '-');

        return $lat !== '' ? $lat : self::FALLBACK;
    }

    /**
     * Return a slug that collides with neither $existingSlugs nor an existing
     * '<slug>.html' site page. Appends -2, -3, … on collision.
     *
     * @param string             $base          a base slug (already slugified)
     * @param list<string>       $existingSlugs slugs taken by other items
     * @param string|null        $ignoreId      reserved for future slug-change (unused in MVP)
     */
    public function unique(string $base, array $existingSlugs, ?string $ignoreId = null): string
    {
        $base = $base !== '' ? $base : self::FALLBACK;

        $taken = [];
        foreach ($existingSlugs as $slug) {
            if (is_string($slug) && $slug !== '') {
                $taken[$slug] = true;
            }
        }
        foreach ($this->pages->list() as $page) {
            $path = (string) ($page['path'] ?? '');
            // map a top-level '<slug>.html' (or '.htm') page back to its slug
            if (preg_match('#^([^/]+)\.html?$#', $path, $m) === 1) {
                $taken[$m[1]] = true;
            }
        }

        if (!isset($taken[$base])) {
            return $base;
        }
        for ($n = 2; ; $n++) {
            $candidate = $base . '-' . $n;
            if (!isset($taken[$candidate])) {
                return $candidate;
            }
        }
    }
}
