<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Auth\AuthService;
use EditFront\Http\UrlHelper;
use EditFront\I18n\Translator;
use EditFront\Security\LoginCaptcha;
use EditFront\Security\LoginThrottle;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

final class LoginController
{
    /** Unconditional slow-down (µs) applied to every failed attempt — costs a
     *  scripted/distributed brute-force real time regardless of source IP. */
    private const FAIL_DELAY_US = 250_000;

    public function __construct(
        private readonly Environment $twig,
        private readonly AuthService $auth,
        private readonly LoginThrottle $throttle,
        private readonly LoginCaptcha $captcha,
        private readonly UrlHelper $url,
        private readonly Translator $i18n,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function form(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->auth->check()) {
            return $response->withHeader('Location', $this->url->path(''))->withStatus(302);
        }
        $ip = $this->clientIp($request);
        $captcha = $this->throttle->captchaRequired($ip) ? $this->captcha->issue() : null;
        return $this->render($response, null, $captcha);
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = $this->clientIp($request);

        $wait = $this->throttle->blockedFor($ip);
        if ($wait > 0) {
            $minutes = (int) ceil($wait / 60);
            return $this->render(
                $response->withStatus(429),
                $this->i18n->t('login.error_throttled', ['min' => $minutes]),
                null
            );
        }

        $body = (array) $request->getParsedBody();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        // CAPTCHA gate — evaluated on the state BEFORE this attempt. When required,
        // a wrong/missing answer short-circuits: the password is NOT checked, so a
        // failed CAPTCHA never advances the brute-force (and never counts as a fail).
        if ($this->throttle->captchaRequired($ip, $username)) {
            $answer = isset($body['captcha']) ? (string) $body['captcha'] : null;
            if (!$this->captcha->verify($answer)) {
                return $this->render(
                    $response->withStatus(401),
                    $this->i18n->t('login.error_captcha'),
                    $this->captcha->issue()
                );
            }
        }

        if ($username !== '' && $password !== '' && $this->auth->attempt($username, $password)) {
            $this->throttle->clear($ip, $username);
            $this->logger->info('auth.login_ok', ['user' => $username, 'ip' => $ip]);
            return $response->withHeader('Location', $this->url->path(''))->withStatus(302);
        }

        $this->throttle->registerFailure($ip, $username);
        usleep(self::FAIL_DELAY_US);
        $this->logger->notice('auth.login_fail', ['user' => $username, 'ip' => $ip]);

        $captcha = $this->throttle->captchaRequired($ip, $username) ? $this->captcha->issue() : null;
        return $this->render($response->withStatus(401), $this->i18n->t('login.error_invalid'), $captcha);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = $this->auth->user();
        $this->auth->logout();
        $this->logger->info('auth.logout', ['user' => $user]);
        return $response->withHeader('Location', $this->url->path('login'))->withStatus(302);
    }

    private function render(ResponseInterface $response, ?string $error, ?string $captcha = null): ResponseInterface
    {
        $response->getBody()->write($this->twig->render('login.twig', [
            'error' => $error,
            'captcha' => $captcha,
        ]));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        // NOTE: behind Cloudflare this is the CF edge IP; trusting
        // CF-Connecting-IP safely needs a CF range check — out of C0 scope.
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }
}
