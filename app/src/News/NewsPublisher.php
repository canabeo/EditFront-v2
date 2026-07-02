<?php

declare(strict_types=1);

namespace EditFront\News;

use EditFront\Document\Annotator;
use EditFront\Document\Html5;
use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PagesIndex;
use EditFront\Storage\PageExistsException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates news publishing: render the article page, write it atomically
 * (with a mandatory pre-save backup when it already exists), then refresh every
 * [data-news-list] container site-wide. The only I/O-heavy News class.
 *
 * Invariant: NewsTemplate::validate() runs BEFORE any write — a bad template
 * throws and zero files are touched.
 *
 * Delete path: PageService is intentionally bypassed (see contract §6 DI matrix
 * — the ctor has no PageService). delete() therefore replicates by hand the two
 * behaviours PageService::delete would have given: a mandatory pre-delete backup
 * and per-page sidecar cleanup, so a removed article leaves no orphans.
 */
final class NewsPublisher
{
    /** Marker attrs that flag a page as a template (never written/listed). */
    private const TEMPLATE_ATTR = 'data-news-template';
    private const LIST_ATTR = 'data-news-list';
    private const LIMIT_ATTR = 'data-news-limit';

    /**
     * Persistent re-find hooks written in place of the authoring markers once a
     * list container has been published. Rationale: the authoring markers
     * (data-news-list / data-news-limit) are stripped from published output
     * (spec §1, clean pages — none of the six marker strings may survive), but
     * a list container must stay re-findable so EVERY later publish re-renders
     * it with the current card set. So on first refresh we replace the authoring
     * markers with these engine hooks, which carry the same mode/limit config
     * yet contain none of the six forbidden marker substrings
     * (`data-news-rendered` ⊅ `data-news-list`; `data-news-rendered-limit` ⊅
     * `data-news-limit`). Both authoring markers AND these hooks are honoured by
     * the scan, so re-publish is idempotent and accumulates correctly.
     */
    private const RENDERED_ATTR = 'data-news-rendered';
    private const RENDERED_LIMIT_ATTR = 'data-news-rendered-limit';

