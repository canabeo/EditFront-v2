<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Auth\AuthService;
use EditFront\Http\UrlHelper;
use EditFront\I18n\Translator;
use EditFront\Plugin\ModuleCtx;
use EditFront\Plugin\PluginManager;
use EditFront\Security\RateLimiter;
use EditFront\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * The core's whole HTTP surface for plugin modules (§6.9) — two routes for all
 * plugins, present or future:
 *
 *   POST {base}/api/p/{slug}/{action}
 *   GET  {base}/settings/p/{slug}
 *
 * Fixing the shape here is what makes a module safe to install by copying a
 * folder: a plugin cannot claim /api/save, cannot shadow a core screen, and
 * cannot exempt anything outside its own namespace from CSRF. It also means
 * adding a module needs no edit to bootstrap.php.
 *
 * Unknown slug, disabled or degraded plugin, or an action the manifest does not
 * declare → 404, deliberately indistinguishable: probing must not reveal which
 * plugins a site runs.
 *
 * A module throwing is caught here: logged with its message, answered with a
 * generic 500. A plugin fault must never surface a stack trace to a visitor,
 * and must never take the CMS down with it.
 */
final class PluginModuleController
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly AuthService $auth,
        private readonly Config $config,
        private readonly Environment $twig,
        private readonly Translator $i18n,
        private readonly UrlHelper $url,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** POST {base}/api/p/{slug}/{action} */
    public function api(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');
        $action = (string) ($args['action'] ?? '');

        $module = $this->plugins->module($slug);
        if ($module === null || !$this->plugins->moduleHasAction($slug, $action)) {
            return $this->notFound($response);
        }

        // The route lives outside the auth group so a visitor's form can reach
        // the public actions — which means every OTHER action has to be guarded
        // right here. Without this, "declared but not public" would be protected
        // by nothing but the CSRF token, and a token is not a login.
        if (!$this->plugins->moduleActionIsPublic($slug, $action) && !$this->auth->check()) {
            $response->getBody()->write((string) json_encode(['error' => 'Unauthorized']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        try {
            return $module->handle($action, $request, $response, $this->ctx($slug));
        } catch (\Throwable $e) {
            $this->logger->error('plugin.module_failed', [
                'slug' => $slug,
                'action' => $action,
                'message' => $e->getMessage(),
            ]);
            $response->getBody()->write((string) json_encode(['error' => 'plugin error']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /** GET {base}/settings/p/{slug} — inside the auth group, like every settings screen. */
    public function page(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = (string) ($args['slug'] ?? '');
        $module = $this->plugins->module($slug);
        if ($module === null) {
            return $this->notFound($response);
        }

        try {
            return $module->page($request, $response, $this->ctx($slug));
        } catch (\Throwable $e) {
            $this->logger->error('plugin.module_page_failed', ['slug' => $slug, 'message' => $e->getMessage()]);
            return $response->withStatus(500);
        }
    }

    private function ctx(string $slug): ModuleCtx
    {
        return new ModuleCtx(
            $slug,
            $this->config->cmsDir() . '/plugins/' . $slug,
            $this->config,
            $this->twig,
            $this->i18n,
            $this->url,
            $this->limiter,
            $this->logger,
        );
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(['error' => 'not found']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }
}
