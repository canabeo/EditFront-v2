<?php

declare(strict_types=1);

namespace EditFront\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Security headers on every CMS response, including errors — this middleware
 * is registered LAST in bootstrap so it executes FIRST and wraps everything
 * (§13; v1 lesson: 404/500 went out without CSP).
 *
 * A response carrying X-Cms-Relax-Csp (the preview iframe serving the USER'S
 * page, which may pull external fonts/scripts) gets a permissive CSP instead —
 * the user's own content is not ours to break.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const CSP = "default-src 'self'; script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
        . "base-uri 'self'; frame-ancestors 'self'; object-src 'none'";

    private const CSP_RELAXED = "default-src * data: blob: 'unsafe-inline' 'unsafe-eval'; "
        . "frame-ancestors 'self'";

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $csp = self::CSP;
        if ($response->hasHeader('X-Cms-Relax-Csp')) {
            $csp = self::CSP_RELAXED;
            $response = $response->withoutHeader('X-Cms-Relax-Csp');
        }

        return $response
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            // HSTS: once the admin has loaded the CMS over HTTPS the browser
            // pins this host to TLS for a year (blocks SSL-strip on the login
            // session cookie). Browsers ignore it when received over plain HTTP,
            // so emitting it unconditionally is safe.
            ->withHeader('Strict-Transport-Security', 'max-age=31536000')
            ->withHeader('Permissions-Policy', 'geolocation=(), camera=(), microphone=(), payment=(), usb=()');
    }
}
