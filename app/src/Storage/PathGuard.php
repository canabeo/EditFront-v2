<?php

declare(strict_types=1);

namespace EditFront\Storage;

/**
 * Validates relative paths and resolves them inside a root directory.
 * Rejects traversal, null bytes, absolute paths, leading '-', backslashes,
 * and symlink escapes (via realpath containment).
 */
final class PathGuard
{
    public function assertSafeRelative(string $rel): void
    {
        if ($rel === '') {
            throw new InvalidPathException('empty path');
        }
        if (str_contains($rel, "\0") || str_contains($rel, '\\')) {
            throw new InvalidPathException('forbidden characters in path');
        }
        if ($rel[0] === '/' || $rel[0] === '-' || preg_match('~^[A-Za-z]:~', $rel) === 1) {
            throw new InvalidPathException('path must be relative: ' . $rel);
        }
        foreach (explode('/', $rel) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidPathException('forbidden path segment in: ' . $rel);
            }
        }
    }

    /**
     * Resolve $rel inside $root and return the absolute path.
     * With $mustExist=false the parent directory must still exist and be inside $root.
     */
    public function resolveWithin(string $root, string $rel, bool $mustExist = true): string
    {
        $this->assertSafeRelative($rel);

        $rootReal = realpath($root);
        if ($rootReal === false) {
            throw new StorageException('root does not exist: ' . $root);
        }

        $abs = $rootReal . '/' . $rel;

        if ($mustExist) {
            $real = realpath($abs);
            if ($real === false || !str_starts_with($real, $rootReal . '/')) {
                throw new InvalidPathException('path escapes root or does not exist: ' . $rel);
            }
            return $real;
        }

        $dirReal = realpath(dirname($abs));
        if ($dirReal === false || ($dirReal !== $rootReal && !str_starts_with($dirReal, $rootReal . '/'))) {
            throw new InvalidPathException('parent directory escapes root or does not exist: ' . $rel);
        }
        return $dirReal . '/' . basename($abs);
    }
}
