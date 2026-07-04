<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Exceptions;

use Chr15k\LegacyBridge\Data\BridgeContext;
use Chr15k\LegacyBridge\Enums\BridgeFailureReason;
use RuntimeException;

final class BridgeException extends RuntimeException
{
    public function __construct(
        public readonly BridgeFailureReason $reason,
        public readonly BridgeContext $context,
    ) {
        parent::__construct($reason->value);
    }

    public static function make(BridgeFailureReason $reason, BridgeContext $context): self
    {
        return new self($reason, $context);
    }
}
