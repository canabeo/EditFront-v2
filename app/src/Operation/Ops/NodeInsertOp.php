<?php

declare(strict_types=1);

namespace EditFront\Operation\Ops;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\Operation\NodeTemplates;
use EditFront\Operation\OperationApplyException;
use EditFront\Operation\OperationSpec;
use EditFront\Operation\OperationValidationException;

/** node.insert — insert a built-in template relative to a reference node. */
final class NodeInsertOp implements OperationSpec
{
    public const POSITIONS = ['before', 'after', 'inside-first', 'inside-last'];

    private const VOID_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'source', 'track', 'wbr',
    ];

    public function __construct(
        private readonly NodeTemplates $templates,
        private readonly Annotator $annotator,
        private readonly Html5 $html5,
    ) {
    }

    public function key(): string
    {
        return 'node.insert';
    }

    public function schema(): array
    {
        return [
            'refId' => ['type' => 'string', 'required' => true, 'pattern' => Annotator::ID_PATTERN],
            'position' => ['type' => 'enum', 'required' => true, 'values' => self::POSITIONS],
            'template' => ['type' => 'enum', 'required' => true, 'values' => array_keys(NodeTemplates::TEMPLATES)],
            'newId' => ['type' => 'string', 'required' => true, 'pattern' => Annotator::ID_PATTERN],
        ];
    }

    public function sanitize(array $forward): array
    {
        return $forward;
    }

    public function apply(\DOMDocument $doc, ?\DOMElement $target, array $forward): void
    {
        $ref = $this->annotator->findById($doc, (string) $forward['refId']);
        if ($ref === null) {
            throw new OperationApplyException('insert ref not found: ' . $forward['refId']);
        }
        if (!$this->annotator->isEditable($ref)) {
            throw new OperationApplyException('insert ref is protected');
        }
        $position = (string) $forward['position'];
        if (
            in_array($position, ['inside-first', 'inside-last'], true)
            && in_array(strtolower($ref->tagName), self::VOID_TAGS, true)
        ) {
            throw new OperationApplyException('cannot insert inside a void element');
        }

        $taken = $this->annotator->collectIds($doc);
        $newId = (string) $forward['newId'];
        if (isset($taken[$newId])) {
            throw new OperationApplyException('newId already used: ' . $newId);
        }

        $html = (string) $this->templates->html((string) $forward['template']);
        $nodes = $this->html5->importFragment($doc, $html);
        $root = null;
        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $root = $node;
                break;
            }
        }
        if ($root === null) {
            throw new OperationApplyException('template has no root element');
        }
        $root->setAttribute(Annotator::ID_ATTR, $newId);
        $taken[$newId] = true;
        // nested editable elements get fresh server-side ids
        $this->annotator->assignFreshIds($root, $taken, false);

        $parent = $ref->parentNode;
        switch ($position) {
            case 'before':
                $parent?->insertBefore($root, $ref);
                break;
            case 'after':
                $parent?->insertBefore($root, $ref->nextSibling);
                break;
            case 'inside-first':
                $ref->insertBefore($root, $ref->firstChild);
                break;
            case 'inside-last':
                $ref->appendChild($root);
                break;
        }
    }

    public function targetRequired(): bool
    {
        return false; // reference travels in forward.refId
    }

    public function echoMode(): string
    {
        return 'none';
    }
}
