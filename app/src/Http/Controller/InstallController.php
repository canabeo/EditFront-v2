<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Http\UrlHelper;
use EditFront\Install\AdminCreator;
use EditFront\Install\InstallException;
use EditFront\Install\SelfCheck;
use EditFront\Security\RateLimiter;
use EditFront\Storage\PagesIndex;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * 3-step install wizard (§9 C5): self-check → page detection → admin creation.
 * Once installed, /install returns 410 Gone (the wizard is single-use) and a
 * re-install attempt is refused by the install lock (AdminCreator).
 */
final class InstallController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly SelfCheck $selfCheck,
        private readonly AdminCreator $creator,
        private readonly PagesIndex $pages,
        private readonly UrlHelper $url,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->creator->isInstalled()) {
            $response->getBody()->write($this->twig->render('install.twig', ['mode' => 'gone']));
            return $response->withStatus(410)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        $checks = $this->selfCheck->run();
        $response->getBody()->write($this->twig->render('install.twig', [
            'mode' => 'wizard',
            'checks' => $checks,
            'can_proceed' => $this->selfCheck->canProceed(),
            'pages_count' => count($this->pages->list(true)),
            'probe' => $this->selfCheck->prepareExposureProbe(),
        ]));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->limiter->allow('install', $ip, 10, 60)) {
            return $this->json($response, 429, ['error' => 'Too many attempts, slow down']);
        }
        if ($this->creator->isInstalled()) {
            return $this->json($response, 410, ['error' => 'already installed']);
        }

        $body = (array) $request->getParsedBody();
        $username = is_string($body['username'] ?? null) ? $body['username'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $annotateOnly = filter_var($body['annotate_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $result = $this->creator->install($username, $password, ['annotate_only' => $annotateOnly]);
        } catch (InstallException $e) {
            return $this->json($response, $e->status, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('install.failed', ['message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'install failed']);
        }

        $this->selfCheck->clearExposureProbe();
        $this->logger->info('install.ok', ['user' => $username, 'secret' => $result['secret_persisted']]);
        return $this->json($response, 200, [
            'ok' => true,
            'redirect' => $this->url->path('login'),
            'env_writable' => $result['env_writable'],
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
