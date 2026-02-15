<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * CARDS Dignity violation — always critical, always halts.
 *
 * Thrown when an operation would reduce a person to an object-for-use.
 * This is a hard stop. No graceful degradation. No silent recovery.
 */
final class DignityException extends AppException
{
    public function __construct(
        string $message,
        string $andYet = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $andYet, 'critical', 0, $previous);
    }
}
