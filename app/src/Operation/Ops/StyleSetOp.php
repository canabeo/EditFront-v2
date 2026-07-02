<?php

declare(strict_types=1);

namespace EditFront\Operation\Ops;

use EditFront\Operation\OperationSpec;
use EditFront\Operation\OperationValidationException;
use EditFront\Security\SanitizerCss;

/**
 * style.set — one inline-style property. Empty value removes the property.
 * Style intents (§5.5) compile to these ops with preset constants.
 */
final class StyleSetOp implements OperationSpec
{
    public function __construct(private readonly SanitizerCss $css)
    {
    }

    public function key(): string
    {
        return 'style.set';
    }

    public function schema(): array
    {
        return [
            'prop' => ['type' => 'string', 'required' => true, 'pattern' => '/^[a-z][a-z-]{0,40}$/'],
            'value' => ['type' => 'string', 'required' => true, 'maxLen' => 400],
        ];
    }

    public function sanitize(array $forward): array
    {
        $prop = strtolower(trim((string) ($forward['prop'] ?? '')));
        if (!$this->css->validProp($prop)) {
            throw new OperationValidationException('invalid css property: ' . $prop);
        }
        $value = $this->css->value((string) ($forward['value'] ?? ''));
        if ($value === null) {
            throw new OperationValidationException('rejected css value for: ' . $prop);
        }
        return ['prop' => $prop, 'value' => $value];
    }

    public function apply(\DOMDocument $doc, ?\DOMElement $target, array $forward): void
    {
        assert($target !== null);
        $styles = $this->parseStyle($target->getAttribute('style'));
        $prop = (string) $forward['prop'];
        $value = (string) $forward['value'];

        if ($value === '') {
            unset($styles[$prop]);
        } else {
            $styles[$prop] = $value;
        }

        if ($styles === []) {
            $target->removeAttribute('style');
            return;
        }
        $pairs = [];
        foreach ($styles as $p => $v) {
            $pairs[] = $p . ': ' . $v;
        }
        $target->setAttribute('style', implode('; ', $pairs));
    }

    /** @return array<string, string> */
    private function parseStyle(string $style): array
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
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
        return 'none'; // live preview already applied it locally
    }
}
