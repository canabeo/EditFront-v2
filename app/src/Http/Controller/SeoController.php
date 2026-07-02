<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Seo\RobotsFile;
use EditFront\Seo\SeoException;
use EditFront\Seo\SeoService;
use EditFront\Security\RateLimiter;
use EditFront\Storage\InvalidPathException;
use EditFront\Storage\StorageException;
use EditFront\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Per-page basic SEO (§9): GET loads the current head fields, POST rewrites them
 * (title/description/canonical/robots) and ensures robots.txt advertises the
 * sitemap. CSRF is enforced by middleware; here we add a per-IP write rate limit.
 * The POST returns the new sha256 so the editor can keep its optimistic-lock
 * baseline current (a later content save then won't see a false conflict).
 */
final class SeoController
{
    public function __construct(
        private readonly SeoService $seo,
        private readonly RobotsFile $robots,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function load(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $page = (string) ($request->getQueryParams()['page'] ?? '');
        if ($page === '') {
            return $this->json($response, 422, ['error' => 'page is required']);
        }
        try {
            $seo = $this->seo->read($page);
        } catch (InvalidPathException) {
            return $this->json($response, 422, ['error' => 'invalid page path']);
        } catch (StorageException) {
            return $this->json($response, 404, ['error' => 'page not found']);
        } catch (\Throwable $e) {
            $this->logger->error('seo.read_failed', ['page' => $page, 'message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'could not read SEO']);
        }
        return $this->json($response, 200, ['ok' => true, 'seo' => $seo]);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->limiter->allow('seo', $ip, 30, 60)) {
            return $this->json($response, 429, ['error' => 'Too many SEO saves, slow down']);
        }

        $body = (array) $request->getParsedBody();
        $page = (string) ($body['page'] ?? '');
        if ($page === '') {
            return $this->json($response, 422, ['error' => 'page is required']);
        }
        $fields = is_array($body['seo'] ?? null) ? $body['seo'] : [];

        try {
            $result = $this->seo->save($page, $fields);
        } catch (SeoException $e) {
            return $this->json($response, 422, ['error' => $e->getMessage()]);
        } catch (InvalidPathException) {
            return $this->json($response, 422, ['error' => 'invalid page path']);
        } catch (StorageException) {
            return $this->json($response, 404, ['error' => 'page not found']);
        } catch (\Throwable $e) {
            $this->logger->error('seo.save_failed', ['page' => $page, 'message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'could not save SEO']);
        }

        // advertise the sitemap so new/updated pages get indexed (best-effort)
        try {
            $this->robots->ensure($this->baseUrl($request));
        } catch (\Throwable $e) {
            $this->logger->warning('seo.robots_failed', ['message' => $e->getMessage()]);
        }

        $this->logger->info('seo.save', ['page' => $page]);
        return $this->json($response, 200, ['ok' => true] + $result);
    }

    private function baseUrl(ServerRequestInterface $request): string
    {
        $base = trim((string) $this->config->get('site_base_url', ''));
        if ($base === '') {
            $uri = $request->getUri();
            $base = $uri->getScheme() . '://' . $uri->getAuthority();
        }
        return rtrim($base, '/');
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
