<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Auth\AuthService;
use EditFront\I18n\Translator;
use EditFront\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * Account page — lets the logged-in admin change their own password.
 * Server-rendered form (no JS): GET shows the form, POST validates and
 * re-renders with a success/error message. CSRF is enforced globally.
 */
final class AccountController
{
    private const MIN_PASSWORD = 8;

    public function __construct(
        private readonly Environment $twig,
        private readonly AuthService $auth,
        private readonly RateLimiter $rate,
        private readonly Translator $i18n,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function page(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($response, null, null);
    }

    public function changePassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!$this->rate->allow('account', is_string($ip) ? $ip : '0.0.0.0', 10, 60)) {
            return $this->render($response->withStatus(429), $this->i18n->t('account.error_throttled'), null);
        }

        $body = (array) $request->getParsedBody();
        $current = (string) ($body['current'] ?? '');
        $new = (string) ($body['new'] ?? '');
        $confirm = (string) ($body['confirm'] ?? '');

        if ($current === '' || $new === '' || $confirm === '') {
            return $this->render($response->withStatus(400), $this->i18n->t('account.error_required'), null);
        }
        if (mb_strlen($new) < self::MIN_PASSWORD) {
            return $this->render(
                $response->withStatus(400),
                $this->i18n->t('account.error_short', ['min' => self::MIN_PASSWORD]),
                null
            );
        }
        if ($new !== $confirm) {
            return $this->render($response->withStatus(400), $this->i18n->t('account.error_mismatch'), null);
        }
        if (!$this->auth->changePassword($current, $new)) {
            $this->logger->notice('auth.password_change_fail', ['user' => $this->auth->user()]);
            return $this->render($response->withStatus(401), $this->i18n->t('account.error_current'), null);
        }

        $this->logger->info('auth.password_changed', ['user' => $this->auth->user()]);
        return $this->render($response, null, $this->i18n->t('account.success'));
    }

    private function render(ResponseInterface $response, ?string $error, ?string $success): ResponseInterface
    {
        $response->getBody()->write($this->twig->render('account.twig', [
            'user' => $this->auth->user(),
            'error' => $error,
            'success' => $success,
        ]));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
