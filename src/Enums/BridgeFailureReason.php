<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Enums;

enum BridgeFailureReason: string
{
    case MissingCookie = 'missing_cookie';

    case AmbiguousCookie = 'ambiguous_cookie';

    case InvalidCookie = 'invalid_cookie';

    case SessionNotFound = 'session_not_found';

    case SessionExpired = 'session_expired';

    case PayloadDecodeFailed = 'payload_decode_failed';

    case UserNotResolved = 'user_not_resolved';

    case AuthenticationFailed = 'authentication_failed';
}
