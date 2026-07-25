<?php

declare(strict_types=1);

namespace EditFront\Document;

use EditFront\Draft\DraftService;
use EditFront\Plugin\PropsStore;
use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\InvalidPathException;
use EditFront\Storage\Journal;
use EditFront\Storage\PageExistsException;
use EditFront\Storage\PagesIndex;
use EditFront\Storage\PathGuard;
use EditFront\Storage\StorageException;
use EditFront\Support\Config;

/**
 * Page-level CRUD from the dashboard (§4.8): create (blank or clone), duplicate,
 * delete. A new page is stamped with persistent ids BEFORE it is written
 * (v1 lesson eac3589: pages created from a raw template had no ids, so the
 * first insert could not find a refId). Delete makes a MANDATORY pre-delete
 * backup and then cleans up every sidecar of the page (meta/journal/drafts/undo)
 * and invalidates the pages-index cache.
 *
 * Basename pattern + PathGuard guard the path; creating inside EXISTING
 * subfolders is allowed, creating new subfolders is not (MVP).
 */
final class PageService
{
    /**
     * Matches the spec basename rule; the dir part (if any) must already exist.
     * Public because DocumentService gates editor opens on the same rule — one
     * definition, so the two can never drift apart.
     */
    public const NAME_RE = '~^[a-zA-Z0-9][a-zA-Z0-9_.\-]{0,99}\.html?$~';

    private const BLANK = "<!doctype html>\n<html lang=\"ru\">\n<head>\n"
        . "<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . "<title>Новая страница</title>\n</head>\n<body>\n<main>\n"
        . "<h1>Новая страница</h1>\n"
        . "<p>Это новая страница — кликните по тексту, чтобы отредактировать.</p>\n"
        . "</main>\n</body>\n</html>\n";

    public function __construct(
        private readonly Config $config,
        private readonly FileStorage $storage,
        private readonly Html5 $html5,
        private readonly Annotator $annotator,
        private readonly BackupService $backups,
        private readonly PagesIndex $pages,
        private readonly PropsStore $props,
        private readonly DraftService $drafts,
        private readonly Journal $journal,
        private readonly PathGuard $guard,
    ) {
    }

    /**
     * Create a page: clone $source when given (priority), otherwise a blank
     * minimal template. The result is annotated (ids on disk before open).
     * @return array{path: string, size: int}
     */
    public function create(string $path, ?string $source = null): array
    {
        $this->validateTarget($path);

        if ($source !== null && $source !== '') {
            try {
                $this->guard->assertSafeRelative($source);
            } catch (InvalidPathException $e) {
                throw new PageCrudException('invalid source path', 422);
            }
            if (!$this->storage->exists($source)) {
                throw new PageCrudException('source page not found: ' . $source, 404);
            }
            $raw = $this->storage->read($source);
        } else {
            $raw = self::BLANK;
        }

        $annotated = $this->annotate($raw);

        try {
            $this->storage->createNew($path, $annotated);
        } catch (PageExistsException $e) {
            throw new PageCrudException($e->getMessage(), 409);
        }

        // A path can be re-created after a delete (or after an out-of-band rm).
        // Drop any stale sidecars left under the same sha1(path) so the new page
        // never inherits a deleted namesake's props/journal/draft (review M1).
        $this->props->delete($path);
        $this->drafts->delete($path);
        $this->journal->delete($path);
        $this->pages->invalidate();

        return ['path' => $path, 'size' => strlen($annotated)];
    }

    /** @return array{path: string, size: int} */
    public function duplicate(string $source, string $target): array
    {
        return $this->create($target, $source);
    }

    /**
     * Delete a page after a mandatory pre-delete backup, then drop all sidecars.
     * @return array{backup_id: string}
     */
    public function delete(string $path): array
    {
        // Same rule as create(): the basename must look like a page. Without it
        // delete() accepted ANY legal relative path under site_root -- and since
        // the CMS lives INSIDE site_root, "cms/storage/admin.json" needs no
        // traversal to reach. Removing that file flips isInstalled() back to
        // false and re-opens the install wizard to anonymous visitors.
        $this->validateTarget($path);
        if (!$this->storage->exists($path)) {
            throw new PageCrudException('page not found: ' . $path, 404);
        }

        // mandatory pre-delete backup — same mechanism as pre-save (§4.8)
        $current = $this->storage->read($path);
        $backupId = $this->backups->preSave($path, $current);

        $this->storage->delete($path);

        // cleanup every per-page sidecar (drafts.delete also clears undo payloads)
        $this->props->delete($path);
        $this->drafts->delete($path);
        $this->journal->delete($path);
        $this->pages->invalidate();

        return ['backup_id' => $backupId];
    }

    /** Stamp persistent ids onto the content before it ever reaches disk. */
    private function annotate(string $raw): string
    {
        $doc = $this->html5->parse($raw);
        $this->annotator->ensureAnnotated($doc);
        return $this->html5->serialize($doc);
    }

    private function validateTarget(string $path): void
    {
        try {
            $this->guard->assertSafeRelative($path);
        } catch (InvalidPathException $e) {
            throw new PageCrudException($e->getMessage(), 422);
        }

        $base = basename($path);
        if (preg_match(self::NAME_RE, $base) !== 1) {
            throw new PageCrudException('invalid page name (allowed: letters/digits/._- + .html)', 422);
        }

        $dir = \str_replace('\\', '/', \dirname($path));
        if ($dir !== '.' && $dir !== '') {
            // creating inside an EXISTING subfolder is fine; a new subfolder is not (MVP)
            try {
                $this->guard->resolveWithin($this->config->siteRoot(), $dir, true);
            } catch (StorageException) {
                throw new PageCrudException('subfolder does not exist (creating new folders is not supported): ' . $dir, 422);
            }
        }
    }
}
