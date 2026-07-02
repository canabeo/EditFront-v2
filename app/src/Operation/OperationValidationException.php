<?php

declare(strict_types=1);

namespace EditFront\Operation;

/** Bad input (unknown op, schema violation, rejected by sanitizer) → the WHOLE batch is rejected. */
final class OperationValidationException extends OperationException
{
}
