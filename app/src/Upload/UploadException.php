<?php

declare(strict_types=1);

namespace EditFront\Upload;

/** Upload rejected; carries the HTTP status the controller returns. */
final class UploadException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
