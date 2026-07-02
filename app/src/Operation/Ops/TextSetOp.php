<?php

declare(strict_types=1);

namespace EditFront\Operation\Ops;

use EditFront\Document\Html5;
use EditFront\Operation\OperationSpec;
use EditFront\Security\SanitizerHtml;

/** text.set — rich-text replacement of a node's innerHTML (whitelist-sanitized). */
final class TextSetOp implements OperationSpec
{
    public function __construct(
        private readonly SanitizerHtml $sanitizer,
        private readonly Html5 $html5,
    ) {
    }

    public function key(): string
    {
        return 'text.set';
    }

    public function schema(): array
    {
        return [
            'html' => ['type' => 'string', 'required' => true, 'maxLen' => 200_000],
        ];
    }

    public function sanitize(array $forward): array
    {
        return ['html' => $this->sanitizer->sanitize((string) ($forward['html'] ?? ''))];
    }

    public function apply(\DOMDocument $doc, ?\DOMElement $target, array $forward): void
    {
        assert($target !== null);
        while ($target->firstChild !== null) {
            $target->removeChild($target->firstChild);
        }
        $html = (string) $forward['html'];
        if ($html !== '') {
            foreach ($this->html5->importFragment($doc, $html) as $node) {
                $target->appendChild($node);
            }
        }
    }

    public function targetRequired(): bool
    {
        return true;
    }

    public function echoMode(): string
    {
        return 'skip-text'; // user typing must not be echoed back (cursor flicker)
    }
}
