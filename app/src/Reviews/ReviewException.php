<?php

declare(strict_types=1);

namespace EditFront\Reviews;

/**
 * Domain-level failure for the reviews engine.
 *
 * Mirrors EditFront\News\NewsException: carries an optional HTTP status hint so
 * the thin ReviewController can map a thrown exception to the right response
 * code without a switch on the message.
 *
 * Convention: 422 (Unprocessable) for validation / bad input (default),
 * 404 for "not found".
 */
final class ReviewException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusHint = 422,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