    public function __construct(
        private readonly NewsStore $store,
        private readonly NewsRenderer $renderer,
        private readonly NewsTemplate $template,
        private readonly NewsSlug $slug,
        private readonly FileStorage $storage,
        private readonly BackupService $backups,
        private readonly PagesIndex $pages,
        private readonly Html5 $html5,
        private readonly Annotator $annotator,
        private readonly NewsBodySanitizer $sanitizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Validate → slug → store upsert → write <slug>.html → refresh lists → invalidate.
     *
     * @param array<string,mixed> $input
     * @return array{id:string,slug:string,page:string,backup_id:?string,lists_updated:int}
     */
    public function save(array $input): array
    {
        // 1. Template + required slots FIRST — bad template ⇒ throw, nothing written.
        $this->template->validate();

        // 2. Normalize the item (sanitize body, fill defaults, mint id/slug).
        $item = $this->normalize($input);

        $page = $item['slug'] . '.html';
        $existed = $this->storage->exists($page);

        // 3. Render the full article page (markers stripped, ids stamped, JSON-LD regenerated).
        $html = $this->renderer->renderArticle($item);

        // 4. Write the page first (atomic), then upsert the store, so a page-write
        //    failure never leaves a dangling store entry.
        $backupId = null;
        if ($existed) {
            $backupId = $this->backups->preSave($page, $this->storage->read($page));
            $this->storage->atomicWrite($page, $html);
        } else {
            // createNew is create-only (409 on conflict). exists() said no; race ⇒ throw.
            try {
                $this->storage->createNew($page, $html);
            } catch (PageExistsException $e) {
                throw new NewsException(
                    'page already exists: ' . $page,
                    409,
                    $e,
                );
            }
        }

        // 5. Now the page is safely on disk — record the item.
        $stored = $this->store->upsert($item);

        // 6. New article ⇒ the site page set changed; refresh the pages index.
        if (!$existed) {
            $this->pages->invalidate();
        }

        // 7. Refresh every [data-news-list] container site-wide.
        $listsUpdated = $this->refreshLists();

        // 8. Re-render EVERY article page with freshly-computed date-neighbours,
        //    so prev/next stays consistent: adding/editing (incl. a date change)
        //    re-orders neighbours, which changes the OTHER articles' nav too.
        //    Re-rendering all is the simplest provably-consistent approach (news
        //    count is small). The primary page was already written above with its
        //    mandatory pre-save backup; the regeneration overwrites it (now with
        //    nav) and the secondaries WITHOUT a per-file pre-save backup — see
        //    regenerateArticlePages() for the backup-discipline rationale.
        $this->regenerateArticlePages();

        $this->logger->info('news.published', [
            'id' => $stored['id'],
            'slug' => $stored['slug'],
            'new' => !$existed,
            'lists_updated' => $listsUpdated,
        ]);

        return [
            'id' => $stored['id'],
            'slug' => $stored['slug'],
            'page' => $page,
            'backup_id' => $backupId,
            'lists_updated' => $listsUpdated,
        ];
    }

    /**
     * Delete page (pre-delete backup) → sidecar cleanup → store remove → refresh
     * lists → invalidate. PageService is bypassed (contract §6), so the pre-delete
     * backup and sidecar cleanup are done by hand here.
     *
     * @return array{id:string,slug:string,lists_updated:int}
     */
    public function delete(string $id): array
    {
        $item = $this->store->find($id);
        if ($item === null) {
            throw new NewsException('news item not found: ' . $id, 404);
        }

        $page = $item['slug'] . '.html';

        // Pre-delete backup of the article page, then remove it + its sidecars.
        if ($this->storage->exists($page)) {
            $this->backups->preSave($page, $this->storage->read($page));
            $this->storage->delete($page);
            $this->cleanupSidecars($page);
        }

        // Remove from the store, then refresh lists so its card disappears.
        $this->store->remove($id);
        $this->pages->invalidate();
        $listsUpdated = $this->refreshLists();

        // Re-render the surviving article pages: removing an item changes its
        // former neighbours' prev/next (e.g. the older article loses its "next"
        // link to the deleted newer one). The deleted page is already unlinked +
        // backed up above; the survivors are regenerated WITHOUT per-file pre-save
        // backups (deterministic from items.json) — see regenerateArticlePages().
        $this->regenerateArticlePages();

        $this->logger->info('news.deleted', [
            'id' => $id,
            'slug' => $item['slug'],
            'lists_updated' => $listsUpdated,
        ]);

        return [
            'id' => $id,
            'slug' => $item['slug'],
            'lists_updated' => $listsUpdated,
        ];
    }

    /**
     * Re-render EVERY article page from the store with freshly-computed
     * date-neighbours, so prev/next is consistent after ANY add / edit (incl. a
     * date change) / delete. Because a single change re-orders neighbours, the
     * OTHER articles' nav must change too; re-rendering all is the simplest
     * provably-correct approach (news count is small).
     *
     * Neighbour rule (matches the card-list ordering, NewsStore::sortForOutput,
     * which is newest-first): for the item at index i, `next` (the NEWER news) =
     * items[i-1] and `prev` (the OLDER news) = items[i+1].
     *
     * BACKUP DISCIPLINE: these are SECONDARY regenerations — every article page
     * is deterministically rebuilt from items.json (the source of truth), so we
     * write them with atomicWrite WITHOUT a per-file pre-save backup. The
     * MANDATORY pre-save backup for the PRIMARY user-edited article (and the
     * pre-delete backup on a deletion) is taken in save()/delete() before this
     * runs; taking another backup of every article on every save would churn the
     * backups dir for no recovery value (the content is reproducible from the
     * store). A page that does not yet exist on disk (e.g. a brand-new article
     * mid-save, already written by save()) is created via createNew and skipped
     * if it somehow races.
     */
    private function regenerateArticlePages(): void
    {
        $items = NewsStore::sortForOutput($this->store->items());
        $count = count($items);

        for ($i = 0; $i < $count; $i++) {
            $item = $items[$i];

            // newest-first ⇒ the NEWER neighbour is the previous index, the
            // OLDER neighbour is the next index.
            $next = $i > 0 ? $items[$i - 1] : null;          // newer
            $prev = $i + 1 < $count ? $items[$i + 1] : null; // older

            $page = ((string) ($item['slug'] ?? '')) . '.html';
            if ($page === '.html') {
                continue; // defensive: malformed item with no slug
            }

            $html = $this->renderer->renderArticle($item, $prev, $next);

            // SECONDARY write: no pre-save backup (see method docblock). Use
            // atomicWrite for an existing page; createNew for one that is not on
            // disk yet (best-effort — a race that says it exists is harmless,
            // the page is already correct from save()).
            if ($this->storage->exists($page)) {
                $this->storage->atomicWrite($page, $html);
            } else {
                try {
                    $this->storage->createNew($page, $html);
                } catch (PageExistsException) {
                    $this->storage->atomicWrite($page, $html);
                }
            }
        }
    }

    /**
     * Remove the per-page sidecars for a deleted article page, addressed by
     * sha1('<slug>.html') under storage_dir. PageService::delete would normally
     * do this; bypassed here per contract §6, so it is replicated by hand.
     * Best-effort — a missing sidecar is not an error.
     *
     * Covers exactly the v2-real per-page sidecars — meta + drafts (file-form
     * '<hash>.json'), the journal (file + rotated-gz glob) and undo (dir-form).
     * Each entry below names the class that OWNS that on-disk layout, so a future
     * layout change has a pointer back to the source of truth. (There is no
     * storage/seo/ or storage/customizations/ sidecar in v2 — SeoService rewrites
     * the page <head> in place, and there is no customizations feature — so those
     * paths are deliberately NOT touched here.)
     */
    private function cleanupSidecars(string $page): void
    {
        $hash = sha1($page);
        $base = rtrim($this->store->storageDir(), '/');

        // file-form sidecars: storageDir()/<kind>/<hash>.json
        $files = [
            // meta/<hash>.json — owned by EditFront\Plugin\PropsStore
            $base . '/meta/' . $hash . '.json',
            // drafts/<hash>.json — owned by EditFront\Draft\DraftService
            $base . '/drafts/' . $hash . '.json',
        ];
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        // journal/<hash>.jsonl(+.gz) — mirror of EditFront\Storage\Journal::delete:
        // a FILE (not a dir) — the live '<hash>.jsonl' plus every rotated
        // '<hash>.*.jsonl.gz' archive.
        @unlink($base . '/journal/' . $hash . '.jsonl');
        foreach (glob($base . '/journal/' . $hash . '.*.jsonl.gz') ?: [] as $gz) {
            @unlink($gz);
        }

        // dir-form sidecars: storageDir()/<kind>/<hash>/
        $dirs = [
            // undo/<hash>/ — owned by EditFront\Draft\UndoPayloadStore
            $base . '/undo/' . $hash,
        ];
        foreach ($dirs as $dir) {
            $this->removeTree($dir);
        }
    }

    /** Best-effort recursive remove of a sidecar directory. */
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($dir);
    }

