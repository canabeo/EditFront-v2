<?php

declare(strict_types=1);

namespace EditFront\Operation\Ops;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\Operation\OperationApplyException;
use EditFront\Operation\OperationSpec;
use EditFront\Security\SanitizerHtml;

/**
 * node.replace — swap the target element for new structural HTML while KEEPING
 * its data-cms-id. This is the block-level transform primitive the formatting
 * toolbar uses (paragraph ↔ heading ↔ quote ↔ list): text.set is innerHTML-only
 * and cannot change a tag, and nesting a block inside a phrasing host (<p><h2>)
 * is reparented by the HTML5 parser on the next open. node.replace changes the
 * host TAG cleanly so it round-trips.
 *
 * The new root inherits the target's id (identity preserved); descendant ids
 * that would collide with OTHER live nodes are stripped (re-annotated on next
 * open). The inverse — emitted client-side — is another node.replace back to
 * the captured original outerHTML.
 */
final class NodeReplaceOp implements OperationSpec
{
    public function __construct(
        private readonly Annotator $annotator,
        private readonly Html5 $html5,
        private readonly SanitizerHtml $sanitizer,
    ) {
    }

    public function key(): string
    {
        return 'node.replace';
    }

    public function schema(): array
    {
        return [
            'html' => ['type' => 'string', 'required' => true, 'maxLen' => 2_000_000],
        ];
    }

    public function sanitize(array $forward): array
    {
        return [
            'html' => $this->sanitizer->sanitizeStructural((string) ($forward['html'] ?? '')),
        ];
    }

    public function apply(\DOMDocument $doc, ?\DOMElement $target, array $forward): void
    {
        if ($target === null) {
            throw new OperationApplyException('node.replace requires a target');
        }
        $parent = $target->parentNode;
        if (!$parent instanceof \DOMNode) {
            throw new OperationApplyException('target has no parent');
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
            throw new OperationApplyException('replace html has no root element');
        }

        $id = $target->getAttribute(Annotator::ID_ATTR);
        if ($id !== '') {
            // identity preserved: the new tag keeps the same addressable id
            $root->setAttribute(Annotator::ID_ATTR, $id);
        }

        // descendant id collision: ids alive OUTSIDE the target subtree must not
        // be claimed by the replacement (the target subtree itself is being removed,
        // so its ids are free to reuse).
        $freed = $this->subtreeIds($target);
        $taken = $this->annotator->collectIds($doc);
        foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
            if (!$el instanceof \DOMElement || !$el->hasAttribute(Annotator::ID_ATTR)) {
                continue;
            }
            $descId = $el->getAttribute(Annotator::ID_ATTR);
            if (isset($taken[$descId]) && !isset($freed[$descId])) {
                $el->removeAttribute(Annotator::ID_ATTR);
            }
        }

        $parent->replaceChild($root, $target);
    }

    /** @return array<string, true> ids inside a subtree (incl. the root) */
    private function subtreeIds(\DOMElement $el): array
    {
        $ids = [];
        if ($el->hasAttribute(Annotator::ID_ATTR)) {
            $ids[$el->getAttribute(Annotator::ID_ATTR)] = true;
        }
        foreach ($el->getElementsByTagName('*') as $child) {
            if ($child instanceof \DOMElement && $child->hasAttribute(Annotator::ID_ATTR)) {
                $ids[$child->getAttribute(Annotator::ID_ATTR)] = true;
            }
        }
        return $ids;
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
