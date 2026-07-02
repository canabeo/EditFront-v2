<?php

declare(strict_types=1);

namespace EditFront\Plugin;

/** A plugin failed to load/validate/round-trip. Caught at boot — never fatal. */
final class PluginException extends \RuntimeException
{
}
