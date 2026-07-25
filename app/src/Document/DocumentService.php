<?php

declare(strict_types=1);

namespace EditFront\Document;

use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\PagesIndex;
use EditFront\Storage\StorageException;
use EditFront\Support\Config;

/**
 * Opening a page for editing stamps persistent ids to DISK (invariant 0.2.1,
 * v1 pristine-first-edit lesson): save never re-annotates, so ids must exist
 * on disk before the first op is ever emitted. Idempotent: an already-stamped
 * file is returned untouched, byte-for-byte.
 */
final class DocumentService
{
    public function __construct(
        private readonly Config $config,
        private readonly FileStorage $storage,
        private readonly Html5 $html5,
        private readonly Annotator $annotator,
        private readonly TagAnnotatorLite $lite,
        private readonly BackupService $backups,
        private readonly PagesIndex $pages,
    ) {
    }

    /**
     * Opening a page WRITES to it (ids are stamped to disk), and it happens on a
     * GET, which CSRF cannot cover by design. So the target has to be
     * constrained here: an attacker page that merely links a logged-in admin to
     * /edit?page=<something> would otherwise rewrite that file through the
     * HTML5 parser — .svg, .xml, another app's templates — with a single
     * top-level navigation and no click inside the editor (session cookie is
     * SameSite=Lax).
     *
     * Two conditions, both cheap: it must look like a page, and it must be one
     * of the pages the CMS actually lists.
     */
    private function assertEditable(string $relPath): void
    {
        if (preg_match(PageService::NAME_RE, basename($relPath)) !== 1) {
            throw new StorageException('not an editable page: ' . $relPath);
        }

        $normalized = str_replace('\\', '/', $relPath);
        foreach ($this->pages->list() as $page) {
            if ($page['path'] === $normalized) {
                return;
            }
        }
        // Cache miss: a page created seconds ago must not 404, so confirm
        // against a fresh scan before refusing.
        foreach ($this->pages->list(true) as $page) {
            if ($page['path'] === $normalized) {
                return;
            }
        }
        throw new StorageException('not a known page: ' . $relPath);
    }

    public function openForEdit(string $relPath): string
    {
        $this->assertEditable($relPath);
        $raw = $this->storage->read($relPath);

        if ((bool) $this->config->get('annotate_only', false)) {
            [$html, $changed] = $this->lite->annotate($raw);
        } else {
            $doc = $this->html5->parse($raw);
            $changed = $this->annotator->ensureAnnotated($doc);
            // unchanged → keep the original bytes, no rewrite at all
            $html = $changed ? $this->html5->serialize($doc) : $raw;
        }

        if ($changed && $html !== $raw) {
            // mandatory pre-stamp backup; failure aborts the open-write
            $this->backups->preSave($relPath, $raw);
            $this->storage->atomicWrite($relPath, $html);
        }

        return $html;
    }
}
