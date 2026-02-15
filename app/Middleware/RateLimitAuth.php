<?php

declare(strict_types=1);

namespace App\Middleware;

/** Rate limit for auth routes: 5 attempts per 15 minutes. */
final class RateLimitAuth extends RateLimitMiddleware
{
    protected string $category = 'auth';
}
