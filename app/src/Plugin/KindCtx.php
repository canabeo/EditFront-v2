<?php

declare(strict_types=1);

namespace EditFront\Plugin;

/**
 * Context handed to BlockKind::render() (§6.3). Pure read-only helpers — a
 * kind never reaches the network or filesystem through it. assetUrl() resolves
 * a plugin-relative asset to a public URL; t() looks up a plugin i18n key.
 */
final class KindCtx
{
    /**
     * @param callable(string): string $assetResolver plugin-relative path → public URL
     * @param callable(string): string $translator    i18n key → string
     */
    public function __construct(
        private readonly string $slug,
        private readonly string $trustLevel,
        private readonly string $pageRelPath,
        private readonly mixed $assetResolver,
        private readonly mixed $translator,
    ) {
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function trustLevel(): string
    {
        return $this->trustLevel;
    }

    public function pageRelPath(): string
    {
        return $this->pageRelPath;
    }

    public function assetUrl(string $relPath): string
    {
        return ($this->assetResolver)($relPath);
    }

    public function t(string $key): string
    {
        return ($this->translator)($key);
    }
}
