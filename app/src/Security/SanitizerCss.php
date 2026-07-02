<?php

declare(strict_types=1);

namespace EditFront\Security;

/**
 * Inline-style declaration sanitizer for style.set: a single property + value.
 * Conservative: rejects anything smelling of CSS-injection (§13).
 */
final class SanitizerCss
{
    private const FORBIDDEN_SUBSTRINGS = [
        'expression(', 'javascript:', 'vbscript:', 'behavior:', '@import', '</', '<', '&#',
    ];

    public function validProp(string $prop): bool
    {
        return preg_match('/^[a-z][a-z-]{0,40}$/', $prop) === 1;
    }

    /** @return string|null sanitized value, '' allowed (= remove prop), null = rejected */
    public function value(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strlen($value) > 400 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f;{}\\\\]/', $value) === 1) {
            return null;
        }
        $probe = strtolower((string) preg_replace('/\s+/', '', $value));
        foreach (self::FORBIDDEN_SUBSTRINGS as $bad) {
            if (str_contains($probe, $bad)) {
                return null;
            }
        }
        // url(...) only with safe targets
        if (preg_match_all('~url\(\s*[\'"]?([^\'")]*)~i', $value, $m) > 0) {
            foreach ($m[1] as $target) {
                $t = strtolower(trim($target));
                if (
                    $t !== ''
                    && !str_starts_with($t, '/')
                    && !str_starts_with($t, './')
                    && !str_starts_with($t, '../')
                    && !str_starts_with($t, 'https://')
                    && !str_starts_with($t, 'http://')
                    && !str_starts_with($t, 'data:image/')
                ) {
                    return null;
                }
            }
        }
        return $value;
    }
}
