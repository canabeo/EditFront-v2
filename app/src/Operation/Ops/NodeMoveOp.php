<?php

declare(strict_types=1);

namespace EditFront\Operation\Ops;

use EditFront\Document\Annotator;
use EditFront\Operation\OperationApplyException;
use EditFront\Operation\OperationSpec;

/**
 * node.move — position-stable move (§4.2): forward stores {parentId, index}
 * BEFORE and AFTER (parentId null = <body>). The index counts ELEMENT
 * children in the final arrangement. Handles cross-parent moves too.
 */
final class NodeMoveOp implements OperationSpec
{
    public function __construct(private readonly Annotator $annotator)
    {
    }

    public function key(): string
    {
        return 'node.move';
    }

    public function schema(): array
    {
        $place = [
            'type' => 'object',
            'fields' => [
                'parentId' => ['type' => 'string', 'nullable' => true, 'pattern' => Annotator::ID_PATTERN],
                'index' => ['type' => 'int', 'required' => true, 'min' => 0, 'max' => 100_000],
            ],
        ];
        return [
            'before' => $place + ['required' => true],
            'after' => $place + ['required' => true],
        ];
    }

    public function sanitize(array $forward): array
    {
        return $forward;
    }

    public function apply(\DOMDocument $doc, ?\DOMElement $target, array $forward): void
    {
        assert($target !== null);
        $after = (array) $forward['after'];
        $parentId = $after['parentId'] ?? null;

        if ($parentId === null) {
            $parent = $doc->getElementsByTagName('body')->item(0);
        } else {
            $parent = $this->annotator->findById($doc, (string) $parentId);
        }
        if (!$parent instanceof \DOMElement) {
            throw new OperationApplyException('move parent not found');
        }
        // cycle guard: cannot move a node into itself / its own subtree
        for ($n = $parent; $n !== null; $n = $n->parentNode) {
            if ($n === $target) {
                throw new OperationApplyException('cannot move node into its own subtree');
            }
        }

        $target->parentNode?->removeChild($target);

        $elements = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $elements[] = $child;
            }
        }
        $index = max(0, min((int) $after['index'], count($elements)));
        $ref = $elements[$index] ?? null;
        $parent->insertBefore($target, $ref);
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
