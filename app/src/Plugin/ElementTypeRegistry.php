<?php

declare(strict_types=1);

namespace EditFront\Plugin;

/**
 * Kind-name → RegisteredKind lookup (§6.1). Populated by PluginManager::boot();
 * read by the save props-sync, the orphan resolver and the controllers. The
 * core never hard-codes element types — they all arrive here from plugins
 * (base text/image/link types still live in the core ops, §16.9).
 */
final class ElementTypeRegistry
{
    /** @var array<string, RegisteredKind> */
    private array $kinds = [];

    public function register(RegisteredKind $kind): void
    {
        $name = $kind->kind();
        if (isset($this->kinds[$name])) {
            throw new PluginException('kind already registered: ' . $name);
        }
        $this->kinds[$name] = $kind;
    }

    public function get(string $kind): ?RegisteredKind
    {
        return $this->kinds[$kind] ?? null;
    }

    public function has(string $kind): bool
    {
        return isset($this->kinds[$kind]);
    }

    /** @return array<string, RegisteredKind> */
    public function all(): array
    {
        return $this->kinds;
    }
}