    /**
     * Scan every site page for [data-news-list] containers and rewrite them
     * with freshly rendered cards (newest-first, honouring data-news-limit).
     * Template pages ([data-news-template]) are skipped. Returns the count of
     * pages actually rewritten.
     */
    private function refreshLists(): int
    {
        // Fix 2: the `published` flag gates card-visibility. Filter the store's
        // items to published-only BEFORE the single sortForOutput pass, so an
        // unpublished (draft) item never renders a card on any list container
        // (home `latest` or `all` listing). The article PAGE itself is still
        // written by save() — drafts are unlisted + noindex (Fix 1), not deleted.
        // Semantics: a missing/true `published` counts as published; only an
        // explicit `published === false` is excluded.
        $published = array_filter(
            $this->store->items(),
            static fn (array $it): bool => ($it['published'] ?? true) !== false,
        );
        $items = NewsStore::sortForOutput(array_values($published));
        $updated = 0;

        foreach ($this->pages->list(true) as $entry) {
            $rel = (string) $entry['path'];

            // Skip the template page and anything not a real page.
            if ($rel === $this->template->templatePath()) {
                continue;
            }

            $html = $this->storage->read($rel);
            $doc = $this->html5->parse($html);

            // Skip template-flagged pages.
            $htmlEl = $doc->getElementsByTagName('html')->item(0);
            if ($htmlEl instanceof \DOMElement && $htmlEl->hasAttribute(self::TEMPLATE_ATTR)) {
                continue;
            }

            $containers = $this->findListContainers($doc);
            if ($containers === []) {
                continue;
            }

            foreach ($containers as $container) {
                $this->fillContainer($doc, $container, $items);
            }

            $serialized = $this->html5->serialize($doc);

            // Mandatory pre-save backup before the atomic overwrite.
            $this->backups->preSave($rel, $html);
            $this->storage->atomicWrite($rel, $serialized);
            $updated++;
        }

        return $updated;
    }

    /**
     * Find every list container — either freshly authored ([data-news-list]) or
     * already-published ([data-news-rendered]) — so re-publish keeps refreshing
     * the same containers after their authoring markers were replaced by the
     * persistent re-find hook.
     *
     * @return list<\DOMElement>
     */
    private function findListContainers(\DOMDocument $doc): array
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
     * Clear a list container and insert cards for $items, honouring its limit.
     *
     * @param list<array<string,mixed>> $items already sorted newest-first
     */
    private function fillContainer(\DOMDocument $doc, \DOMElement $container, array $items): void
    {
        // Clear existing children.
        while ($container->firstChild !== null) {
            $container->removeChild($container->firstChild);
        }

        // Resolve mode + limit from authoring markers (first publish) or from the
        // persistent re-find hooks (subsequent publishes) BEFORE rewriting them.
        [$mode, $limit] = $this->resolveListConfig($container);

        $count = 0;
        foreach ($items as $item) {
            if ($limit !== null && $count >= $limit) {
                break;
            }
            // renderCard returns an element OWNED by the card skin's doc — import it.
            $card = $this->renderer->renderCard($item);
            $imported = $doc->importNode($card, true);
            $container->appendChild($imported);
            $count++;
        }

        // Replace the authoring markers with the persistent re-find hooks: none
        // of the six forbidden marker substrings survive in the published page,
        // yet the container stays re-findable for the next publish.
        $container->removeAttribute(self::LIST_ATTR);
        $container->removeAttribute(self::LIMIT_ATTR);
        $container->setAttribute(self::RENDERED_ATTR, $mode);
        $container->removeAttribute(self::RENDERED_LIMIT_ATTR);
        if ($limit !== null) {
            $container->setAttribute(self::RENDERED_LIMIT_ATTR, (string) $limit);
        }
    }

