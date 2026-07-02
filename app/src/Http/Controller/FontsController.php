<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Auth\AuthService;
use EditFront\Font\FontException;
use EditFront\Font\FontService;
use EditFront\I18n\Translator;
use EditFront\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * Self-hosted fonts admin (§ fonts). Upload a font file + family name, list
 * with a live preview, delete. Server-rendered (no JS) under auth + global CSRF.
 */
final class FontsController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly AuthService $auth,
        private readonly FontService $fonts,
        private readonly Translator $i18n,
        private readonly RateLimiter $rate,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function page(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($response, null, null);
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->rate->allow('fonts', $ip, 20, 60)) {
            return $this->render($response->withStatus(429), $this->i18n->t('fonts.error'), null);
        }

        $family = (string) (((array) $request->getParsedBody())['family'] ?? '');
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->render($response->withStatus(422), $this->i18n->t('fonts.error_nofile'), null);
        }

        try {
            $entry = $this->fonts->store((string) $file->getStream(), $family);
        } catch (FontException $e) {
            $key = match ($e->status) {
                413 => 'fonts.error_too_large',
                409 => 'fonts.error_exists',
                415 => 'fonts.error_type',
                default => 'fonts.error_invalid',
            };
            return $this->render($response->withStatus($e->status), $this->i18n->t($key), null);
        } catch (\Throwable $e) {
            $this->logger->error('font.upload_failed', ['message' => $e->getMessage()]);
            return $this->render($response->withStatus(422), $this->i18n->t('fonts.error'), null);
        }

        $this->logger->info('font.added', ['family' => $entry['family'], 'format' => $entry['format']]);
        return $this->render($response, null, $this->i18n->t('fonts.added', ['name' => $entry['family']]));
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $family = (string) (((array) $request->getParsedBody())['family'] ?? '');
        $this->fonts->delete($family);
        $this->logger->info('font.deleted', ['family' => $family]);
        return $this->render($response, null, $this->i18n->t('fonts.deleted'));
    }

    private function render(ResponseInterface $response, ?string $error, ?string $success): ResponseInterface
    {
        $response->getBody()->write($this->twig->render('fonts.twig', [
            'user' => $this->auth->user(),
            'fonts' => $this->fonts->list(),
            'font_css' => implode("\n", array_values($this->fonts->userFontFaceRules())),
            'preset_count' => $this->fonts->presetCount(),
            'error' => $error,
            'success' => $success,
        ]));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
