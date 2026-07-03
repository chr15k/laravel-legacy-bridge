<?php

namespace Chr15k\LegacyBridge\Enums;

use Carbon\CarbonImmutable;

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

    public function toStorage(CarbonImmutable $carbon): int|string
    {
        return match ($this) {
            self::Datetime  => $carbon->toDateTimeString(),
            self::Timestamp => $carbon->timestamp,
        };
    }

    public function fromStorage(int|string $value): CarbonImmutable
    {
        return match ($this) {
            self::Datetime  => CarbonImmutable::parse($value),
            self::Timestamp => CarbonImmutable::createFromTimestamp((int) $value),
        };
    }
}
