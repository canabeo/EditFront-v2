<?php

declare(strict_types=1);

namespace EditFront\Storage;

/** A create/duplicate targeted an existing page (maps to HTTP 409). */
final class PageExistsException extends StorageException
{
}
