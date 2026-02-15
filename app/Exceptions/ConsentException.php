<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * CARDS Autonomy/Consent violation — critical, halts.
 *
 * Thrown when consent is missing, expired, or withdrawn.
 * Operations on user data require granted consent.
 */
final class ConsentException extends AppException
{
    public function __construct(
        string $message,
        string $andYet = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $andYet, 'critical', 0, $previous);
    }
}
