<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Enums;

enum CookieEncryption: string
{
    case None = 'none';
    case Laravel = 'laravel';

    public function isLaravel(): bool
    {
        return $this === self::Laravel;
    }
}
