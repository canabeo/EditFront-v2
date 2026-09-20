<?php

declare(strict_types=1);

namespace EditFront\Document;

use Psr\Log\LoggerInterface;

/**
 * Keeps the FAQ rich-result markup in step with the questions a reader sees.
 *
 * The page holds the questions twice: once as visible markup, once inside a
 * JSON-LD graph for search engines. Editing one leaves the other stale — the
 * client fixes a wording in the browser, and the answer Google shows keeps the
 * old text for months, silently (found on alum.e-f.work, 20.09.2026).
 *
 * So the visible markup is the truth and the graph is derived from it on every
 * save: mark the list with data-cms-faq, and each direct child becomes one
 * question — its first heading (or [data-cms-faq-q]) is the question, the rest
 * of its text is the answer.
 *
 * Deliberately conservative:
 * - no marked list on the page → the JSON-LD is left completely alone;
 * - only the mainEntity of the FAQPage node is touched, never the rest of the
 *   graph (organization, breadcrumbs, offers — all hand-kept);
 * - an unchanged list rewrites nothing, so a save does not reformat JSON that
 *   nobody edited.
 */
final class FaqSchemaRenderer
{
    private const LIST_ATTR = 'data-cms-faq';
    private const Q_ATTR = 'data-cms-faq-q';
    private const HEADINGS = ['h2', 'h3', 'h4', 'h5', 'h6'];
    private const MAX_ITEMS = 100;
    private const MAX_ANSWER = 5000;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function apply(\DOMDocument $doc): void
    {
        $items = $this->collect($doc);
        if ($items === []) {
            return; // no marked FAQ on this page → nothing is derived, nothing is touched
        }

        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            if (!$script instanceof \DOMElement) {
                continue;
            }
            $raw = $script->textContent;
            try {
                $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->logger->warning('faq.ldjson_unparsable', ['message' => $e->getMessage()]);
                continue; // hand-written JSON we cannot read is left exactly as it is
            }
            if (!is_array($data)) {
                continue;
            }
            $changed = false;
            $data = $this->rewrite($data, $items, $changed);
            if (!$changed) {
                continue;
            }
            $script->textContent = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?: $raw;
            $this->logger->info('faq.schema_synced', ['questions' => count($items)]);
        }
    }

    /**
     * Visible questions, in document order.
     * @return list<array{q: string, a: string}>
     */
    private function collect(\DOMDocument $doc): array
    {
        $out = [];
        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//*[@' . self::LIST_ATTR . ']') ?: [] as $list) {
            if (!$list instanceof \DOMElement) {
                continue;
            }
            foreach ($list->childNodes as $item) {
                if (!$item instanceof \DOMElement) {
                    continue;
                }
                $pair = $this->readItem($xpath, $item);
                if ($pair !== null) {
                    $out[] = $pair;
                }
                if (count($out) >= self::MAX_ITEMS) {
                    return $out;
                }
            }
        }
        return $out;
    }

    /** @return array{q: string, a: string}|null */
    private function readItem(\DOMXPath $xpath, \DOMElement $item): ?array
    {
        $question = $xpath->query('.//*[@' . self::Q_ATTR . ']', $item)?->item(0);
        if (!$question instanceof \DOMElement) {
            foreach ($item->getElementsByTagName('*') as $el) {
                if ($el instanceof \DOMElement && in_array(strtolower($el->tagName), self::HEADINGS, true)) {
                    $question = $el;
                    break;
                }
            }
        }
        if (!$question instanceof \DOMElement) {
            return null; // no question here — not an FAQ item, skip it
        }

        $q = $this->text($question->textContent);

        // the answer is everything else in the item: lift the question out, read
        // the rest, put it back exactly where it was (no clone, no text matching)
        $parent = $question->parentNode;
        if ($parent === null) {
            return null;
        }
        $next = $question->nextSibling;
        $parent->removeChild($question);
        $a = $this->text($item->textContent);
        $parent->insertBefore($question, $next);

        if ($q === '' || $a === '') {
            return null;
        }
        return ['q' => $q, 'a' => mb_substr($a, 0, self::MAX_ANSWER)];
    }

    /** Collapse the whitespace HTML authors use for indentation. */
    private function text(string $raw): string
    {
        $s = preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $raw)) ?? $raw;
        return trim($s);
    }

    /**
     * Walk the decoded graph and replace mainEntity on every FAQPage node.
     * @param array<mixed> $data
     * @param list<array{q: string, a: string}> $items
     * @return array<mixed>
     */
    private function rewrite(array $data, array $items, bool &$changed): array
    {
        if ($this->isFaqPage($data)) {
            $wanted = $this->mainEntity($items);
            if (($data['mainEntity'] ?? null) !== $wanted) {
                $data['mainEntity'] = $wanted;
                $changed = true;
            }
            return $data;
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->rewrite($value, $items, $changed);
            }
        }
        return $data;
    }

    /** @param array<mixed> $node */
    private function isFaqPage(array $node): bool
    {
        $type = $node['@type'] ?? null;
        if (is_string($type)) {
            return $type === 'FAQPage';
        }
        return is_array($type) && in_array('FAQPage', $type, true);
    }

    /**
     * @param list<array{q: string, a: string}> $items
     * @return list<array<string, mixed>>
     */
    private function mainEntity(array $items): array
    {
        return array_map(static fn (array $i): array => [
            '@type' => 'Question',
            'name' => $i['q'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $i['a'],
            ],
        ], $items);
    }
}
