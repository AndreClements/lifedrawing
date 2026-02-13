<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * Consent state machine.
 *
 * Octagon facet 7 (Consent): opt-in, revocable, context-aware.
 * Users must explicitly grant consent before their data can be used
 * in public-facing features (gallery, profiles, claims).
 */
enum ConsentState: string
{
    case Pending   = 'pending';
    case Granted   = 'granted';
    case Withdrawn = 'withdrawn';

    public function canParticipate(): bool
    {
        return $this === self::Granted;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Consent pending',
            self::Granted   => 'Consent granted',
            self::Withdrawn => 'Consent withdrawn',
        };
    }
}
