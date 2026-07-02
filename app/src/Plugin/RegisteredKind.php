<?php

declare(strict_types=1);

namespace EditFront\Plugin;

/**
 * A live, validated element type: the declarative manifest kind-spec bundled
 * with its loaded BlockKind instance and a compiled PropsSchema. The single
 * thing ElementTypeRegistry hands out (§6.1).
 */
final class RegisteredKind
{
    private readonly PropsSchema $schema;

    /** @param array<string, mixed> $manifestKind one entry from manifest.kinds */
    public function __construct(
        private readonly string $slug,
        private readonly string $trust,
        private readonly array $manifestKind,
        private readonly BlockKind $block,
    ) {
        $this->schema = new PropsSchema((array) ($manifestKind['props_schema'] ?? []));
    }

    public function kind(): string
    {
        return (string) $this->manifestKind['kind'];
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function trust(): string
    {
        return $this->trust;
    }

    public function version(): int
    {
        return (int) ($this->manifestKind['version'] ?? 1);
    }

    public function label(): string
    {
        return (string) ($this->manifestKind['label'] ?? $this->kind());
    }

    public function needsRuntime(): bool
    {
        return (bool) ($this->manifestKind['needs_runtime'] ?? false);
    }

    public function block(): BlockKind
    {
        return $this->block;
    }

    public function propsSchema(): PropsSchema
    {
        return $this->schema;
    }

    /** @return array<string, mixed> */
    public function manifestKind(): array
    {
        return $this->manifestKind;
    }
}
