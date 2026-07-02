<?php

declare(strict_types=1);

namespace EditFront\Operation;

/**
 * One operation type (§4.2). The schema() is the SINGLE source of the
 * forward-payload shape: the server validator rejects unknown keys, the
 * client fixtures are checked against it by OperationSchemaParityTest,
 * /api/op-schema serves it to the client. Adding an editing capability =
 * adding ONE OperationSpec. invert() arrives in C2.
 */
interface OperationSpec
{
    public function key(): string;

    /** @return array<string, array<string, mixed>> field schema for forward (SchemaValidator format) */
    public function schema(): array;

    /**
     * Normalize/sanitize the forward payload.
     * @param array<string, mixed> $forward
     * @return array<string, mixed>
     * @throws OperationValidationException on hard reject
     */
    public function sanitize(array $forward): array;

    /**
     * Mutate the document. $target is the resolved element for ops with
     * targetRequired(); null otherwise.
     * @param array<string, mixed> $forward
     * @throws OperationApplyException
     */
    public function apply(\DOMDocument $doc, ?\DOMElement $target, array $forward): void;

    public function targetRequired(): bool;

    /** preview echo policy: 'full' | 'skip-text' | 'none' (consumed by the client) */
    public function echoMode(): string;
}
