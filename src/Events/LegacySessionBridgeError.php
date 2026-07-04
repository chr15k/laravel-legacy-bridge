<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

final readonly class LegacySessionBridgeError
{
    use Dispatchable;

    public function __construct(public Throwable $exception) {}
}
