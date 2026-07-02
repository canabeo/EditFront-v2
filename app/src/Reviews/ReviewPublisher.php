<?php

declare(strict_types=1);

namespace EditFront\Reviews;

use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PagesIndex;
use EditFront\Document\Html5;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the reviews moderation workflow and site rendering.
 *
 * Mirrors EditFront\News\NewsPublisher's container-adoption mechanism but is
 * much simpler: reviews are not standalone pages, so there is no article render,
 * no slug, no per-page sidecar cleanup. The publisher just refreshes every
 * [data-reviews-list] / [data-reviews-rendered] container site-wide with the
 * currently APPROVED reviews (newest first, honouring a per-container limit).
 *
 * Visibility rule: only status==='approved' reviews are rendered. submit()
 * (the public endpoint) stores a 'pending' review and does NOT re-render — so a
 * flood of spam submissions can never trigger expensive site-wide rewrites; the
 * site only changes when a logged-in manager approves / edits / deletes.
 */
final class ReviewPublisher
{
    /** Authoring markers (placed by hand in the site page). */
    private const LIST_ATTR  = 'data-reviews-list';
    private const LIMIT_ATTR = 'data-reviews-limit';

    /**
     * Persistent re-find hooks written in place of the authoring markers once a
     * container has been published, so EVERY later publish re-renders it. Same
     * rationale as NewsPublisher: neither hook contains the authoring-marker
     * substrings (`data-reviews-rendered` ⊅ `data-reviews-list`;
     * `data-reviews-rendered-limit` ⊅ `data-reviews-limit`), so the published
     * page is clean of authoring markers yet the container stays re-findable.
     */
    private const RENDERED_ATTR       = 'data-reviews-rendered';
    private const RENDERED_LIMIT_ATTR = 'data-reviews-rendered-limit';

