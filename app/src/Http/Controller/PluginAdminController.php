<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Auth\AuthService;
use EditFront\Plugin\PluginException;
use EditFront\Plugin\PluginInstaller;
use EditFront\Plugin\PluginInstallException;
use EditFront\Plugin\PluginManager;
use EditFront\Security\RateLimiter;
use EditFront\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * Plugin management admin (§6.6): list installed plugins + their boot status,
 * enable/disable, uninstall, and install from an uploaded ZIP. All under auth +
 * CSRF; writes are rate-limited. The toggle/uninstall/install only mutate the
 * filesystem/registry — the verdict (enabled/degraded/error) is recomputed by
 * the NEXT request's boot, so the page reloads to show the new state.
 */
final class PluginAdminController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly PluginManager $plugins,
        private readonly PluginInstaller $installer,
        private readonly AuthService $auth,
        private readonly RateLimiter $limiter,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function page(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->twig->render('plugins.twig', [
            'plugins' => $this->plugins->adminList(),
            'user' => $this->auth->user(),
        ]));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function toggle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($r = $this->throttle($request, $response)) !== null) {
            return $r;
        }
        $body = (array) $request->getParsedBody();
        $slug = is_string($body['slug'] ?? null) ? $body['slug'] : '';
        $enabled = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($slug === '') {
            return $this->json($response, 422, ['error' => 'slug is required']);
        }
        try {
            $this->plugins->setEnabled($slug, $enabled);
        } catch (PluginException $e) {
            return $this->json($response, 422, ['error' => $e->getMessage()]);
        }
        $this->logger->info('plugin.toggle', ['slug' => $slug, 'enabled' => $enabled]);
        return $this->json($response, 200, ['ok' => true, 'slug' => $slug, 'enabled' => $enabled]);
    }

    public function uninstall(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (($r = $this->throttle($request, $response)) !== null) {
            return $r;
        }
        $body = (array) $request->getParsedBody();
        $slug = is_string($body['slug'] ?? null) ? $body['slug'] : '';
        if ($slug === '') {
            return $this->json($response, 422, ['error' => 'slug is required']);
        }
        try {
            $this->installer->uninstall($slug);
        } catch (PluginInstallException $e) {
            return $this->json($response, $e->status, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('plugin.uninstall_failed', ['slug' => $slug, 'message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'could not uninstall plugin']);
        }
        return $this->json($response, 200, ['ok' => true, 'slug' => $slug]);
    }

    public function install(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->limiter->allow('plugin-install', $ip, 10, 60)) {
            return $this->json($response, 429, ['error' => 'Too many install attempts, slow down']);
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return $this->json($response, 422, ['error' => 'no file uploaded (field "file")']);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, 422, ['error' => 'upload error code ' . $file->getError()]);
        }

        $tmpDir = $this->config->storageDir() . '/tmp';
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0770, true) && !is_dir($tmpDir)) {
            return $this->json($response, 500, ['error' => 'cannot create temp dir']);
        }
        $tmp = $tmpDir . '/upload-' . bin2hex(random_bytes(6)) . '.zip';
        try {
            $file->moveTo($tmp);
            $result = $this->installer->install($tmp);
        } catch (PluginInstallException $e) {
            return $this->json($response, $e->status, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('plugin.install_failed', ['message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'install failed']);
        } finally {
            @unlink($tmp);
        }
        return $this->json($response, 200, ['ok' => true] + $result);
    }

    private function throttle(ServerRequestInterface $request, ResponseInterface $response): ?ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->limiter->allow('plugin-admin', $ip, 30, 60)) {
            return $this->json($response, 429, ['error' => 'Too many requests, slow down']);
        }
        return null;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
