<?php

declare(strict_types=1);

namespace EditFront\Plugin\Ops;

use EditFront\Operation\OperationApplyException;
use EditFront\Operation\OperationSpec;
use EditFront\Plugin\ElementTypeRegistry;
use EditFront\Plugin\KindRenderer;
use EditFront\Plugin\PluginException;
use EditFront\Plugin\PropsMutator;

/**
 * plugin.<slug>.{setprop|arrayinsert|arrayremove|arraymove} — a schema-driven
 * props edit (§6.4.1). The current props are read straight from the DOM via
 * the kind's serialize() (render↔serialize is a verified round-trip, §6.3),
 * mutated through PropsMutator (path validated against PropsSchema), then the
 * node is re-rendered. Pure DOM-self-contained: the sidecar is reconciled by
 * the save pipeline afterwards, so this op needs no page context.
 *
 * One class, four modes — the apply contract is identical, only the mutation
 * differs (keeps the registry from sprouting four near-identical specs).
 */
final class PluginMutateOp implements OperationSpec
{
    public function __construct(
        private readonly string $slug,
        private readonly string $mode,
        private readonly ElementTypeRegistry $types,
        private readonly PropsMutator $mutator,
        private readonly KindRenderer $renderer,
        private readonly \EditFront\Document\Html5 $html5,
    ) {
    }

    public function key(): string
    {
        return 'plugin.' . $this->slug . '.' . $this->mode;
    }

    public function schema(): array
    {
        $path = ['type' => 'string', 'required' => true, 'maxLen' => 200, 'pattern' => '/^[a-z][a-z0-9_.-]{0,200}$/i'];
        switch ($this->mode) {
            case 'setprop':
                return ['path' => $path, 'value' => ['type' => 'any', 'required' => true, 'nullable' => true]];
            case 'arrayinsert':
                return [
                    'path' => $path,
                    'index' => ['type' => 'int', 'required' => true, 'min' => 0, 'max' => 10000],
                    'item' => ['type' => 'any', 'nullable' => true],
                ];
            case 'arrayremove':
                return [
                    'path' => $path,
                    'index' => ['type' => 'int', 'required' => true, 'min' => 0, 'max' => 10000],
                ];
            case 'arraymove':
                return [
                    'path' => $path,
                    'from' => ['type' => 'int', 'required' => true, 'min' => 0, 'max' => 10000],
                    'to' => ['type' => 'int', 'required' => true, 'min' => 0, 'max' => 10000],
                ];
            default:
                return [];
        }
    }

    public function sanitize(array $forward): array
    {
        return $forward; // path/value validated against the kind schema in apply()
    }

    public function apply(\DOMDocument $doc, ?\DOMElement $target, array $forward): void
    {
        assert($target !== null);
        $kindName = $target->getAttribute('data-cms-block');
        $rk = $this->types->get($kindName);
        if ($rk === null || $rk->slug() !== $this->slug) {
            throw new OperationApplyException('target is not a ' . $this->slug . ' block');
        }

        try {
            $props = $rk->block()->serialize($target);
            $newProps = $this->mutator->apply($this->mode, $props, $forward, $rk->propsSchema());
        } catch (PluginException $e) {
            throw new OperationApplyException('props mutation rejected: ' . $e->getMessage());
        }

        $errors = $rk->propsSchema()->validate($newProps);
        if ($errors !== []) {
            throw new OperationApplyException('props invalid after mutation: ' . implode('; ', $errors));
        }

        try {
            $inner = $this->renderer->renderInner($rk, $newProps, $this->html5->innerHtml($target));
        } catch (PluginException $e) {
            throw new OperationApplyException('render failed: ' . $e->getMessage());
        }
        $this->renderer->setInner($doc, $target, $inner);
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
