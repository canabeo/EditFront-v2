<?php

declare(strict_types=1);

namespace EditFront\Document;

/**
 * Page-level CRUD failure carrying the HTTP status the controller should
 * return (409 conflict / 422 invalid / 404 missing source). Keeps the
 * controller a thin translator and the logic unit-testable in PageService.
 */
final class PageCrudException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
