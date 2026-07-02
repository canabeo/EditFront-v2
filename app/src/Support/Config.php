<?php

declare(strict_types=1);

namespace EditFront\Support;

/**
 * Immutable value object over the config array. Injected by autowiring into
 * every class that needs configuration — no scalar constructor params, so the
 * container never needs a manual per-class factory (invariant 0.2.7).
 */
final class Config
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function basePath(): string
    {
        return (string) $this->values['base_path'];
    }

    public function siteRoot(): string
    {
        return (string) $this->values['site_root'];
    }

    public function cmsDir(): string
    {
        return (string) $this->values['cms_dir'];
    }

    public function storageDir(): string
    {
        return (string) $this->values['storage_dir'];
    }

    public function debug(): bool
    {
        return (bool) $this->values['debug'];
    }

    public function sessionName(): string
    {
        return (string) $this->values['session_name'];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
}
