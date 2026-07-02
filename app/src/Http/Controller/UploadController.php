<?php

declare(strict_types=1);

namespace EditFront\Http\Controller;

use EditFront\Security\RateLimiter;
use EditFront\Upload\UploadException;
use EditFront\Upload\UploadService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Image upload + listing for the in-preview image picker (§4.9).
 * POST /api/upload (multipart "file") and GET /api/images. CSRF is enforced
 * by middleware (the client sends X-CSRF-Token); here a 20/min per-IP limit.
 */
final class UploadController
{
    public function __construct(
        private readonly UploadService $uploads,
        private readonly RateLimiter $limiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$this->limiter->allow('upload', $ip, 20, 60)) {
            return $this->json($response, 429, ['error' => 'Too many uploads, slow down']);
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            return $this->json($response, 422, ['error' => 'no file uploaded (field "file")']);
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, 422, ['error' => 'upload error code ' . $file->getError()]);
        }

        // read the stream once — cap is enforced again in the service
        $max = $this->uploads->maxBytes();
        $size = $file->getSize();
        if (is_int($size) && $size > $max) {
            return $this->json($response, 413, ['error' => 'file too large']);
        }
        $content = (string) $file->getStream();

        try {
            $result = $this->uploads->store($content, (string) $file->getClientFilename());
        } catch (UploadException $e) {
            return $this->json($response, $e->status, ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('upload.failed', ['message' => $e->getMessage()]);
            return $this->json($response, 422, ['error' => 'upload failed']);
        }

        $this->logger->info('upload.ok', ['hash' => $result['hash'], 'ext' => $result['ext'], 'size' => $result['size']]);
        return $this->json($response, 200, ['ok' => true] + $result);
    }

    public function images(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, 200, ['images' => $this->uploads->list()]);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
