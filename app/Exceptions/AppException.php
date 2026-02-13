<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Base application exception with the And-Yet field.
 *
 * The try-catch-AND-YET pattern: every exception carries an honest self-critique.
 * The andYet is not shown to the user — it's logged for the developer, capturing
 * what the system knows it doesn't handle well yet. Maculate design: acknowledge
 * the flaw, document it, move forward.
 */
class AppException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $andYet = '',
        public readonly string $severity = 'medium',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** Structured context for logging. */
    public function context(): array
    {
        return [
            'exception' => static::class,
            'message' => $this->getMessage(),
            'and_yet' => $this->andYet,
            'severity' => $this->severity,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }
}
