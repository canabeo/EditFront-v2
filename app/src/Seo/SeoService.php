<?php

declare(strict_types=1);

namespace EditFront\Seo;

use EditFront\Document\Html5;
use EditFront\Security\SanitizerUrl;
use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PathGuard;

/**
 * Per-page basic SEO (§9). The page's own <head> is the source of truth — we read
 * and rewrite the real <title>/<meta>/<link> tags in place (no sidecar), so the
 * live page is self-contained (drop-in) and a content save never needs to know
 * about SEO. Editing goes through a MANDATORY pre-save backup + atomic write,
 * exactly like a content save.
 *
 * Fields: title, description, canonical, index/follow (robots). Anything else
 * (Open Graph, Twitter, JSON-LD) is left for a future seo-social plugin.
 */
final class SeoService
{
    private const MAX_TITLE = 200;
    private const MAX_DESC = 500;
    private const MAX_URL = 2000;

    public function __construct(
        private readonly FileStorage $storage,
        private readonly Html5 $html5,
        private readonly BackupService $backups,
        private readonly SanitizerUrl $urls,
        private readonly PathGuard $guard,
    ) {
    }

    /**
     * @return array{title: string, description: string, canonical: string, index: bool, follow: bool}
     */
    public function read(string $page): array
    {
        $this->guard->assertSafeRelative($page);
        $doc = $this->html5->parse($this->storage->read($page));
        $head = $doc->getElementsByTagName('head')->item(0);
        if ($head === null) {
            return ['title' => '', 'description' => '', 'canonical' => '', 'index' => true, 'follow' => true];
        }

        $titleEl = $head->getElementsByTagName('title')->item(0);
        // combine the content of EVERY robots meta (crawlers merge them) and treat
        // the `none` directive as noindex,nofollow (spec equivalence)
        $robots = '';
        foreach ($this->metasByName($head, 'robots') as $m) {
            $robots .= ' ' . strtolower($m->getAttribute('content'));
        }
        $none = str_contains($robots, 'none');

        return [
            'title' => $titleEl !== null ? trim($titleEl->textContent) : '',
            'description' => $this->metaContent($head, 'description'),
            'canonical' => $this->canonicalHref($head),
            'index' => !($none || str_contains($robots, 'noindex')),
            'follow' => !($none || str_contains($robots, 'nofollow')),
        ];
    }

