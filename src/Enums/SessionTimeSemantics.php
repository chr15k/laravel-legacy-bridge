<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Enums;

enum SessionTimeSemantics: string
{
    /**
     * The stored time is an expiry timestamp.
     */
    case Expires = 'expires';

    /**
     * The stored time is a last-activity timestamp.
     */
    case Activity = 'activity';

    public function representsExpires(): bool
    {
        return $this === self::Expires;
    }

    public function representsActivity(): bool
    {
        return $this === self::Activity;
    }
}
