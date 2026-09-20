<?php

declare(strict_types=1);

namespace EditFront\Plugin;

use EditFront\Http\UrlHelper;
use EditFront\I18n\Translator;
use EditFront\Security\RateLimiter;
use EditFront\Support\Config;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * What a PluginModule is given instead of reaching into the container (§6.9).
 *
 * Everything scoped to the plugin: its own storage directory under
 * storage/plugins/<slug>/ (created on demand, never shared), its own template
 * namespace, the shared logger, translator, rate limiter and URL helper.
 *
 * The point is that a module needs no wiring of its own — no DI entry, no
 * bootstrap edit — so installing one is still "copy the folder in".
 */
final class ModuleCtx
{
    public function __construct(
        private readonly string $slug,
        private readonly string $dir,
        private readonly Config $config,
        private readonly Environment $twig,
        private readonly Translator $i18n,
        private readonly UrlHelper $url,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function slug(): string
    {
        return $this->slug;
    }

    /** The plugin's own folder — read-only territory (its code and templates). */
    public function pluginDir(): string
    {
        return $this->dir;
    }

    /**
     * Private, writable, per-plugin. Created on first use with the same
     * permissions the core uses for storage, and never inside the web root.
     */
    public function storageDir(): string
    {
        $dir = $this->config->storageDir() . '/plugins/' . $this->slug;
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $dir;
    }

    /**
     * Render a template from the plugin's own templates/ folder. The folder is
     * registered as a Twig namespace named after the slug, so a plugin template
     * can neither be shadowed by nor shadow a core one.
     *
     * @param array<string, mixed> $vars
     */
    public function render(string $template, array $vars = []): string
    {
        $loader = $this->twig->getLoader();
        if ($loader instanceof \Twig\Loader\FilesystemLoader && !in_array($this->slug, $loader->getNamespaces(), true)) {
            $loader->addPath($this->dir . '/templates', $this->slug);
        }
        return $this->twig->render('@' . $this->slug . '/' . ltrim($template, '/'), $vars);
    }

    public function t(string $key, ?array $params = null, ?string $fallback = null): string
    {
        return $this->i18n->t($key, $params, $fallback);
    }

    public function url(): UrlHelper
    {
        return $this->url;
    }

    /** Shared with the core, so a flood through a plugin still counts. */
    public function limiter(): RateLimiter
    {
        return $this->limiter;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function config(): Config
    {
        return $this->config;
    }
}
