<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Document\ConflictException;
use EditFront\Document\SaveService;
use EditFront\Operation\OperationRegistry;
use EditFront\Operation\OperationValidationException;
use EditFront\Security\RateLimiter;
use EditFront\Storage\StorageException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class ApiController
{
    public function __construct(
        private readonly SaveService $saver,
        private readonly OperationRegistry $registry,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->limiter->allow('save', $ip, 60, 60)) {
            return $this->json($response, 429, ['error' => 'Too many save requests, slow down']);
        }

        $body = (array) $request->getParsedBody();
        $page = (string) ($body['page'] ?? '');
        $baseSha = (string) ($body['base_sha256'] ?? '');
        $ops = $body['ops'] ?? null;
        $force = filter_var($body['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($page === '' || !is_array($ops)) {
            return $this->json($response, 422, ['error' => 'page and ops are required']);
        }

        try {
            $result = $this->saver->save($page, $baseSha, array_values($ops), $force);
        } catch (ConflictException $e) {
            return $this->json($response, 409, [
                'error' => 'conflict',
                'current_sha256' => $e->currentSha256,
            ]);
        } catch (OperationValidationException $e) {
            return $this->json($response, 422, ['error' => $e->getMessage()]);
        } catch (StorageException $e) {
            // own safe message (path rejected / backup failed → save aborted)
            $this->logger->error('save.storage_error', ['page' => $page, 'message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => $e->getMessage()]);
        }

        $this->logger->info('page.save', [
            'page' => $page,
            'applied' => $result['applied'],
            'warnings' => count($result['warnings']),
            'backup_id' => $result['backup_id'],
        ]);

        return $this->json($response, 200, ['ok' => true] + $result);
    }

    public function opSchema(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, 200, ['ops' => $this->registry->manifest()]);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
