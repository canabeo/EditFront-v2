<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Document\RestoreService;
use EditFront\Security\RateLimiter;
use EditFront\Storage\BackupService;
use EditFront\Storage\StorageException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Pre-save backup listing + restore (§9 C5). GET /api/backups?page=X lists the
 * snapshots; POST /api/restore restores one (RestoreService backs up the
 * current version first). CSRF via middleware; restore is rate-limited.
 */
final class BackupController
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly RestoreService $restore,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $page = (string) ($request->getQueryParams()['page'] ?? '');
        if ($page === '') {
            return $this->json($response, 422, ['error' => 'page is required']);
        }
        $items = array_map(
            static fn (array $b): array => ['id' => $b['id'], 'mtime' => $b['mtime'], 'size' => $b['size']],
            $this->backups->listForPage($page)
        );
        return $this->json($response, 200, ['backups' => $items]);
    }

    public function restorePage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->limiter->allow('restore', $ip, 20, 60)) {
            return $this->json($response, 429, ['error' => 'Too many restore requests, slow down']);
        }

        $body = (array) $request->getParsedBody();
        $page = is_string($body['page'] ?? null) ? $body['page'] : '';
        $id = is_string($body['id'] ?? null) ? $body['id'] : '';
        if ($page === '' || $id === '') {
            return $this->json($response, 422, ['error' => 'page and id are required']);
        }

        try {
            $result = $this->restore->restore($page, $id);
        } catch (StorageException $e) {
            return $this->json($response, 422, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('restore.failed', ['page' => $page, 'id' => $id, 'message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'restore failed']);
        }

        $this->logger->info('page.restore', ['page' => $page, 'id' => $id, 'pre' => $result['pre_restore_backup_id']]);
        return $this->json($response, 200, ['ok' => true] + $result);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
