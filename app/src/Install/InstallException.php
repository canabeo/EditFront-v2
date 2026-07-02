<?php

declare(strict_types=1);

namespace EditFront\Install;

/** Install failure carrying the HTTP status the controller returns. */
final class InstallException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
