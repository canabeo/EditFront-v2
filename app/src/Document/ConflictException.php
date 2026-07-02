<?php

declare(strict_types=1);

namespace EditFront\Document;

/** Optimistic-lock conflict (§4.3): the page changed under the editor. */
final class ConflictException extends \RuntimeException
{
    public function __construct(public readonly string $currentSha256)
    {
        parent::__construct('page changed since it was opened');
    }
}
