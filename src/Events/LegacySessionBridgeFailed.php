<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Events;

use Chr15k\LegacyBridge\Data\BridgeContext;
use Chr15k\LegacyBridge\Enums\BridgeFailureReason;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class LegacySessionBridgeFailed
{
    use Dispatchable;

    public function __construct(
        public BridgeFailureReason $reason,
        public BridgeContext $context,
    ) {}
}
