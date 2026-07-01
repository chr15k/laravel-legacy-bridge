<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Http\Middleware;

use Chr15k\LegacyBridge\Config;
use Chr15k\LegacyBridge\Contracts\LegacyContextResolver;
use Chr15k\LegacyBridge\Contracts\LegacyIntegration;
use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;
use Chr15k\LegacyBridge\Payload\LegacyPayload;
use Chr15k\LegacyBridge\Payload\PayloadDecoder;
use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class LegacySessionBridge
{
    public function __construct(
        private Auth $auth,
        private Config $config,
        private PayloadDecoder $decoder,
        private LegacyIntegration $integration,
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
            // Never let bridge failures break the request lifecycle — the user will land on the guest flow.
            $this->log('error', 'bridge failed', ['error' => $throwable->getMessage()]);
        }

        $response = $next($request);

        if ($this->config->shouldInvalidateAfterWrite()) {
            $this->invalidateLegacySession($request);
        }

        return $response;
    }

    private function fetchLegacyCookie(Request $request)
    {
        return $request->cookie($this->config->cookie());
    }

    private function shouldBridge(Request $request): bool
    {
        if ($this->auth->guard()->check()) {
            return false;
        }

        return (bool) $this->fetchLegacyCookie($request);
    }

    private function bridge(Request $request): ?string
    {
        $cookie = $this->fetchLegacyCookie($request);

        $sessionId = $this->integration->resolveSessionIdFromCookie($cookie);

        if ($sessionId === null) {
            return null;
        }

        $row = $this->fetchLegacySession($sessionId);

        if ($row === null) {
            return null;
        }

        $payload = $this->decoder->decode($row->payload, $this->config->format());

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

    /**
     * @return object{
     *   id: string,
     *   user_id: ?int,
     *   ip_address: ?string,
     *   user_agent: ?string,
     *   payload: string,
     *   last_activity: int
     * }|null
     */
    private function fetchLegacySession(string $sessionId): ?object
    {
        /**
         * @var object{
         *   id: string,
         *   user_id: ?int,
         *   ip_address: ?string,
         *   user_agent: ?string,
         *   payload: string,
         *   last_activity: int
         * }|null
         */
        return DB::connection($this->config->connection())
            ->table($this->config->table())
            ->where('id', $sessionId)
            ->where('last_activity', '>', now()->subMinutes($this->config->lifetime())->timestamp)
            ->first();
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
        $cookie = $this->fetchLegacyCookie($request);

        $resolvedSessionId = $this->integration->resolveSessionIdFromCookie($cookie);

        if ($this->config->invalidation() === 'never') {
            return;
        }

        try {
            DB::connection($this->config->connection())
                ->table($this->config->table())
                ->where('id', $resolvedSessionId)
                ->delete();

            Cookie::queue(Cookie::forget($this->config->cookie()));

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
