<?php

declare(strict_types=1);

namespace EditFront\Operation\Ops;

use EditFront\Document\Annotator;
use EditFront\Operation\OperationApplyException;
use EditFront\Operation\OperationSpec;

/** node.duplicate — deep clone inserted after the original; the whole cloned subtree gets fresh ids. */
final class NodeDuplicateOp implements OperationSpec
{
    public function __construct(private readonly Annotator $annotator)
    {
    }

    public function key(): string
    {
        return 'node.duplicate';
    }

    public function schema(): array
    {
        return [
            'newId' => ['type' => 'string', 'required' => true, 'pattern' => Annotator::ID_PATTERN],
            // optional: ids the editor already assigned to the clone's editable
            // descendants (document order). Lets the duplicate's children keep the
            // same ids server-side so an edit made before saving still lands.
            'childIds' => [
                'type' => 'list',
                'nullable' => true,
                'maxItems' => 2000,
                'of' => ['type' => 'string', 'pattern' => Annotator::ID_PATTERN],
            ],
        ];
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
        $taken = $this->annotator->collectIds($doc);
        $newId = (string) $forward['newId'];
        if (isset($taken[$newId])) {
            throw new OperationApplyException('newId already used: ' . $newId);
        }

        // cloneNode (not sanitize) preserves data-cms-block on nested plugin
        // blocks — the structural sanitizer would strip it.
        $clone = $target->cloneNode(true);
        assert($clone instanceof \DOMElement);
        $clone->setAttribute(Annotator::ID_ATTR, $newId);
        $taken[$newId] = true;

        // Prefer the editor-supplied child ids so the duplicate's descendants
        // keep the exact ids the editor already shows; any mismatch (count /
        // bad pattern / collision) falls back to freshly minted ids.
        $childIds = $forward['childIds'] ?? null;
        if (!is_array($childIds) || !$this->applyChildIds($clone, $childIds, $taken)) {
            $this->annotator->assignFreshIds($clone, $taken, false);
        }

        $target->parentNode->insertBefore($clone, $target->nextSibling);
    }

    /**
     * Apply the editor's pre-assigned ids to the clone's editable descendants
     * (same document order as Annotator::editableDescendants). All-or-nothing:
     * returns false on any count/pattern/collision mismatch so the caller can
     * fall back to fresh ids.
     * @param list<string> $childIds
     * @param array<string, true> $taken (mutated only on success)
     */
    private function applyChildIds(\DOMElement $clone, array $childIds, array &$taken): bool
    {
        $targets = $this->annotator->editableDescendants($clone);
        if (count($targets) !== count($childIds)) {
            return false;
        }
        $seen = $taken;
        foreach ($childIds as $id) {
            if (!is_string($id) || !$this->annotator->isValidId($id) || isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
        }
        foreach ($targets as $i => $el) {
            $el->setAttribute(Annotator::ID_ATTR, $childIds[$i]);
            $taken[$childIds[$i]] = true;
        }
        return true;
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
