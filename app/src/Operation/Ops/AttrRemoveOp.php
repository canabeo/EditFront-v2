<?php

declare(strict_types=1);

namespace EditFront\Operation\Ops;

use EditFront\Operation\OperationSpec;
use EditFront\Operation\OperationValidationException;

/** attr.remove — drop one attribute. */
final class AttrRemoveOp implements OperationSpec
{
    public function key(): string
    {
        return 'attr.remove';
    }

    public function schema(): array
    {
        return [
            'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[a-zA-Z][a-zA-Z0-9-]{0,49}$/'],
        ];
    }

    public function sanitize(array $forward): array
    {
        $name = strtolower((string) ($forward['name'] ?? ''));
        if (str_starts_with($name, 'data-cms-')) {
            throw new OperationValidationException('reserved attribute: ' . $name);
        }
        return ['name' => $name];
    }

    public function apply(\DOMDocument $doc, ?\DOMElement $target, array $forward): void
    {
        assert($target !== null);
        $target->removeAttribute((string) $forward['name']);
    }

    public function targetRequired(): bool
    {
        return true;
    }

    public function echoMode(): string
    {
        return 'full';
    }
}
