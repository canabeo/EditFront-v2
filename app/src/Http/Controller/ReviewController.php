<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Auth\AuthService;
use EditFront\I18n\Translator;
use EditFront\Reviews\ReviewException;
use EditFront\Reviews\ReviewPublisher;
use EditFront\Reviews\ReviewStore;
use EditFront\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * HTTP slice for the reviews engine: the /settings/reviews admin moderation page
 * + JSON list, the admin moderation actions (save / approve / reject / delete),
 * and the PUBLIC submission endpoint.
 *
 * The admin actions live in the AuthMiddleware group (CSRF enforced globally).
 * submit() is registered OUTSIDE the group (anonymous visitors) and is the one
 * CSRF-exempt write path (see CsrfMiddleware) — it is protected instead by a
 * honeypot field, a per-IP rate-limit and plain-text-only storage.
 */
final class ReviewController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly AuthService $auth,
        private readonly ReviewStore $store,
        private readonly ReviewPublisher $publisher,
        private readonly RateLimiter $limiter,
        private readonly Translator $i18n,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ---- admin -------------------------------------------------------------

    /** GET /settings/reviews — server-rendered moderation page. */
    public function page(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $html = $this->twig->render('reviews.twig', [
            'user'     => $this->auth->user(),
            'pending'  => ReviewStore::sortForOutput($this->store->byStatus('pending')),
            'approved' => ReviewStore::sortForOutput($this->store->byStatus('approved')),
            'rejected' => ReviewStore::sortForOutput($this->store->byStatus('rejected')),
        ]);
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** GET /api/reviews — JSON list of all items (output-sorted). */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, 200, ['items' => ReviewStore::sortForOutput($this->store->items())]);
    }

    /** POST /api/reviews — create (manual add) or edit a review. */
    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->throttle($request)) {
            return $this->json($response, 429, ['error' => $this->i18n->t('reviews.error_rate')]);
        }
        $body = $this->body($request);
        try {
            $result = $this->publisher->save($body);
        } catch (ReviewException $e) {
            return $this->json($response, $e->statusHint, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('reviews.save_failed', ['message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'could not save']);
        }
        return $this->json($response, 200, ['ok' => true] + $result);
    }

    /** POST /api/reviews/approve — publish a review. */
    public function approve(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->moderate($request, $response, 'approve');
    }

    /** POST /api/reviews/reject — reject a review (stays rejected). */
    public function reject(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->moderate($request, $response, 'reject');
    }

    /** POST /api/reviews/unpublish — take an approved review back to moderation. */
    public function unpublish(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->moderate($request, $response, 'unpublish');
    }

    /** POST /api/reviews/delete — delete a review. */
    public function delete(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->moderate($request, $response, 'delete');
    }

    private function moderate(ServerRequestInterface $request, ResponseInterface $response, string $action): ResponseInterface
    {
        if (!$this->throttle($request)) {
            return $this->json($response, 429, ['error' => $this->i18n->t('reviews.error_rate')]);
        }
        $body = $this->body($request);
        $id   = is_string($body['id'] ?? null) ? trim((string) $body['id']) : '';
        if ($id === '') {
            return $this->json($response, 422, ['error' => 'id is required']);
        }
        try {
            $result = match ($action) {
                'approve'   => $this->publisher->approve($id),
                'reject'    => $this->publisher->reject($id),
                'unpublish' => $this->publisher->unpublish($id),
                default     => $this->publisher->delete($id),
            };
        } catch (ReviewException $e) {
            return $this->json($response, $e->statusHint, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('reviews.' . $action . '_failed', ['id' => $id, 'message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'could not ' . $action]);
        }
        return $this->json($response, 200, ['ok' => true] + $result);
    }

    // ---- public submission -------------------------------------------------

    /** POST /api/reviews/submit — anonymous public submission (CSRF-exempt). */
    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');

        // 5 submissions / 10 min / IP.
        if (!$this->limiter->allow('reviews-submit', $ip, 5, 600)) {
            return $this->json($response, 429, ['ok' => false, 'error' => $this->i18n->t('reviews.submit_rate')]);
        }

        $body = $this->body($request);

        // honeypot: a filled 'website' field is a bot — silently accept + drop.
        if (trim((string) ($body['website'] ?? '')) !== '') {
            return $this->json($response, 200, ['ok' => true]);
        }

        $name = trim((string) ($body['name'] ?? ''));
        $text = trim((string) ($body['text'] ?? ($body['comment'] ?? '')));
        $consent = !empty($body['consent']) || !empty($body['agree']);

        if ($name === '' || $text === '') {
            return $this->json($response, 422, ['ok' => false, 'error' => $this->i18n->t('reviews.submit_required')]);
        }
        if (!$consent) {
            return $this->json($response, 422, ['ok' => false, 'error' => $this->i18n->t('reviews.submit_consent')]);
        }

        try {
            $this->publisher->submit([
                'name'    => $name,
                'country' => $body['country'] ?? ($body['city'] ?? ''),
                'text'    => $text,
                'source'  => is_string($body['source'] ?? null) ? $body['source'] : '',
                '__ip'    => $ip,
            ]);
        } catch (ReviewException $e) {
            return $this->json($response, $e->statusHint, ['ok' => false, 'error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('reviews.submit_failed', ['message' => $e->getMessage()]);
            return $this->json($response, 422, ['ok' => false, 'error' => $this->i18n->t('reviews.submit_error')]);
        }

        return $this->json($response, 200, ['ok' => true, 'message' => $this->i18n->t('reviews.submit_thanks')]);
    }

    // ---- helpers -----------------------------------------------------------

    private function throttle(ServerRequestInterface $request): bool
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        return $this->limiter->allow('reviews', $ip, 60, 60);
    }

    /**
     * Parse the request body. Supports JSON (fetch) and urlencoded forms.
     *
     * @return array<string,mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }
        $raw = (string) $request->getBody();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
