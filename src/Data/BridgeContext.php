<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Data;

use Chr15k\LegacyBridge\Payload\LegacyPayload;

final readonly class BridgeContext
{
    /**
     * @param  array<string, string>  $requestContext
     * @param  string|array<int, string>|null  $cookieValue
     */
    public function __construct(
        public string $cookieName,
        public array $requestContext,
        public string|array|null $cookieValue = null,
        public ?string $sessionId = null,
        public ?LegacyPayload $payload = null,
        public int|string|null $userId = null,
    ) {}

    public function withSessionId(string $sessionId): self
    {
        return new self(
            cookieName: $this->cookieName,
            requestContext: $this->requestContext,
            cookieValue: $this->cookieValue,
            sessionId: $sessionId,
            payload: $this->payload,
            userId: $this->userId,
        );
    }

    public function withPayload(LegacyPayload $payload): self
    {
        return new self(
            cookieName: $this->cookieName,
            requestContext: $this->requestContext,
            cookieValue: $this->cookieValue,
            sessionId: $this->sessionId,
            payload: $payload,
            userId: $this->userId,
        );
    }

    public function withUserId(int|string $userId): self
    {
        return new self(
            cookieName: $this->cookieName,
            requestContext: $this->requestContext,
            cookieValue: $this->cookieValue,
            sessionId: $this->sessionId,
            payload: $this->payload,
            userId: $userId,
        );
    }
}
