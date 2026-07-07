<?php

namespace Workbench\App\Listeners;

use Chr15k\LegacyBridge\Events\LegacySessionBridged;
use Illuminate\Support\Facades\Log;

final class LogSessionBridgeSuccess
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
    public function handle(LegacySessionBridged $event): void
    {
        Log::info('Legacy session bridge succeeded', [
            'user_id'    => $event->userId,
            'session_id' => $event->sessionId,
            'payload'    => $event->payload->all(),
        ]);
    }
}
