<?php

declare(strict_types=1);

namespace EditFront\Document;

use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;

/**
 * Restore a page from a pre-save snapshot (§9 C5). The CURRENT content is
 * backed up FIRST, before the overwrite (v1 F4-bugfix7 lesson: restore that
 * skips the pre-restore backup loses the live version irreversibly). If the
 * page was deleted, restore re-creates it (no current state to back up).
 */
final class RestoreService
{
    public function __construct(
        private readonly FileStorage $storage,
        private readonly BackupService $backups,
    ) {
    }

    /**
     * @return array{new_sha256: string, pre_restore_backup_id: string}
     * @throws \EditFront\Storage\StorageException on bad id / missing snapshot / unwritable page
     */
    public function restore(string $relPath, string $id): array
    {
        // throws if the id is malformed or the snapshot is missing — before any write
        $content = $this->backups->readContent($relPath, $id);

        // pre-restore backup of the CURRENT version (skip only if the page is gone)
        $preId = '';
        if ($this->storage->exists($relPath)) {
            $preId = $this->backups->preSave($relPath, $this->storage->read($relPath));
        }

        $this->storage->atomicWrite($relPath, $content);

        return [
            'new_sha256' => hash('sha256', $content),
            'pre_restore_backup_id' => $preId,
        ];
    }
}
