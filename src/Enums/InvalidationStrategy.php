<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Enums;

enum InvalidationStrategy: string
{
    case Never = 'never';
    case AfterWrite = 'after_write';
    case Immediate = 'immediate';

    public function isImmediate(): bool
    {
        return $this === self::Immediate;
    }

    public function isAfterWrite(): bool
    {
        return $this === self::AfterWrite;
    }
}
