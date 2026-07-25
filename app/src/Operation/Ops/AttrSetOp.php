<?php

declare(strict_types=1);

namespace EditFront\Operation\Ops;

use EditFront\Document\Annotator;
use EditFront\Operation\OperationSpec;
use EditFront\Operation\OperationValidationException;
use EditFront\Security\SanitizerUrl;

/**
 * attr.set — set one attribute (href/src/alt/title/...).
 *
 * This is an ALLOW-list, deliberately. The previous deny-list was a losing
 * architecture: `srcdoc` does not start with "on", is not a URL attribute and
 * was not on the deny list, so it passed every filter — set on an <iframe> it
 * puts attacker-authored HTML, parsed in the SAME ORIGIN, into a published page
 * that visitors load. `<object data=…>` had the same shape. Both bypassed the
 * layered sanitizers the project promises, because this operation writes the
 * attribute after sanitizing has already happened. A deny-list would have had
 * to predict every future such attribute; an allow-list fails closed instead.
 */
final class AttrSetOp implements OperationSpec
{
    /** URL-bearing: value additionally goes through SanitizerUrl */
    private const URL_ATTRS = ['href', 'src', 'action', 'formaction', 'poster'];

    /** inert presentational/semantic attributes; aria- and data- go by prefix */
    private const PLAIN_ATTRS = [
        'alt', 'title', 'class', 'id', 'role', 'target', 'rel', 'lang', 'dir',
        'width', 'height', 'loading', 'decoding', 'colspan', 'rowspan', 'datetime',
    ];

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

        if (!self::isAllowed($name)) {
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

    /**
     * data-* stays settable (it is inert markup and plugins rely on it), except
     * the data-cms-* namespace the editor uses to address nodes. Note that the
     * bare `data` attribute — the one that makes <object> load a resource — is
     * NOT matched by the data- prefix and is therefore refused.
     */
    private static function isAllowed(string $name): bool
    {
        if (in_array($name, self::URL_ATTRS, true) || in_array($name, self::PLAIN_ATTRS, true)) {
            return true;
        }
        if (str_starts_with($name, 'aria-')) {
            return true;
        }
        return str_starts_with($name, 'data-') && !str_starts_with($name, 'data-cms-');
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
