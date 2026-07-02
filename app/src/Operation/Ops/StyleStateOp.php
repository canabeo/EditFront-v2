<?php

declare(strict_types=1);

namespace EditFront\Operation\Ops;

use EditFront\Operation\OperationSpec;
use EditFront\Operation\OperationValidationException;
use EditFront\Security\SanitizerCss;

/**
 * style.state — one CSS property for a non-default element STATE (hover).
 * Inline styles can only express the base state, so state styles live in a
 * data-cms-hover declaration string on the element; StateCssRenderer turns
 * those into a managed <style>[data-cms-id="X"]:hover{...}</style> on save, so
 * the hover works on the live static page with no CMS (drop-in). Empty value
 * removes the property.
 */
final class StyleStateOp implements OperationSpec
{
    private const STATES = ['hover'];
    private const ATTR = 'data-cms-hover';

    public function __construct(private readonly SanitizerCss $css)
    {
    }

    public function key(): string
    {
        return 'style.state';
    }

    public function schema(): array
    {
        return [
            'state' => ['type' => 'string', 'required' => true, 'pattern' => '/^hover$/'],
            'prop' => ['type' => 'string', 'required' => true, 'pattern' => '/^[a-z][a-z-]{0,40}$/'],
            'value' => ['type' => 'string', 'required' => true, 'maxLen' => 400],
        ];
    }

    public function sanitize(array $forward): array
    {
        $state = (string) ($forward['state'] ?? '');
        if (!in_array($state, self::STATES, true)) {
            throw new OperationValidationException('invalid state: ' . $state);
        }
        $prop = strtolower(trim((string) ($forward['prop'] ?? '')));
        if (!$this->css->validProp($prop)) {
            throw new OperationValidationException('invalid css property: ' . $prop);
        }
        $value = $this->css->value((string) ($forward['value'] ?? ''));
        if ($value === null) {
            throw new OperationValidationException('rejected css value for: ' . $prop);
        }
        return ['state' => $state, 'prop' => $prop, 'value' => $value];
    }

    public function apply(\DOMDocument $doc, ?\DOMElement $target, array $forward): void
    {
        assert($target !== null);
        $decls = $this->parse($target->getAttribute(self::ATTR));
        $prop = (string) $forward['prop'];
        $value = (string) $forward['value'];

        if ($value === '') {
            unset($decls[$prop]);
        } else {
            $decls[$prop] = $value;
        }

        if ($decls === []) {
            $target->removeAttribute(self::ATTR);
            return;
        }
        $pairs = [];
        foreach ($decls as $p => $v) {
            $pairs[] = $p . ': ' . $v;
        }
        $target->setAttribute(self::ATTR, implode('; ', $pairs));
    }

    /** @return array<string, string> */
    private function parse(string $decls): array
    {
        $out = [];
        foreach (explode(';', $decls) as $decl) {
            $colon = strpos($decl, ':');
            if ($colon === false) {
                continue;
            }
            $p = strtolower(trim(substr($decl, 0, $colon)));
            $v = trim(substr($decl, $colon + 1));
            if ($p !== '' && $v !== '') {
                $out[$p] = $v;
            }
        }
        return $out;
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
