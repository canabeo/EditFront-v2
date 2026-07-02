<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Auth\AuthService;
use EditFront\Http\UrlHelper;
use EditFront\I18n\Translator;
use EditFront\I18n\TranslationStore;
use EditFront\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * Admin-UI translation editor (§8). Lists every translation key with its
 * builtin baseline and an editable override; saving writes only the changed
 * values to lang/user/<locale>.json. Server-rendered (one small filter script),
 * under auth + global CSRF.
 */
final class TranslationsController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly AuthService $auth,
        private readonly Translator $i18n,
        private readonly TranslationStore $store,
        private readonly RateLimiter $rate,
        private readonly UrlHelper $url,
    ) {
    }

    public function addLanguage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!$this->rate->allow('translations', is_string($ip) ? $ip : '0.0.0.0', 20, 60)) {
            return $this->render($response->withStatus(429), $this->resolveLocale(''), $this->i18n->t('translations.error'), null);
        }

        $code = strtolower(trim((string) (((array) $request->getParsedBody())['code'] ?? '')));
        try {
            $this->store->addLanguage($code);
        } catch (\Throwable $e) {
            $msg = $e->getMessage() === 'language already exists'
                ? $this->i18n->t('translations.error_exists')
                : $this->i18n->t('translations.error_bad_code');
            return $this->render($response->withStatus(400), $this->resolveLocale(''), $msg, null);
        }

        // Redirect so a fresh request rebuilds the Translator's supported-language
        // cache and the new tab shows up immediately.
        return $response
            ->withHeader('Location', $this->url->path('settings/translations') . '?edit=' . $code)
            ->withStatus(302);
    }

    public function page(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $edit = $this->resolveLocale((string) ($request->getQueryParams()['edit'] ?? ''));
        return $this->render($response, $edit, null, null);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!$this->rate->allow('translations', is_string($ip) ? $ip : '0.0.0.0', 20, 60)) {
            return $this->render($response->withStatus(429), $this->resolveLocale(''), $this->i18n->t('translations.error'), null);
        }

        $body = (array) $request->getParsedBody();
        $edit = $this->resolveLocale((string) ($body['lang'] ?? ''));
        $pairs = is_array($body['tr'] ?? null) ? $body['tr'] : [];

        try {
            $count = $this->store->saveOverrides($edit, $pairs);
        } catch (\Throwable) {
            return $this->render($response->withStatus(400), $edit, $this->i18n->t('translations.error'), null);
        }

        return $this->render($response, $edit, null, $this->i18n->t('translations.saved', ['n' => $count]));
    }

    /** Falls back to the current UI locale when the requested one is unknown. */
    private function resolveLocale(string $locale): string
    {
        return in_array($locale, $this->i18n->supportedLanguages(), true) ? $locale : $this->i18n->current();
    }

    private function render(
        ResponseInterface $response,
        string $edit,
        ?string $error,
        ?string $success
    ): ResponseInterface {
        $baseline = $this->store->baseline($edit);
        $overrides = $this->store->overrides($edit);
        ksort($baseline);

        $rows = [];
        foreach ($baseline as $key => $base) {
            $rows[] = [
                'key' => $key,
                'base' => $base,
                'override' => $overrides[$key] ?? '',
            ];
        }

        $response->getBody()->write($this->twig->render('translations.twig', [
            'user' => $this->auth->user(),
            'langs' => $this->i18n->supportedLanguages(),
            'edit' => $edit,
            'rows' => $rows,
            'overrides_count' => count($overrides),
            'error' => $error,
            'success' => $success,
        ]));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
