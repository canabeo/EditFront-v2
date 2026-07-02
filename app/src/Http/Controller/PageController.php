<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Document\PageCrudException;
use EditFront\Document\PageService;
use EditFront\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Dashboard page CRUD (§4.8). Thin: all logic + validation lives in
 * PageService (unit-tested). CSRF is enforced by middleware; here we add a
 * per-IP rate limit (20/min) shared across create/duplicate/delete.
 */
final class PageController
{
    public function __construct(
        private readonly PageService $pages,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($r = $this->throttle($request, $response)) !== null) {
            return $r;
        }
        $body = (array) $request->getParsedBody();
        $path = $this->str($body['path'] ?? '');
        $source = $this->str($body['source'] ?? '');

        if ($path === '') {
            return $this->json($response, 422, ['error' => 'path is required']);
        }
        try {
            $result = $this->pages->create($path, $source !== '' ? $source : null);
        } catch (PageCrudException $e) {
            return $this->json($response, $e->status, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('page.create_failed', ['path' => $path, 'message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'could not create page']);
        }
        $this->logger->info('page.create', ['path' => $path, 'cloned' => $source !== '']);
        return $this->json($response, 200, ['ok' => true] + $result);
    }

    public function duplicate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($r = $this->throttle($request, $response)) !== null) {
            return $r;
        }
        $body = (array) $request->getParsedBody();
        $source = $this->str($body['source'] ?? '');
        $target = $this->str($body['target'] ?? '');

        if ($source === '' || $target === '') {
            return $this->json($response, 422, ['error' => 'source and target are required']);
        }
        try {
            $result = $this->pages->duplicate($source, $target);
        } catch (PageCrudException $e) {
            return $this->json($response, $e->status, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('page.duplicate_failed', ['source' => $source, 'target' => $target, 'message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'could not duplicate page']);
        }
        $this->logger->info('page.duplicate', ['source' => $source, 'target' => $target]);
        return $this->json($response, 200, ['ok' => true] + $result);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($r = $this->throttle($request, $response)) !== null) {
            return $r;
        }
        $body = (array) $request->getParsedBody();
        $path = $this->str($body['path'] ?? '');
        if ($path === '') {
            return $this->json($response, 422, ['error' => 'path is required']);
        }
        try {
            $result = $this->pages->delete($path);
        } catch (PageCrudException $e) {
            return $this->json($response, $e->status, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('page.delete_failed', ['path' => $path, 'message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'could not delete page']);
        }
        $this->logger->info('page.delete', ['path' => $path, 'backup_id' => $result['backup_id']]);
        return $this->json($response, 200, ['ok' => true] + $result);
    }

    private function throttle(ServerRequestInterface $request, ResponseInterface $response): ?ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->limiter->allow('page-crud', $ip, 20, 60)) {
            return $this->json($response, 429, ['error' => 'Too many page operations, slow down']);
        }
        return null;
    }

    private function str(mixed $v): string
    {
        return is_string($v) ? trim($v) : '';
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
