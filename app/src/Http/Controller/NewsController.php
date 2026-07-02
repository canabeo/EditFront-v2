<?php
declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Auth\AuthService;
use EditFront\I18n\Translator;
use EditFront\News\NewsException;
use EditFront\News\NewsPublisher;
use EditFront\News\NewsStore;
use EditFront\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * Thin HTTP slice for the news engine (phase N4). Renders the /settings/news
 * admin page, returns the output-sorted item list as JSON, and forwards
 * create/update/delete to NewsPublisher. CSRF is enforced globally by
 * CsrfMiddleware over the AuthMiddleware group — the controller never checks
 * it. Rate-limit bucket 'news' (30/60 per IP). NewsException::statusHint maps
 * to the HTTP status; any other \Throwable → 422. Body sanitization of
 * body_html happens inside NewsPublisher::save, not here.
 */
final class NewsController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly AuthService $auth,
        private readonly NewsStore $store,
        private readonly NewsPublisher $publisher,
        private readonly RateLimiter $limiter,
        private readonly Translator $i18n,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** GET /settings/news — server-rendered admin page. */
    public function page(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $html = $this->twig->render('news.twig', [
            'user'    => $this->auth->user(),
            'items'   => $this->sortedItems(),
            'error'   => null,
            'success' => null,
        ]);
        $response->getBody()->write($html);

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /** GET /api/news — JSON list of items (output-sorted). */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, 200, ['items' => $this->sortedItems()]);
    }

    /** POST /api/news — create or update an item via the publisher. */
    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->limiter->allow('news', $ip, 30, 60)) {
            return $this->json($response, 429, ['error' => $this->i18n->t('news.error_rate')]);
        }

        $body  = $this->body($request);
        $title = is_string($body['title'] ?? null) ? trim((string) $body['title']) : '';
        if ($title === '') {
            return $this->json($response, 422, ['error' => 'title is required']);
        }

        try {
            $result = $this->publisher->save($body);
        } catch (NewsException $e) {
            return $this->json($response, $e->statusHint, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('news.save_failed', ['message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'could not save']);
        }

        $this->logger->info('news.save', ['id' => $result['id'], 'slug' => $result['slug']]);

        return $this->json($response, 200, ['ok' => true] + $result);
    }

    /** POST /api/news/delete — delete an item + its page + cards. */
    public function delete(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->limiter->allow('news', $ip, 30, 60)) {
            return $this->json($response, 429, ['error' => $this->i18n->t('news.error_rate')]);
        }

        $body = $this->body($request);
        $id   = is_string($body['id'] ?? null) ? trim((string) $body['id']) : '';
        if ($id === '') {
            return $this->json($response, 422, ['error' => 'id is required']);
        }

        try {
            $result = $this->publisher->delete($id);
        } catch (NewsException $e) {
            return $this->json($response, $e->statusHint, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('news.delete_failed', ['id' => $id, 'message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'could not delete']);
        }

        $this->logger->info('news.delete', ['id' => $result['id'], 'slug' => $result['slug']]);

        return $this->json($response, 200, ['ok' => true] + $result);
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
     * Output-sorted items: date desc, tie-break created_at desc.
     * Reuses the single canonical sort from NewsStore (no inline usort).
     *
     * @return list<array<string,mixed>>
     */
    private function sortedItems(): array
    {
        return NewsStore::sortForOutput($this->store->items());
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
