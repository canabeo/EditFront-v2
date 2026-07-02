<?php

declare(strict_types=1);

namespace EditFront\Seo;

/** Invalid SEO input (e.g. an unsafe canonical URL) — mapped to HTTP 422. */
final class SeoException extends \RuntimeException
{
}
