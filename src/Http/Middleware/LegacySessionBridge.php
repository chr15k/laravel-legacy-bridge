<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Http\Middleware;

use Chr15k\LegacyBridge\Config;
use Chr15k\LegacyBridge\Contracts\LegacyContextResolver;
use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;
use Chr15k\LegacyBridge\Data\LegacySession;
use Chr15k\LegacyBridge\Payload\LegacyPayload;
use Chr15k\LegacyBridge\Payload\PayloadDecoder;
use Chr15k\LegacyBridge\Session\LegacyDatabaseSessionHandler;
use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        private ?LegacyContextResolver $contextResolver = null,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldBridge($request)) {
            return $next($request);
        }

        try {
            $this->bridge($request);
        } catch (Throwable $throwable) {
            $this->log('error', 'bridge failed', [
                'error' => $throwable->getMessage(),
            ]);
        }

        $response = $next($request);

        if ($this->config->shouldInvalidateAfterWrite()) {
            $this->invalidateLegacySession($request);
        }

        return $response;
    }

    private function fetchLegacyCookieValueFromRequest(Request $request)
    {
        return $request->cookie($this->config->cookie());
    }

    private function shouldBridge(Request $request): bool
    {
        if ($this->auth->guard()->check()) {
            return false;
        }

        return (bool) $this->fetchLegacyCookieValueFromRequest($request);
    }

    private function bridge(Request $request): ?string
    {
        $value = $this->fetchLegacyCookieValueFromRequest($request);

        $sessionId = $this->sessionHandler->resolveSessionId($value);

        if ($sessionId === null) {
            return null;
        }

        $data = $this->sessionHandler->fetch($sessionId);

        if (! $data instanceof LegacySession) {
            return null;
        }

        $payload = $this->decoder->decode($data->payload, $this->config->format());

        if ($payload->isEmpty()) {
            return null;
        }

        $userId = $this->resolver->resolve($payload);

        if ($userId === null) {
            return null;
        }

        /** @var StatefulGuard $guard */
        $guard = $this->auth->guard();

        $guard->loginUsingId($userId);

        $this->hydrateContext($userId, $payload);

        if ($this->config->invalidation() === 'immediate') {
            $this->invalidateLegacySession($request);
        }

        $this->log('info', 'session bridged', [
            'user_id'    => $userId,
            'session_id' => mb_substr($sessionId, 0, 8).'…',
            'format'     => $payload->format(),
        ]);

        return $sessionId;
    }

    private function hydrateContext(int $userId, LegacyPayload $payload): void
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

    private function invalidateLegacySession(Request $request): void
    {
        $value = $this->fetchLegacyCookieValueFromRequest($request);

        $resolvedSessionId = $this->sessionHandler->resolveSessionId($value);

        if ($this->config->invalidation() === 'never') {
            return;
        }

        try {
            $this->sessionHandler->invalidate($resolvedSessionId);
        } catch (Throwable $throwable) {
            $this->log('warning', 'could not invalidate legacy session', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<mixed>  $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (! $this->config->loggingEnabled()) {
            return;
        }

        $channel = $this->config->loggingChannel();

        $logger = is_string($channel) ? Log::channel($channel) : Log::getFacadeRoot();

        $logger->{$level}('legacy-bridge: '.$message, $context);
    }
}
