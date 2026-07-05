<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Enums;

enum PayloadFormat: string
{
    case Auto = 'auto';
    case PhpSession = 'php_session';
    case Json = 'json';
    case Laravel = 'laravel';
    case Encrypted = 'encrypted';
}
