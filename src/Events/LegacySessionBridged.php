<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Events;

use Chr15k\LegacyBridge\Payload\LegacyPayload;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class LegacySessionBridged
{
    use Dispatchable;

    public function __construct(
        public int|string $userId,
        public string $sessionId,
        public LegacyPayload $payload,
    ) {}
}
