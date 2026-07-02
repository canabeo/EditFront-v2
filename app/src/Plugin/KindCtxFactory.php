<?php

declare(strict_types=1);

namespace EditFront\Plugin;

use EditFront\Support\Config;

/** Builds a render context for a kind (§6.3). Autowired; no per-plugin factory. */
final class KindCtxFactory
{
    public function __construct(private readonly Config $config)
    {
    }

    public function make(RegisteredKind $rk, string $page = ''): KindCtx
    {
        $base = $this->config->basePath();
        $slug = $rk->slug();
        return new KindCtx(
            $slug,
            $rk->trust(),
            $page,
            static fn (string $rel): string => $base . '/plugin-asset/' . $slug . '/' . ltrim($rel, '/'),
            static fn (string $key): string => $key, // plugin i18n on the server is identity in C3
        );
    }
}
