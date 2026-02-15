<?php

declare(strict_types=1);

namespace App\Middleware;

/** Rate limit for uploads: 10 per hour. */
final class RateLimitUpload extends RateLimitMiddleware
{
    protected string $category = 'upload';
}
