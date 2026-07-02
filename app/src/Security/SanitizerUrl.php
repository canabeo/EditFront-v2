<?php

declare(strict_types=1);

namespace EditFront\Security;

/**
 * URL sanitizer shared by rich-text href, attr.set href/src etc.
 * Control chars (TAB/LF/CR) are stripped BEFORE the scheme check —
 * "java\tscript:" must not slip through (v1 audit lesson).
 */
final class SanitizerUrl
{
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** @return string|null sanitized URL or null when rejected */
    public function sanitize(string $url, bool $allowDataImage = false): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2000 || str_contains($url, "\0")) {
            return null;
        }

        // scheme detection on a control-char-free copy
        $probe = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $url));

        if (preg_match('~^([a-z][a-z0-9+.-]*):~', $probe, $m) === 1) {
            $scheme = $m[1];
            if (in_array($scheme, self::SAFE_SCHEMES, true)) {
                return $url;
            }
            if (
                $allowDataImage
                && $scheme === 'data'
                && preg_match('~^data:image/(png|jpe?g|gif|webp|avif|svg\+xml);~', $probe) === 1
            ) {
                return $url;
            }
            return null;
        }

        // schemeless: relative path, anchor, query — fine
        return $url;
    }
}
