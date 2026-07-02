<?php

declare(strict_types=1);

namespace EditFront\Operation\Ops;

use EditFront\Document\Annotator;
use EditFront\Operation\OperationSpec;
use EditFront\Operation\OperationValidationException;
use EditFront\Security\SanitizerUrl;

/** attr.set — set one attribute (href/src/alt/title/...). */
final class AttrSetOp implements OperationSpec
{
    private const URL_ATTRS = ['href', 'src', 'action', 'formaction', 'poster'];
    private const DENY_ATTRS = ['style', 'srcset', 'contenteditable', 'is', 'slot'];

    public function __construct(private readonly SanitizerUrl $urls)
    {
    }

    public function key(): string
    {
        return 'attr.set';
    }

    public function schema(): array
    {
        return [
            'name' => ['type' => 'string', 'required' => true, 'pattern' => '/^[a-zA-Z][a-zA-Z0-9-]{0,49}$/'],
            'value' => ['type' => 'string', 'required' => true, 'maxLen' => 2000],
        ];
    }

    public function sanitize(array $forward): array
    {
        $name = strtolower((string) ($forward['name'] ?? ''));
        $value = (string) ($forward['value'] ?? '');

        if (str_starts_with($name, 'on') || str_starts_with($name, 'data-cms-') || in_array($name, self::DENY_ATTRS, true)) {
            throw new OperationValidationException('attribute not allowed: ' . $name);
        }
        if (in_array($name, self::URL_ATTRS, true)) {
            $value = $this->urls->sanitize($value, $name === 'src');
            if ($value === null) {
                throw new OperationValidationException('unsafe URL for attribute: ' . $name);
            }
        }
        return ['name' => $name, 'value' => $value];
    }

    public function apply(\DOMDocument $doc, ?\DOMElement $target, array $forward): void
    {
        assert($target !== null);
        $target->setAttribute((string) $forward['name'], (string) $forward['value']);
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