    /**
     * Resolve a container's list mode + card limit from whichever marker form is
     * present — the authoring markers (data-news-list / data-news-limit) on a
     * freshly authored page, or the persistent hooks (data-news-rendered /
     * data-news-rendered-limit) on an already-published page.
     *
     * data-news-limit honoured: a positive integer caps the count; "all" (or the
     * list value being 'all') ⇒ unlimited; absent ⇒ unlimited.
     *
     * @return array{0:string,1:?int} [mode, limit]
     */
    private function resolveListConfig(\DOMElement $container): array
    {
        if ($container->hasAttribute(self::LIST_ATTR)) {
            $mode = trim($container->getAttribute(self::LIST_ATTR));
            $rawLimit = $container->getAttribute(self::LIMIT_ATTR);
        } else {
            $mode = trim($container->getAttribute(self::RENDERED_ATTR));
            $rawLimit = $container->getAttribute(self::RENDERED_LIMIT_ATTR);
        }
        if ($mode === '') {
            $mode = 'all';
        }

        if (strtolower($mode) === 'all') {
            return [$mode, null];
        }
        $raw = trim($rawLimit);
        if ($raw === '' || strtolower($raw) === 'all') {
            return [$mode, null];
        }
        if (!ctype_digit($raw)) {
            return [$mode, null];
        }
        $n = (int) $raw;
        return [$mode, $n > 0 ? $n : null];
    }

    /**
     * Validate/normalize an input item into the canonical Item shape.
     * Mints id + unique slug for new items; sanitizes body_html.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function normalize(array $input): array
    {
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        if ($title === '') {
            throw new NewsException('title is required', 422);
        }

        $date = is_string($input['date'] ?? null) ? trim($input['date']) : '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new NewsException('date must be YYYY-MM-DD', 422);
        }

        // Existing item? keep its id + slug + created_at.
        $existing = null;
        $id = is_string($input['id'] ?? null) ? trim((string) $input['id']) : '';
        if ($id !== '') {
            $existing = $this->store->find($id);
            if ($existing === null) {
                throw new NewsException('news item not found: ' . $id, 404);
            }
        }

        if ($existing !== null) {
            $id = (string) $existing['id'];
            $slug = (string) $existing['slug'];
            $createdAt = (string) $existing['created_at'];
        } else {
            $id = $this->store->nextId();
            $existingSlugs = array_column($this->store->items(), 'slug');
            $base = $this->slug->slugify($title);
            $slug = $this->slug->unique($base, $existingSlugs);
            $createdAt = $this->nowIso();
        }

        $titleShort = is_string($input['title_short'] ?? null) ? trim($input['title_short']) : '';
        if ($titleShort === '') {
            $titleShort = $title;
        }

        $cover = is_string($input['cover'] ?? null) ? trim($input['cover']) : '';
        $coverOg = is_string($input['cover_og'] ?? null) ? trim($input['cover_og']) : '';
        if ($coverOg === '') {
            $coverOg = $cover;
        }

        $bodyRaw = is_string($input['body_html'] ?? null) ? $input['body_html'] : '';
        // NOTE: excerpt feeds the meta description (excerpt slot) only — the spec's
        // optional "excerpt also seeds a lead paragraph" ('при желании lead') is
        // intentionally NOT implemented. Recorded decision, not an oversight.
        $body = $this->sanitizer->sanitize($bodyRaw);

        return [
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'title_short' => $titleShort,
            'category' => is_string($input['category'] ?? null) ? trim($input['category']) : '',
            'date' => $date,
            'cover' => $cover,
            'cover_og' => $coverOg,
            'excerpt' => is_string($input['excerpt'] ?? null) ? trim($input['excerpt']) : '',
            'body_html' => $body,
            // Phase B: gallery URLs + position. Validation is reused VERBATIM
            // from NewsStore so this render-input item and the persisted store
            // item agree (the renderer trusts whatever gallery list it gets).
            'gallery' => NewsStore::normalizeGallery($input['gallery'] ?? null),
            'gallery_position' => NewsStore::normalizeGalleryPosition($input['gallery_position'] ?? null),
            'published' => (bool) ($input['published'] ?? false),
            'created_at' => $createdAt,
            'updated_at' => $this->nowIso(),
        ];
    }

    private function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
