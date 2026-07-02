<?php

declare(strict_types=1);

namespace EditFront\Document;

/**
 * Stable-id management (§4.1). One traverse() is the single walking code path
 * for annotate and any other pass — annotate/apply can never drift apart on
 * "which nodes count" (v1 walk/collect drift cure).
 *
 * applyOps NEVER writes ids — stamping happens only via ensureAnnotated()
 * at editor-open time, persisted to disk (invariant 0.2.1).
 *
 * Plugin blocks (§6): a data-cms-block node IS editable (it can be selected and
 * targeted by plugin props ops), but its rendered subtree is NOT — the content
 * is owned by the kind and edited through props, so traverse() stamps the block
 * node and stops, and isEditable() rejects anything strictly inside a block.
 */
final class Annotator
{
    public const ID_ATTR = 'data-cms-id';
    public const PROTECTED_ATTR = 'data-cms-protected';
    public const BLOCK_ATTR = 'data-cms-block';
    public const ID_PATTERN = '/^cms-[0-9a-f]{12}$/';

    public const PROTECTED_TAGS = [
        'html', 'head', 'body', 'script', 'style', 'link', 'meta', 'title', 'base',
    ];

    public function generateId(): string
    {
        return 'cms-' . bin2hex(random_bytes(6));
    }

    public function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /** Element may be edited / targeted by ops / receive an id. */
    public function isEditable(\DOMElement $el): bool
    {
        if (in_array(strtolower($el->tagName), self::PROTECTED_TAGS, true)) {
            return false;
        }
        // protected subtree (self or any ancestor)
        for ($n = $el; $n instanceof \DOMElement; $n = $n->parentNode) {
            if ($n->getAttribute(self::PROTECTED_ATTR) === 'true') {
                return false;
            }
        }
        // strictly inside a plugin block → managed by the kind, not directly editable
        for ($n = $el->parentNode; $n instanceof \DOMElement; $n = $n->parentNode) {
            if ($n->hasAttribute(self::BLOCK_ATTR)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Visit every editable element under <body> in document order.
     * Protected tags and data-cms-protected subtrees are skipped entirely;
     * a data-cms-block node is visited but its subtree is not descended into.
     * @param callable(\DOMElement): void $callback
     */
    public function traverse(\DOMDocument $doc, callable $callback): void
    {
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body !== null) {
            $this->walk($body, $callback);
        }
    }

    private function walk(\DOMNode $node, callable $callback): void
    {
        // snapshot: the callback may mutate the tree
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if (
                in_array(strtolower($child->tagName), self::PROTECTED_TAGS, true)
                || $child->getAttribute(self::PROTECTED_ATTR) === 'true'
            ) {
                continue; // skip node and subtree
            }
            if ($child->hasAttribute(self::BLOCK_ATTR)) {
                $callback($child); // stamp the block node itself, but do not descend
                continue;
            }
            $callback($child);
            $this->walk($child, $callback);
        }
    }

    /** Stamp missing/invalid/duplicate ids. Returns true when the doc changed. */
    public function ensureAnnotated(\DOMDocument $doc): bool
    {
        $changed = false;
        $seen = [];
        $this->traverse($doc, function (\DOMElement $el) use (&$changed, &$seen): void {
            $id = $el->getAttribute(self::ID_ATTR);
            if ($id !== '' && $this->isValidId($id) && !isset($seen[$id])) {
                $seen[$id] = true;
                return;
            }
            do {
                $new = $this->generateId();
            } while (isset($seen[$new]));
            $el->setAttribute(self::ID_ATTR, $new);
            $seen[$new] = true;
            $changed = true;
        });
        return $changed;
    }

    public function findById(\DOMDocument $doc, string $id): ?\DOMElement
    {
        if (!$this->isValidId($id)) {
            return null;
        }
        $xpath = new \DOMXPath($doc);
        $node = $xpath->query("//*[@" . self::ID_ATTR . "='" . $id . "']")?->item(0);
        return $node instanceof \DOMElement ? $node : null;
    }

    /** @return array<string, true> all ids present in the document */
    public function collectIds(\DOMDocument $doc): array
    {
        $ids = [];
        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//*[@' . self::ID_ATTR . ']') ?: [] as $el) {
            if ($el instanceof \DOMElement) {
                $ids[$el->getAttribute(self::ID_ATTR)] = true;
            }
        }
        return $ids;
    }

    /**
     * Give fresh unique ids to every editable element of a (newly inserted /
     * cloned) subtree, including the root unless it already got an explicit id.
     * @param array<string, true> $taken ids already used in the document (mutated)
     */
    public function assignFreshIds(\DOMElement $root, array &$taken, bool $includeRoot = true): void
    {
        $targets = [];
        if ($includeRoot && $this->isEditable($root)) {
            $targets[] = $root;
        }
        foreach ($root->getElementsByTagName('*') as $el) {
            if ($el instanceof \DOMElement && $this->isEditable($el)) {
                $targets[] = $el;
            }
        }
        foreach ($targets as $el) {
            do {
                $new = $this->generateId();
            } while (isset($taken[$new]));
            $el->setAttribute(self::ID_ATTR, $new);
            $taken[$new] = true;
        }
    }

    /**
     * Editable descendants of $root in document order (root itself excluded) —
     * exactly the set assignFreshIds(..., includeRoot:false) would stamp. The
     * duplicate op uses this to apply the editor's pre-assigned ids in the same
     * order, so client and server agree on the clone's ids.
     * @return list<\DOMElement>
     */
    public function editableDescendants(\DOMElement $root): array
    {
        $out = [];
        foreach ($root->getElementsByTagName('*') as $el) {
            if ($el instanceof \DOMElement && $this->isEditable($el)) {
                $out[] = $el;
            }
        }
        return $out;
    }
}
