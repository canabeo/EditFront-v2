<?php

declare(strict_types=1);

namespace EditFront\Document;

use EditFront\Operation\OperationApplyException;
use EditFront\Operation\OperationRegistry;
use EditFront\Operation\OperationValidationException;
use EditFront\Operation\SchemaValidator;
use EditFront\Plugin\PluginManager;
use EditFront\Plugin\PropsStore;
use EditFront\Storage\BackupService;
use EditFront\Storage\FileStorage;
use EditFront\Storage\Journal;
use Psr\Log\LoggerInterface;

/**
 * Fail-closed save pipeline (§4.3):
 * optimistic lock → validate ALL ops (unknown op/field rejects the batch) →
 * MANDATORY verified pre-save backup → apply in journal order → canonical
 * serialize → atomic write → journal append.
 * applyOps NEVER generates ids (invariant 0.2.1).
 *
 * After applying, the plugin props sidecar is reconciled FROM the DOM (§6.5):
 * the rendered node is the truth, the sidecar is derived — so it can never
 * drift, and a deleted plugin node loses its props entry automatically.
 */
final class SaveService
{
    private const MAX_OPS = 200;

    public function __construct(
        private readonly FileStorage $storage,
        private readonly Html5 $html5,
        private readonly Annotator $annotator,
        private readonly OperationRegistry $registry,
        private readonly SchemaValidator $validator,
        private readonly BackupService $backups,
        private readonly Journal $journal,
        private readonly PluginManager $plugins,
        private readonly PropsStore $props,
        private readonly StateCssRenderer $stateCss,
        private readonly FontFaceRenderer $fontFaces,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $ops
     * @return array{applied: int, warnings: list<string>, new_sha256: string, backup_id: string}
     */
    public function save(string $relPath, string $baseSha256, array $ops, bool $force = false): array
    {
        $current = $this->storage->read($relPath);

        if (!$force && !hash_equals(hash('sha256', $current), $baseSha256)) {
            throw new ConflictException(hash('sha256', $current));
        }
        if ($ops === []) {
            throw new OperationValidationException('empty ops list');
        }
        if (count($ops) > self::MAX_OPS) {
            throw new OperationValidationException('too many ops in one save');
        }

        // 1. pre-validate the whole batch before touching anything
        $parsed = [];
        foreach ($ops as $i => $raw) {
            if (!is_array($raw)) {
                throw new OperationValidationException("op#$i: not an object");
            }
            $key = $raw['op'] ?? null;
            if (!is_string($key)) {
                throw new OperationValidationException("op#$i: missing op key");
            }
            $spec = $this->registry->get($key);
            if ($spec === null) {
                throw new OperationValidationException("op#$i: unknown operation '$key'");
            }
            $targetId = $raw['target'] ?? null;
            if ($targetId !== null && (!is_string($targetId) || !$this->annotator->isValidId($targetId))) {
                throw new OperationValidationException("op#$i: invalid target id");
            }
            if ($spec->targetRequired() && $targetId === null) {
                throw new OperationValidationException("op#$i: target required for '$key'");
            }
            $forward = $raw['forward'] ?? [];
            if (!is_array($forward)) {
                throw new OperationValidationException("op#$i: forward must be an object");
            }
            // validate the RAW payload first — sanitize() may rebuild the array
            // and would silently swallow unknown keys (whitelist-drift gate)
            $errors = $this->validator->validate($spec->schema(), $forward);
            if ($errors !== []) {
                throw new OperationValidationException("op#$i ($key): " . implode('; ', $errors));
            }
            $forward = $spec->sanitize($forward);
            $parsed[] = [$key, $spec, $targetId, $forward];
        }

        // 2. no verified backup → no save
        $backupId = $this->backups->preSave($relPath, $current);

        // 3. apply in order
        $doc = $this->html5->parse($current);
        $applied = 0;
        $warnings = [];
        $journalOps = [];
        foreach ($parsed as $i => [$key, $spec, $targetId, $forward]) {
            try {
                $target = null;
                if ($spec->targetRequired()) {
                    $target = $this->annotator->findById($doc, (string) $targetId);
                    if ($target === null) {
                        $warnings[] = "op#$i ($key): target not found: $targetId";
                        continue;
                    }
                    if (!$this->annotator->isEditable($target)) {
                        $warnings[] = "op#$i ($key): target is protected: $targetId";
                        continue;
                    }
                }
                $spec->apply($doc, $target, $forward);
                $applied++;
                $journalOps[] = ['op' => $key, 'target' => $targetId, 'forward' => $forward];
            } catch (OperationApplyException $e) {
                $warnings[] = "op#$i ($key): " . $e->getMessage();
            }
        }

        // 4. reconcile plugin props sidecar from the final DOM (§6.5) — best-effort
        $this->syncPluginProps($relPath, $doc, $warnings);

        // 4b. (re)render hover-state CSS from data-cms-hover into <head> so hover
        // works on the live static page (drop-in) and stays idempotent
        $this->stateCss->apply($doc);

        // 4c. (re)render the @font-face block for self-hosted fonts into <head>
        $this->fontFaces->apply($doc);

        // 5. canonical serialize + atomic write
        $html = $this->html5->serialize($doc);
        $this->storage->atomicWrite($relPath, $html);

        // 6. history (best-effort)
        $batchId = bin2hex(random_bytes(8));
        $this->journal->append($relPath, $journalOps, $batchId);

        if ($warnings !== []) {
            $this->logger->warning('save.warnings', ['page' => $relPath, 'warnings' => $warnings]);
        }

        return [
            'applied' => $applied,
            'warnings' => $warnings,
            'new_sha256' => hash('sha256', $html),
            'backup_id' => $backupId,
        ];
    }

    /** @param list<string> $warnings (mutated) */
    private function syncPluginProps(string $relPath, \DOMDocument $doc, array &$warnings): void
    {
        try {
            $this->plugins->boot();
            $types = $this->plugins->elementTypes();
            $map = [];
            $xpath = new \DOMXPath($doc);
            foreach ($xpath->query('//*[@data-cms-block]') ?: [] as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                $id = $node->getAttribute(Annotator::ID_ATTR);
                $kindName = $node->getAttribute('data-cms-block');
                if ($id === '') {
                    continue;
                }
                $rk = $types->get($kindName);
                if ($rk === null) {
                    continue; // unknown kind → stays static HTML, no sidecar entry
                }
                try {
                    $props = $rk->block()->serialize($node);
                } catch (\Throwable $e) {
                    $warnings[] = "props sync ($kindName/$id): " . $e->getMessage();
                    continue;
                }
                if ($rk->propsSchema()->validate($props) !== []) {
                    $warnings[] = "props sync ($kindName/$id): invalid props, skipped";
                    continue;
                }
                $map[$id] = ['kind' => $kindName, 'version' => $rk->version(), 'props' => $props];
            }
            $this->props->sync($relPath, $map);
        } catch (\Throwable $e) {
            // never abort a save over the sidecar
            $this->logger->warning('props.sync_failed', ['page' => $relPath, 'error' => $e->getMessage()]);
        }
    }
}
