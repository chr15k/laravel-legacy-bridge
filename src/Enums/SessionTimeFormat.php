<?php

namespace Chr15k\LegacyBridge\Enums;

use Carbon\Carbon;
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

    public function toStorage(CarbonInterface $carbon): int|string
    {
        return match ($this) {
            self::Datetime  => $carbon->toDateTimeString(),
            self::Timestamp => $carbon->timestamp,
        };
    }

    public function fromStorage(int|string $value): CarbonInterface
    {
        return match ($this) {
            self::Datetime  => Carbon::parse($value),
            self::Timestamp => Carbon::createFromTimestamp((int) $value),
        };
    }
}
