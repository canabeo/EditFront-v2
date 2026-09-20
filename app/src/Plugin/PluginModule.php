<?php

declare(strict_types=1);

namespace EditFront\Plugin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The second thing a plugin can be (§6.9).
 *
 * A BlockKind adds a kind of CONTENT to a page. A PluginModule adds a piece of
 * the SITE: its own endpoints, its own screen in the admin, its own storage.
 * Contact forms, a booking box, an export job for one client — work that is not
 * part of every EditFront install and therefore has no business in the core.
 *
 * The core owns exactly two routes for all modules, so a plugin can never
 * shadow a core path or grant itself an exemption outside its own namespace:
 *
 *   POST {base}/api/p/{slug}/{action}   → handle()
 *   GET  {base}/settings/p/{slug}       → page()
 *
 * An action the manifest lists under `module.public` is reachable without a
 * session and without a CSRF token — that is how a form on the public site
 * posts to it — so such an action MUST do its own rate limiting and treat every
 * field as hostile. Everything else requires a logged-in admin, like any core
 * endpoint.
 *
 * Plugin PHP is trusted code (USAGE.md): this interface does not sandbox it,
 * it gives it a defined place to live.
 */
interface PluginModule
{
    /**
     * Handle POST {base}/api/p/{slug}/{action}. $action is validated against
     * the manifest before the call, so an unlisted action never reaches here.
     * Returning a response is the module's job; throwing is caught and logged
     * by the core, which answers 500 without leaking the message.
     */
    public function handle(string $action, ServerRequestInterface $request, ResponseInterface $response, ModuleCtx $ctx): ResponseInterface;

    /** Render the module's own admin screen (GET {base}/settings/p/{slug}). */
    public function page(ServerRequestInterface $request, ResponseInterface $response, ModuleCtx $ctx): ResponseInterface;
}
