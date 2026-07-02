<?php

declare(strict_types=1);

namespace EditFront\Operation\Ops;

use EditFront\Operation\OperationApplyException;
use EditFront\Operation\OperationSpec;

/** node.delete — remove the target node (inverse carries the payload, C2). */
final class NodeDeleteOp implements OperationSpec
{
    public function key(): string
    {
        return 'node.delete';
    }

    public function schema(): array
    {
        return []; // no forward fields; any extra key is a validation error
    }

    public function sanitize(array $forward): array
    {
        return $forward;
    }

    public function apply(\DOMDocument $doc, ?\DOMElement $target, array $forward): void
    {
        assert($target !== null);
        if ($target->parentNode === null) {
            throw new OperationApplyException('node has no parent');
        }
        $target->parentNode->removeChild($target);
    }

    public function targetRequired(): bool
    {
        return true;
    }

    public function echoMode(): string
    {
        return 'none';
    }
}
