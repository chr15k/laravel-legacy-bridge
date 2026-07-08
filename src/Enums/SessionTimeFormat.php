<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Enums;

use Carbon\CarbonInterface;

enum SessionTimeFormat: string
{
    case Datetime = 'datetime';
    case Timestamp = 'timestamp';

    public function isDatetime(): bool
    {
        return $this === self::Datetime;
    }

    public function isTimestamp(): bool
    {
        return $this === self::Timestamp;
    }

    public function toStorage(CarbonInterface $carbon): float|int|string
    {
        return match ($this) {
            self::Datetime  => $carbon->toDateTimeString(),
            self::Timestamp => $carbon->timestamp,
        };
    }
}
