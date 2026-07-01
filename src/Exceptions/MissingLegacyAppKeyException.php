<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Exceptions;

use RuntimeException;

final class MissingLegacyAppKeyException extends RuntimeException
{
    public function __construct($message = 'app_key is not set in config/legacy-bridge.php')
    {
        parent::__construct($message);
    }
}