    public function __construct(
        private readonly ReviewStore $store,
        private readonly ReviewRenderer $renderer,
        private readonly FileStorage $storage,
        private readonly BackupService $backups,
        private readonly PagesIndex $pages,
        private readonly Html5 $html5,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ---- public submission -------------------------------------------------

    /**
     * Store a public submission as a PENDING review and (best-effort) e-mail the
     * manager. Does NOT re-render the site (pending reviews are invisible).
     *
     * @param array<string,mixed> $input
     * @return array{id:string}
     */
    public function submit(array $input): array
    {
        $config = $this->store->config();
        $source = is_string($input['source'] ?? null) && trim($input['source']) !== ''
            ? trim($input['source'])
            : ($config['site_label'] !== '' ? ('Сайт — ' . $config['site_label']) : 'Сайт');

        $stored = $this->store->upsert([
            'name'    => $input['name'] ?? '',
            'country' => $input['country'] ?? '',
            'text'    => $input['text'] ?? '',
            'year'    => $input['year'] ?? '',
            'status'  => 'pending',
            'source'  => $source,
        ]);

        $this->notify($stored, (string) ($input['__ip'] ?? ''));
        $this->logger->info('reviews.submitted', ['id' => $stored['id']]);

        return ['id' => $stored['id']];
    }

    // ---- admin moderation --------------------------------------------------

    /** Approve a review → visible on the site. */
    public function approve(string $id): array
    {
        return $this->transition($id, 'approved');
    }

    /** Reject a review → stays in the admin queue (rejected), never rendered. */
    public function reject(string $id): array
    {
        return $this->transition($id, 'rejected');
    }

    /** Unpublish an approved review → BACK to moderation (pending), not rejected. */
    public function unpublish(string $id): array
    {
        return $this->transition($id, 'pending');
    }

    /**
     * Create (manual add) or edit a review from the admin form, then refresh.
     * A new review (no id) defaults to 'approved' (the manager is trusted —
     * product decision); an explicit status in the payload wins.
     *
     * @param array<string,mixed> $input
     * @return array{id:string,status:string,lists_updated:int}
     */
    public function save(array $input): array
    {
        $id = is_string($input['id'] ?? null) ? trim((string) $input['id']) : '';

        $existing = null;
        if ($id !== '') {
            $existing = $this->store->find($id);
            if ($existing === null) {
                throw new ReviewException('review not found: ' . $id, 404);
            }
        }

        $status = is_string($input['status'] ?? null) ? trim($input['status']) : '';
        if (!in_array($status, ReviewStore::STATUSES, true)) {
            $status = $existing !== null ? (string) $existing['status'] : 'approved';
        }

        $payload = [
            'name'    => $input['name'] ?? '',
            'country' => $input['country'] ?? '',
            'text'    => $input['text'] ?? '',
            'year'    => $input['year'] ?? '',
            'status'  => $status,
            'source'  => $existing !== null
                ? (string) ($existing['source'] ?? '')
                : (is_string($input['source'] ?? null) && trim($input['source']) !== '' ? trim($input['source']) : 'Вручную'),
        ];
        if ($id !== '') {
            $payload['id'] = $id;
        }

        $stored = $this->store->upsert($payload);
        $updated = $this->refreshContainers();

        $this->logger->info('reviews.saved', ['id' => $stored['id'], 'status' => $stored['status']]);

        return ['id' => $stored['id'], 'status' => (string) $stored['status'], 'lists_updated' => $updated];
    }

    /** Delete a review entirely, then refresh (its card disappears). */
    public function delete(string $id): array
    {
        $item = $this->store->find($id);
        if ($item === null) {
            throw new ReviewException('review not found: ' . $id, 404);
        }
        $this->store->remove($id);
        $updated = $this->refreshContainers();
        $this->logger->info('reviews.deleted', ['id' => $id, 'lists_updated' => $updated]);

        return ['id' => $id, 'lists_updated' => $updated];
    }

    /** Force a re-render of every container (used by the deploy CLI seeder). */
    public function republish(): int
    {
        return $this->refreshContainers();
    }

    // ---- internals ---------------------------------------------------------

    private function transition(string $id, string $status): array
    {
        $item = $this->store->find($id);
        if ($item === null) {
            throw new ReviewException('review not found: ' . $id, 404);
        }
        $item['status'] = $status;
        $stored = $this->store->upsert($item);
        $updated = $this->refreshContainers();

        $this->logger->info('reviews.transition', ['id' => $id, 'status' => $status, 'lists_updated' => $updated]);

        return ['id' => $id, 'status' => (string) $stored['status'], 'lists_updated' => $updated];
    }

    /**
     * Scan every site page for review containers and rewrite them with cards for
     * the APPROVED reviews (newest first, honouring the per-container limit).
     * Returns the count of pages actually rewritten.
     */
    private function refreshContainers(): int
    {
        $approved = array_filter(
            $this->store->items(),
            static fn (array $it): bool => ($it['status'] ?? '') === 'approved',
        );
        $items = ReviewStore::sortForOutput(array_values($approved));
        $updated = 0;

        foreach ($this->pages->list(true) as $entry) {
            $rel = (string) $entry['path'];

            $html = $this->storage->read($rel);
            // cheap pre-filter: skip pages that obviously hold no container
            if (!str_contains($html, self::LIST_ATTR) && !str_contains($html, self::RENDERED_ATTR)) {
                continue;
            }

            $doc = $this->html5->parse($html);
            $containers = $this->findContainers($doc);
            if ($containers === []) {
                continue;
            }

            foreach ($containers as $container) {
                $this->fillContainer($doc, $container, $items);
            }

            $serialized = $this->html5->serialize($doc);
            $this->backups->preSave($rel, $html);
            $this->storage->atomicWrite($rel, $serialized);
            $updated++;
        }

        return $updated;
    }

    /** @return list<\DOMElement> */
    private function findContainers(\DOMDocument $doc): array
    {
        $out = [];
        $xpath = new \DOMXPath($doc);
        $query = '//*[@' . self::LIST_ATTR . ' or @' . self::RENDERED_ATTR . ']';
        foreach ($xpath->query($query) ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $out[] = $node;
            }
        }
        return $out;
    }

