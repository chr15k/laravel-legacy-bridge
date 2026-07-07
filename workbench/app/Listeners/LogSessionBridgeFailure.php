<?php

namespace Workbench\App\Listeners;

use Chr15k\LegacyBridge\Events\LegacySessionBridgeFailed;
use Illuminate\Support\Facades\Log;

final class LogSessionBridgeFailure
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(LegacySessionBridgeFailed $event): void
    {
        Log::error('Legacy session bridge failed', [
            'reason'  => $event->reason->value,
            'context' => $event->context->toArray(),
        ]);
    }
}
