<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Data;

final readonly class LegacySession
{
    public function __construct(
        public string $id,
        public ?int $userId,
        public ?string $ipAddress,
        public ?string $userAgent,
        public string $payload,
        public int $lastActivity,
        public bool $expired,
        public float $age
    ) {}
}
