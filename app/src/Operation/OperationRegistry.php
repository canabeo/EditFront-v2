<?php

declare(strict_types=1);

namespace EditFront\Operation;

/**
 * Single registry of operation types (§4.2). Core ops register at boot,
 * plugin ops join the SAME registry in C3 — one save pipeline, one parity
 * test, no whitelist drift by design.
 */
final class OperationRegistry
{
    /** @var array<string, OperationSpec> */
    private array $specs = [];

    public function register(OperationSpec $spec): void
    {
        $key = $spec->key();
        if (isset($this->specs[$key])) {
            throw new \LogicException('operation already registered: ' . $key);
        }
        $this->specs[$key] = $spec;
    }

    public function get(string $key): ?OperationSpec
    {
        return $this->specs[$key] ?? null;
    }

    /** @return array<string, OperationSpec> */
    public function all(): array
    {
        return $this->specs;
    }

    /** Serializable manifest for GET /api/op-schema. @return array<string, mixed> */
    public function manifest(): array
    {
        $out = [];
        foreach ($this->specs as $key => $spec) {
            $out[$key] = [
                'schema' => $spec->schema(),
                'target_required' => $spec->targetRequired(),
                'echo' => $spec->echoMode(),
            ];
        }
        return $out;
    }
}
