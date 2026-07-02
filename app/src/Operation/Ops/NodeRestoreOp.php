<?php

declare(strict_types=1);

namespace EditFront\Operation\Ops;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\Operation\OperationApplyException;
use EditFront\Operation\OperationSpec;
use EditFront\Security\SanitizerHtml;

/**
 * node.restore — the inverse of node.delete (§7.4): re-insert previously
 * existing page content at {parentId, index}. The html goes through the
 * STRUCTURAL sanitizer; valid data-cms-id attributes survive so later ops
 * can still address the restored nodes.
 */
final class NodeRestoreOp implements OperationSpec
{
    public function __construct(
        private readonly Annotator $annotator,
        private readonly Html5 $html5,
        private readonly SanitizerHtml $sanitizer,
    ) {
    }

    public function key(): string
    {
        return 'node.restore';
    }

    public function schema(): array
    {
        return [
            'parentId' => ['type' => 'string', 'nullable' => true, 'required' => true, 'pattern' => Annotator::ID_PATTERN],
            'index' => ['type' => 'int', 'required' => true, 'min' => 0, 'max' => 100_000],
            'html' => ['type' => 'string', 'required' => true, 'maxLen' => 2_000_000],
        ];
    }

    public function sanitize(array $forward): array
    {
        return [
            'parentId' => $forward['parentId'] ?? null,
            'index' => $forward['index'] ?? 0,
            'html' => $this->sanitizer->sanitizeStructural((string) ($forward['html'] ?? '')),
        ];
    }

    public function apply(\DOMDocument $doc, ?\DOMElement $target, array $forward): void
    {
        $parentId = $forward['parentId'];
        if ($parentId === null) {
            $parent = $doc->getElementsByTagName('body')->item(0);
        } else {
            $parent = $this->annotator->findById($doc, (string) $parentId);
        }
        if (!$parent instanceof \DOMElement) {
            throw new OperationApplyException('restore parent not found');
        }
        if ($parent->tagName !== 'body' && !$this->annotator->isEditable($parent)) {
            throw new OperationApplyException('restore parent is protected');
        }

        $nodes = $this->html5->importFragment($doc, (string) $forward['html']);
        $root = null;
        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $root = $node;
                break;
            }
        }
        if ($root === null) {
            throw new OperationApplyException('restore html has no root element');
        }

        // restored ids must not collide with anything alive in the document
        $taken = $this->annotator->collectIds($doc);
        $restoredIds = [];
        if ($root->hasAttribute(Annotator::ID_ATTR)) {
            $restoredIds[] = $root->getAttribute(Annotator::ID_ATTR);
        }
        foreach ($root->getElementsByTagName('*') as $el) {
            if ($el instanceof \DOMElement && $el->hasAttribute(Annotator::ID_ATTR)) {
                $restoredIds[] = $el->getAttribute(Annotator::ID_ATTR);
            }
        }
        foreach ($restoredIds as $id) {
            if (isset($taken[$id])) {
                throw new OperationApplyException('restore id already in use: ' . $id);
            }
        }

        $elements = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $elements[] = $child;
            }
        }
        $index = max(0, min((int) $forward['index'], count($elements)));
        $parent->insertBefore($root, $elements[$index] ?? null);
    }

    public function targetRequired(): bool
    {
        return false;
    }

    public function echoMode(): string
    {
        return 'none';
    }
}
