<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Http\Middleware;

use Chr15k\LegacyBridge\Contracts\LegacyContextResolver;
use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;
use Chr15k\LegacyBridge\Data\BridgeContext;
use Chr15k\LegacyBridge\Enums\BridgeFailureReason;
use Chr15k\LegacyBridge\Events\LegacySessionBridged;
use Chr15k\LegacyBridge\Events\LegacySessionBridgeError;
use Chr15k\LegacyBridge\Events\LegacySessionBridgeFailed;
use Chr15k\LegacyBridge\Exceptions\BridgeException;
use Chr15k\LegacyBridge\Payload\LegacyPayload;
use Chr15k\LegacyBridge\Payload\PayloadDecoder;
use Chr15k\LegacyBridge\Session\CookieValueResolver;
use Chr15k\LegacyBridge\Session\LegacyDatabaseSessionHandler;
use Chr15k\LegacyBridge\Support\Config;
use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class LegacySessionBridge
{
    public function __construct(
        private Auth $auth,
        private Config $config,
        private PayloadDecoder $decoder,
        private LegacyDatabaseSessionHandler $sessionHandler,
        private LegacyUserResolver $resolver,
        private CookieValueResolver $cookieResolver,
        private ?LegacyContextResolver $contextResolver = null,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->guard()->check()) {
            return $next($request);
        }

        $sessionId = $this->attemptBridge($request);

        $response = $next($request);

        if ($sessionId && $this->config->invalidationStrategy()->isAfterWrite()) {
            $this->sessionHandler->invalidate($sessionId);
        }

        return $response;
    }

    private function attemptBridge(Request $request): ?string
    {
        try {
            return $this->bridge($request);
        } catch (BridgeException $e) {
            LegacySessionBridgeFailed::dispatch($e->reason, $e->context);
        } catch (Throwable $e) {
            LegacySessionBridgeError::dispatch($e);
        }

        return null;
    }

    private function bridge(Request $request): string
    {
        $ctx = $this->initializeBridgeContext($request);

        if (is_array($ctx->cookieValue)) {
            throw BridgeException::make(BridgeFailureReason::AmbiguousCookie, $ctx);
        }

        if (! is_string($ctx->cookieValue)) {
            throw BridgeException::make(BridgeFailureReason::MissingCookie, $ctx);
        }

        $sessionId = $this->cookieResolver->resolve($ctx->cookieValue)
            ?? throw BridgeException::make(BridgeFailureReason::InvalidCookie, $ctx);

        $ctx = $ctx->withSessionId($sessionId);

        $data = $this->sessionHandler->fetch($sessionId)
            ?? throw BridgeException::make(BridgeFailureReason::SessionNotFound, $ctx);

        $payload = $this->decoder->decode($data->payload, $this->config->format());
        $ctx = $ctx->withPayload($payload);

        if ($payload->isEmpty()) {
            throw BridgeException::make(BridgeFailureReason::PayloadDecodeFailed, $ctx);
        }

        $userId = $this->resolver->resolve($payload)
            ?? throw BridgeException::make(BridgeFailureReason::UserNotResolved, $ctx);

        $ctx = $ctx->withUserId($userId);

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();

        if (! $guard->loginUsingId($userId)) {
            throw BridgeException::make(BridgeFailureReason::AuthenticationFailed, $ctx);
        }

        $this->restoreSessionContext($userId, $payload);

        LegacySessionBridged::dispatch(
            userId: $userId,
            sessionId: $sessionId,
            payload: $payload,
        );

        if ($this->config->invalidationStrategy()->isImmediate()) {
            $this->sessionHandler->invalidate($sessionId);
        }

        return $sessionId;
    }

    private function initializeBridgeContext(Request $request): BridgeContext
    {
        $cookie = $this->config->cookie();

        /** @var string|array<int, string>|null $cookieValue */
        $cookieValue = $request->cookie($cookie);

        return new BridgeContext(
            cookieName: $cookie,
            requestContext: [
                'ip'         => $request->ip() ?? 'unknown',
                'path'       => $request->path(),
                'user_agent' => $request->userAgent() ?? 'unknown',
                'method'     => $request->method(),
            ],
            cookieValue: $cookieValue,
        );
    }

    private function restoreSessionContext(int|string $userId, LegacyPayload $payload): void
    {
        $carryKeys = $this->config->contextCarryKeys();

        if ($carryKeys !== []) {
            /** @var array<string, mixed> $context */
            $context = $payload->only($carryKeys);
            session($context);
        }

        if ($this->config->contextFlash()) {
            $flash = $payload->get('_flash');

            if (! is_array($flash)) {
                return;
            }

            $new = $flash['new'] ?? [];

            if (! is_array($new)) {
                return;
            }

            foreach ($new as $key) {
                if (! is_string($key)) {
                    continue;
                }

                session()->flash($key, $payload->get($key));
            }
        }

        if ($this->contextResolver instanceof LegacyContextResolver) {
            foreach ($this->contextResolver->resolve($userId, $payload) as $key => $value) {
                session([$key => $value]);
            }
        }
    }
}