    /** Sitemap helper: a page is indexable unless its robots meta says noindex. */
    public function isIndexable(string $page): bool
    {
        try {
            return $this->read($page)['index'];
        } catch (\Throwable) {
            return true; // never let a bad page drop out of the sitemap by accident
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{new_sha256: string, backup_id: string}
     * @throws SeoException invalid input (e.g. an unsafe canonical URL)
     */
    public function save(string $page, array $fields): array
    {
        $this->guard->assertSafeRelative($page);
        $current = $this->storage->read($page);

        $title = $this->clean((string) ($fields['title'] ?? ''), self::MAX_TITLE);
        $description = $this->clean((string) ($fields['description'] ?? ''), self::MAX_DESC);
        $canonical = trim((string) ($fields['canonical'] ?? ''));
        if (strlen($canonical) > self::MAX_URL) {
            throw new SeoException('canonical URL too long');
        }
        if ($canonical !== '' && $this->urls->sanitize($canonical) === null) {
            throw new SeoException('invalid canonical URL');
        }
        $index = (bool) ($fields['index'] ?? true);
        $follow = (bool) ($fields['follow'] ?? true);

        $doc = $this->html5->parse($current);
        $head = $this->ensureHead($doc);

        $this->setTitle($doc, $head, $title);
        $this->upsertMeta($doc, $head, 'description', $description);
        $this->upsertCanonical($doc, $head, $canonical);
        // index,follow is the default → no tag at all (cleaner output)
        $robots = ($index && $follow) ? '' : (($index ? 'index' : 'noindex') . ',' . ($follow ? 'follow' : 'nofollow'));
        $this->upsertMeta($doc, $head, 'robots', $robots);

        // mandatory pre-save backup BEFORE writing (mirror of a content save)
        $backupId = $this->backups->preSave($page, $current);

        $html = $this->html5->serialize($doc);
        $this->storage->atomicWrite($page, $html);

        return ['new_sha256' => hash('sha256', $html), 'backup_id' => $backupId];
    }

    /* ---- head helpers ---- */

    private function ensureHead(\DOMDocument $doc): \DOMElement
    {
        $head = $doc->getElementsByTagName('head')->item(0);
        if ($head instanceof \DOMElement) {
            return $head;
        }
        $head = $doc->createElement('head');
        $html = $doc->getElementsByTagName('html')->item(0);
        if ($html instanceof \DOMElement) {
            $html->insertBefore($head, $html->firstChild);
        } else {
            $doc->appendChild($head);
        }
        return $head;
    }

    private function setTitle(\DOMDocument $doc, \DOMElement $head, string $title): void
    {
        $titles = iterator_to_array($head->getElementsByTagName('title'));
        // empty value removes the tag (same convention as description/canonical) —
        // never materialise an empty <title></title>
        if ($title === '') {
            foreach ($titles as $t) {
                $t->parentNode?->removeChild($t);
            }
            return;
        }
        if ($titles !== []) {
            $titles[0]->textContent = $title;
            for ($i = 1, $n = count($titles); $i < $n; $i++) {
                $titles[$i]->parentNode?->removeChild($titles[$i]);
            }
            return;
        }
        $el = $doc->createElement('title');
        $el->textContent = $title;
        $head->insertBefore($el, $head->firstChild);
    }

    /** name is matched case-insensitively; empty value removes the tag entirely. */
    private function upsertMeta(\DOMDocument $doc, \DOMElement $head, string $name, string $value): void
    {
        $existing = $this->metasByName($head, $name);
        if ($value === '') {
            foreach ($existing as $m) {
                $m->parentNode?->removeChild($m);
            }
            return;
        }
        if ($existing !== []) {
            $existing[0]->setAttribute('content', $value);
            for ($i = 1, $n = count($existing); $i < $n; $i++) {
                $existing[$i]->parentNode?->removeChild($existing[$i]);
            }
            return;
        }
        $m = $doc->createElement('meta');
        $m->setAttribute('name', $name);
        $m->setAttribute('content', $value);
        $head->appendChild($m);
    }

    private function upsertCanonical(\DOMDocument $doc, \DOMElement $head, string $href): void
    {
        $links = [];
        foreach ($head->getElementsByTagName('link') as $l) {
            if ($l instanceof \DOMElement && strtolower($l->getAttribute('rel')) === 'canonical') {
                $links[] = $l;
            }
        }
        if ($href === '') {
            foreach ($links as $l) {
                $l->parentNode?->removeChild($l);
            }
            return;
        }
        if ($links !== []) {
            $links[0]->setAttribute('href', $href);
            for ($i = 1, $n = count($links); $i < $n; $i++) {
                $links[$i]->parentNode?->removeChild($links[$i]);
            }
            return;
        }
        $l = $doc->createElement('link');
        $l->setAttribute('rel', 'canonical');
        $l->setAttribute('href', $href);
        $head->appendChild($l);
    }

    /** @return list<\DOMElement> */
    private function metasByName(\DOMElement $head, string $name): array
    {
        $out = [];
        foreach ($head->getElementsByTagName('meta') as $m) {
            if ($m instanceof \DOMElement && strtolower($m->getAttribute('name')) === $name) {
                $out[] = $m;
            }
        }
        return $out;
    }

    private function metaContent(\DOMElement $head, string $name): string
    {
        $metas = $this->metasByName($head, $name);
        return $metas !== [] ? trim($metas[0]->getAttribute('content')) : '';
    }

    private function canonicalHref(\DOMElement $head): string
    {
        foreach ($head->getElementsByTagName('link') as $l) {
            if ($l instanceof \DOMElement && strtolower($l->getAttribute('rel')) === 'canonical') {
                return trim($l->getAttribute('href'));
            }
        }
        return '';
    }

    private function clean(string $v, int $maxChars): string
    {
        $v = str_replace(["\r", "\n", "\t"], ' ', $v);
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;
        $v = trim($v);
        if (mb_strlen($v) > $maxChars) {
            $v = mb_substr($v, 0, $maxChars);
        }
        return $v;
    }
}