    /**
     * Clear a container and insert cards for $items, honouring its limit + mode.
     *
     * @param list<array<string,mixed>> $items already sorted newest-first
     */
    private function fillContainer(\DOMDocument $doc, \DOMElement $container, array $items): void
    {
        while ($container->firstChild !== null) {
            $container->removeChild($container->firstChild);
        }

        [$mode, $limit] = $this->resolveConfig($container);

        $count = 0;
        foreach ($items as $item) {
            if ($limit !== null && $count >= $limit) {
                break;
            }
            $card = $this->renderer->renderCard($item, $mode);
            $imported = $doc->importNode($card, true);
            $container->appendChild($imported);
            $count++;
        }

        // Replace the authoring markers with the persistent re-find hooks.
        $container->removeAttribute(self::LIST_ATTR);
        $container->removeAttribute(self::LIMIT_ATTR);
        $container->setAttribute(self::RENDERED_ATTR, $mode);
        $container->removeAttribute(self::RENDERED_LIMIT_ATTR);
        if ($limit !== null) {
            $container->setAttribute(self::RENDERED_LIMIT_ATTR, (string) $limit);
        }
    }

    /**
     * Resolve [mode, limit] from whichever marker form is present — the
     * authoring markers (data-reviews-list / data-reviews-limit) on a freshly
     * authored page, or the persistent hooks on an already-published page. mode
     * is the card skin ('home' | 'page', default 'page'); a positive-integer
     * limit caps the count, 'all'/absent ⇒ unlimited.
     *
     * @return array{0:string,1:?int}
     */
    private function resolveConfig(\DOMElement $container): array
    {
        if ($container->hasAttribute(self::LIST_ATTR)) {
            $mode = trim($container->getAttribute(self::LIST_ATTR));
            $rawLimit = $container->getAttribute(self::LIMIT_ATTR);
        } else {
            $mode = trim($container->getAttribute(self::RENDERED_ATTR));
            $rawLimit = $container->getAttribute(self::RENDERED_LIMIT_ATTR);
        }
        $mode = $mode === 'home' ? 'home' : 'page';

        $raw = trim($rawLimit);
        if ($raw === '' || strtolower($raw) === 'all' || !ctype_digit($raw)) {
            return [$mode, null];
        }
        $n = (int) $raw;
        return [$mode, $n > 0 ? $n : null];
    }

    /**
     * Best-effort e-mail notification to the manager about a new submission.
     * Skipped entirely when config.notify_email is empty (so tests + a default
     * deployment never call mail()).
     *
     * @param array<string,mixed> $review
     */
    private function notify(array $review, string $ip): void
    {
        $config = $this->store->config();
        $to   = (string) ($config['notify_email'] ?? '');
        $from = (string) ($config['notify_from'] ?? '');
        if ($to === '' || !function_exists('mail')) {
            return;
        }

        $label = (string) ($config['site_label'] ?? '');
        $name    = (string) ($review['name'] ?? '');
        $country = (string) ($review['country'] ?? '');
        $year    = (string) ($review['year'] ?? '');
        $text    = (string) ($review['text'] ?? '');

        $lines = ['Имя: ' . $name];
        if ($country !== '') { $lines[] = 'Страна: ' . $country; }
        if ($year !== '')    { $lines[] = 'Год: ' . $year; }
        $lines[] = '';
        $lines[] = $text;
        $lines[] = '';
        $lines[] = '— — —';
        $lines[] = 'Статус: на модерации';
        if ($ip !== '') { $lines[] = 'IP: ' . $ip; }
        $lines[] = 'Дата: ' . date('d.m.Y H:i:s');

        $body = "Новый отзыв на модерацию" . ($label !== '' ? " — $label" : '') . "\n";
        $body .= "Откройте админку, чтобы одобрить или отклонить.\n\n";
        $body .= implode("\n", $lines) . "\n";

        $subject = '=?UTF-8?B?' . base64_encode('Новый отзыв на модерацию' . ($label !== '' ? " — $label" : '')) . '?=';
        $headers = '';
        if ($from !== '') {
            $headers .= "From: $from\r\n";
        }
        $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n";

        $params = $from !== '' ? ('-f' . $from) : '';
        try {
            @mail($to, $subject, $body, $headers, $params);
        } catch (\Throwable $e) {
            $this->logger->warning('reviews.notify_failed', ['message' => $e->getMessage()]);
        }
    }
}
