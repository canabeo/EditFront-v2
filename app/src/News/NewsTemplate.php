<?php

declare(strict_types=1);

namespace EditFront\News;

use EditFront\Document\Html5;
use EditFront\Storage\FileStorage;

/**
 * Loads the marked-up "skin" page (config.template_page), parses it with
 * Html5, and exposes the article document + the card node. Validates that
 * every required data-nf slot exists. Per-request cached. NO writes here.
 */
final class NewsTemplate
{
    /** Required data-nf slots on the article document. */
    private const REQUIRED_ARTICLE_SLOTS = [
        'title_tag', 'excerpt', 'url', 'jsonld',
        'title', 'body', 'cover', 'meta_line', 'title_short',
    ];

    /** Required data-nf slots inside the [data-news-card] template. */
    private const REQUIRED_CARD_SLOTS = ['url', 'cover', 'meta_line', 'title_short'];

    private ?\DOMDocument $articleDoc = null;
    private ?\DOMElement $cardEl = null;

    public function __construct(
        private readonly NewsStore $store,
        private readonly FileStorage $storage,
        private readonly Html5 $html5,
    ) {
    }

    public function templatePath(): string
    {
        $page = (string) ($this->store->config()['template_page'] ?? '_news-template.html');
        return $page !== '' ? $page : '_news-template.html';
    }

    /**
     * Parsed clone-source of the article template (the FULL doc).
     * Per-request cached: callers must clone nodes before mutating.
     */
    public function articleSkin(): \DOMDocument
    {
        if ($this->articleDoc !== null) {
            return $this->articleDoc;
        }
        $rel = $this->templatePath();
        if (!$this->storage->exists($rel)) {
            throw new NewsException('news template page not found: ' . $rel, 422);
        }
        $html = $this->storage->read($rel);
        $doc = $this->html5->parse($html);

        $root = $doc->getElementsByTagName('html')->item(0);
        if (!$root instanceof \DOMElement || !$root->hasAttribute('data-news-template')) {
            throw new NewsException('news template page is missing the [data-news-template] marker on <html>', 422);
        }
        $this->articleDoc = $doc;
        return $doc;
    }

    /**
     * The single .post root element inside the [data-news-card] <template>.
     * Returned node is OWNED by the article skin doc; clone before use.
     */
    public function cardNode(): \DOMElement
    {
        if ($this->cardEl !== null) {
            return $this->cardEl;
        }
        $doc = $this->articleSkin();
        $cardEl = $this->extractCardRoot($doc);
        $this->cardEl = $cardEl;
        return $cardEl;
    }

    public function validate(): void
    {
        $doc = $this->articleSkin();

        $missingArticle = $this->missingSlots($doc->documentElement, self::REQUIRED_ARTICLE_SLOTS, true);
        if ($missingArticle !== []) {
            throw new NewsException(
                'news article template is missing required data-nf slots: ' . implode(', ', $missingArticle),
                422,
            );
        }

        $card = $this->cardNode();
        $missingCard = $this->missingSlots($card, self::REQUIRED_CARD_SLOTS, true);
        if ($missingCard !== []) {
            throw new NewsException(
                'news card template is missing required data-nf slots: ' . implode(', ', $missingCard),
                422,
            );
        }
    }

    /**
     * Locate the [data-news-card] <template> and return its single .post root.
     * Throws if the card marker or its root element is absent.
     */
    private function extractCardRoot(\DOMDocument $doc): \DOMElement
    {
        $xpath = new \DOMXPath($doc);
        $tpl = $xpath->query('//template[@data-news-card]')?->item(0);
        if (!$tpl instanceof \DOMElement) {
            throw new NewsException('news template page is missing the <template data-news-card> element', 422);
        }

        // A <template>'s children live in its DOM content. Masterminds (with
        // disable_html_ns) parses template children as ordinary child nodes,
        // so the root is the first element child of the <template>.
        $root = null;
        foreach ($tpl->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $root = $child;
                break;
            }
        }
        if (!$root instanceof \DOMElement) {
            throw new NewsException('news card <template> has no element child (expected one .post root)', 422);
        }
        return $root;
    }

    /**
     * Return the required slot names that are NOT present as data-nf values
     * within $scope. When $cardAware is true the article-scope walk skips
     * into nested [data-news-card] templates so card-only slots don't falsely
     * satisfy article requirements (and vice-versa).
     *
     * @param list<string> $required
     * @return list<string>
     */
    private function missingSlots(\DOMElement $scope, array $required, bool $cardAware): array
    {
        $present = [];
        $this->collectSlotNames($scope, $present, $cardAware && $scope->tagName !== 'template' && !$this->isCardScope($scope));
        $missing = [];
        foreach ($required as $name) {
            if (!isset($present[$name])) {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    /** True when $el is (or is inside) a [data-news-card] template subtree root. */
    private function isCardScope(\DOMElement $el): bool
    {
        $node = $el;
        while ($node instanceof \DOMElement) {
            if ($node->tagName === 'template' && $node->hasAttribute('data-news-card')) {
                return true;
            }
            $parent = $node->parentNode;
            $node = $parent instanceof \DOMElement ? $parent : null;
        }
        return false;
    }

    /**
     * Walk $scope collecting every data-nf value into $present (as keys).
     * When $skipCardTemplates is true, do NOT descend into [data-news-card]
     * <template> subtrees — keeps article-scope and card-scope slot sets
     * disjoint.
     *
     * @param array<string,true> $present
     */
    private function collectSlotNames(\DOMNode $scope, array &$present, bool $skipCardTemplates): void
    {
        foreach ($scope->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($skipCardTemplates && $child->tagName === 'template' && $child->hasAttribute('data-news-card')) {
                continue;
            }
            $nf = $child->getAttribute('data-nf');
            if ($nf !== '') {
                $present[$nf] = true;
            }
            $this->collectSlotNames($child, $present, $skipCardTemplates);
        }
        // include $scope's own data-nf if it's an element (card root carries none, but article <article> body does via descendants)
        if ($scope instanceof \DOMElement) {
            $own = $scope->getAttribute('data-nf');
            if ($own !== '') {
                $present[$own] = true;
            }
        }
    }
}
