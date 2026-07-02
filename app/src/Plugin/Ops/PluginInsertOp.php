<?php

declare(strict_types=1);

namespace EditFront\Plugin\Ops;

use EditFront\Document\Annotator;
use EditFront\Operation\OperationApplyException;
use EditFront\Operation\OperationSpec;
use EditFront\Plugin\ElementTypeRegistry;
use EditFront\Plugin\KindRenderer;
use EditFront\Plugin\PluginException;

/**
 * plugin.<slug>.insert — insert a plugin block of one of this plugin's kinds
 * (§6.4). The node is rendered server-side from (optional) starting props
 * through the kind's render() + core sanitize, then placed relative to a
 * reference node. Inserts only this plugin's own kinds.
 */
final class PluginInsertOp implements OperationSpec
{
    private const POSITIONS = ['before', 'after', 'inside-first', 'inside-last'];
    private const VOID_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'source', 'track', 'wbr',
    ];

    public function __construct(
        private readonly string $slug,
        private readonly ElementTypeRegistry $types,
        private readonly KindRenderer $renderer,
        private readonly Annotator $annotator,
    ) {
    }

    public function key(): string
    {
        return 'plugin.' . $this->slug . '.insert';
    }

    public function schema(): array
    {
        return [
            'kind' => ['type' => 'string', 'required' => true, 'pattern' => '/^[a-z][a-z0-9-]{1,39}$/'],
            'refId' => ['type' => 'string', 'required' => true, 'pattern' => Annotator::ID_PATTERN],
            'position' => ['type' => 'enum', 'required' => true, 'values' => self::POSITIONS],
            'newId' => ['type' => 'string', 'required' => true, 'pattern' => Annotator::ID_PATTERN],
            'props' => ['type' => 'any', 'nullable' => true],
        ];
    }

    public function sanitize(array $forward): array
    {
        return $forward; // kind/props validated against the kind schema in apply()
    }

    public function apply(\DOMDocument $doc, ?\DOMElement $target, array $forward): void
    {
        $kindName = (string) ($forward['kind'] ?? '');
        $rk = $this->types->get($kindName);
        if ($rk === null || $rk->slug() !== $this->slug) {
            throw new OperationApplyException('unknown kind for plugin ' . $this->slug . ': ' . $kindName);
        }

        $schema = $rk->propsSchema();
        $props = $schema->defaults();
        $given = $forward['props'] ?? null;
        if (is_array($given)) {
            // merge given over defaults so partial props are allowed
            $props = array_replace($props, $given);
        }
        $errors = $schema->validate($props);
        if ($errors !== []) {
            throw new OperationApplyException('invalid starting props: ' . implode('; ', $errors));
        }

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

        try {
            $inner = $this->renderer->renderInner($rk, $props);
            $node = $this->renderer->buildBlock($doc, $rk, $newId, $inner);
        } catch (PluginException $e) {
            throw new OperationApplyException('render failed: ' . $e->getMessage());
        }

        $parent = $ref->parentNode;
        switch ($position) {
            case 'before':
                $parent?->insertBefore($node, $ref);
                break;
            case 'after':
                $parent?->insertBefore($node, $ref->nextSibling);
                break;
            case 'inside-first':
                $ref->insertBefore($node, $ref->firstChild);
                break;
            case 'inside-last':
                $ref->appendChild($node);
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
