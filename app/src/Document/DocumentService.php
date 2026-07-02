<?php

declare(strict_types=1);

namespace EditFront\Document;

use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
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
    ) {
    }

    public function openForEdit(string $relPath): string
    {
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
