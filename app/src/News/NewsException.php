<?php

declare(strict_types=1);

namespace EditFront\News;

/**
 * Domain-level failure for the news engine.
 *
 * Mirrors EditFront\Storage\StorageException but carries an optional HTTP
 * status hint so the thin NewsController (phase N4) can map a thrown
 * exception to the right response code without a switch on the message.
 *
 * Convention: 422 (Unprocessable) for validation / bad input (default),
 * 404 for "not found", 409 for slug/page conflicts.
 */
final class NewsException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusHint = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
