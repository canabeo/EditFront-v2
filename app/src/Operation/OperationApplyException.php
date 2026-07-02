<?php

declare(strict_types=1);

namespace EditFront\Operation;

/** Runtime apply condition (stale ref, protected target) → warning, op skipped, batch continues. */
final class OperationApplyException extends OperationException
{
}
