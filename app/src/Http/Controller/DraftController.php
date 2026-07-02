<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Draft\DraftService;
use EditFront\Draft\UndoPayloadStore;
use EditFront\Security\RateLimiter;
use EditFront\Storage\FileStorage;
use EditFront\Storage\StorageException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DraftController
{
    public function __construct(
        private readonly DraftService $drafts,
        private readonly UndoPayloadStore $payloads,
        private readonly FileStorage $storage,
        private readonly RateLimiter $limiter,
    ) {
    }

    /** GET /api/draft?page=X → {draft|null, current_sha256} */
    public function get(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $page = $this->pageParam($request->getQueryParams()['page'] ?? null);
        if ($page === null) {
            return $this->json($response, 422, ['error' => 'page required']);
        }
        try {
            $currentSha = hash('sha256', $this->storage->read($page));
        } catch (StorageException) {
            return $this->json($response, 404, ['error' => 'page not found']);
        }
        return $this->json($response, 200, [
            'draft' => $this->drafts->load($page),
            'current_sha256' => $currentSha,
        ]);
    }

    /** POST /api/draft {page, draft:{base_sha256, serial, cursor, entries}} */
    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->allow($request, 'draft', 120)) {
            return $this->json($response, 429, ['error' => 'too many draft saves']);
        }
        $body = (array) $request->getParsedBody();
        $page = $this->pageParam($body['page'] ?? null);
        $draft = $body['draft'] ?? null;
        if ($page === null || !is_array($draft)) {
            return $this->json($response, 422, ['error' => 'page and draft required']);
        }
        try {
            $this->drafts->save($page, $draft);
        } catch (StorageException $e) {
            return $this->json($response, 422, ['error' => $e->getMessage()]);
        }
        return $this->json($response, 200, ['ok' => true]);
    }

    /** POST /api/draft/delete {page} */
    public function delete(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $page = $this->pageParam($body['page'] ?? null);
        if ($page === null) {
            return $this->json($response, 422, ['error' => 'page required']);
        }
        $this->drafts->delete($page);
        return $this->json($response, 200, ['ok' => true]);
    }

    /** POST /api/undo-payload {page, cmd_id, payload} — heavy command offload (§7.2) */
    public function payloadSave(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->allow($request, 'undo-payload', 60)) {
            return $this->json($response, 429, ['error' => 'too many payload saves']);
        }
        $body = (array) $request->getParsedBody();
        $page = $this->pageParam($body['page'] ?? null);
        $cmdId = $body['cmd_id'] ?? null;
        $payload = $body['payload'] ?? null;
        if ($page === null || !is_string($cmdId) || !is_string($payload) || $payload === '') {
            return $this->json($response, 422, ['error' => 'page, cmd_id and payload required']);
        }
        try {
            $this->payloads->save($page, $cmdId, $payload);
        } catch (StorageException $e) {
            return $this->json($response, 422, ['error' => $e->getMessage()]);
        }
        return $this->json($response, 200, ['ok' => true]);
    }

    /** GET /api/undo-payload?page=X&cmd_id=Y → {payload|null} */
    public function payloadGet(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $page = $this->pageParam($params['page'] ?? null);
        $cmdId = $params['cmd_id'] ?? null;
        if ($page === null || !is_string($cmdId)) {
            return $this->json($response, 422, ['error' => 'page and cmd_id required']);
        }
        try {
            $payload = $this->payloads->load($page, $cmdId);
        } catch (StorageException $e) {
            return $this->json($response, 422, ['error' => $e->getMessage()]);
        }
        return $this->json($response, 200, ['payload' => $payload]);
    }

    private function pageParam(mixed $page): ?string
    {
        return is_string($page) && $page !== '' && strlen($page) <= 300 ? $page : null;
    }

    private function allow(ServerRequestInterface $request, string $bucket, int $max): bool
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        return $this->limiter->allow($bucket, $ip, $max, 60);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
