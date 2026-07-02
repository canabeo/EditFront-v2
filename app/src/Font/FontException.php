<?php

declare(strict_types=1);

namespace EditFront\Font;

/** Font upload/registry rejected; carries the HTTP status the controller returns. */
final class FontException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
