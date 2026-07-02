<?php

declare(strict_types=1);

namespace EditFront\Http;

use EditFront\Support\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Psr7\Response;

/**
 * Single error handler: client gets a generic message + trace_id, the full
 * exception goes to the log (§13 — never leak messages/traces unless debug).
 */
final class ErrorHandler
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly UrlHelper $url,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        if (!is_int($status) || $status < 400 || $status > 599) {
            $status = 500;
        }
        $traceId = bin2hex(random_bytes(8));

        $context = [
            'trace_id' => $traceId,
            'status' => $status,
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile() . ':' . $exception->getLine(),
        ];
        if ($status >= 500) {
            $context['trace'] = $exception->getTraceAsString();
            $this->logger->error('http.error', $context);
        } else {
            $this->logger->info('http.client_error', $context);
        }

        $title = match ($status) {
            403 => 'Forbidden',
            404 => 'Not found',
            405 => 'Method not allowed',
            429 => 'Too many requests',
            default => 'Internal error',
        };

        $response = new Response($status);

        if ($this->url->isApiPath($request->getUri()->getPath())) {
            $response->getBody()->write((string) json_encode([
                'error' => $title,
                'trace_id' => $traceId,
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $details = $this->config->debug()
            ? '<pre>' . htmlspecialchars(
                $exception::class . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString(),
                ENT_QUOTES
            ) . '</pre>'
            : '';
        $response->getBody()->write(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $status . ' ' . $title
            . '</title></head><body style="font-family:system-ui;margin:3rem auto;max-width:36rem;color:#1f2937">'
            . '<h1 style="font-size:1.4rem">' . $status . ' — ' . $title . '</h1>'
            . '<p style="color:#6b7280">trace_id: <code>' . $traceId . '</code></p>'
            . $details
            . '</body></html>'
        );
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
