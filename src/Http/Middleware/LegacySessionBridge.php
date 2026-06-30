<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Http\Middleware;

use Chr15k\LegacyBridge\Concerns\DecryptsLegacySessionData;
use Chr15k\LegacyBridge\Config;
use Chr15k\LegacyBridge\Contracts\LegacyContextResolver;
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
    use DecryptsLegacySessionData;

    public function __construct(
        private Auth $auth,
        private PayloadDecoder $decoder,
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

        // At this point we know we have a legacy cookie for this user.

        try {
            $this->bridge($request);
        } catch (Throwable $throwable) {
            // Never let bridge failures break the request lifecycle.
            // Log and continue — the user will land on the guest flow.
            $this->log('error', 'bridge failed', ['error' => $throwable->getMessage()]);
        }

        $response = $next($request);

        if ($this->shouldInvalidateAfterWrite()) {
            $this->invalidateLegacySession($request);
        }

        return $response;
    }

    private function shouldBridge(Request $request): bool
    {
        if ($this->auth->guard()->check()) {
            return false;
        }

        return (bool) $request->cookie($this->cookieName());
    }

    /**
     * Returns the resolved session ID if successfully bridged.
     */
    private function bridge(Request $request): ?string
    {
        $sessionId = $this->resolveSessionId($request);

        if ($sessionId === null) {
            return null;
        }

        $row = $this->fetchLegacySession($sessionId);

        if ($row === null) {
            return null;
        }

        $payload = $this->decoder->decode($row->payload, Config::format());

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

        if (Config::invalidation() === 'immediate') {
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
        return DB::connection(Config::connection())
            ->table(Config::table())
            ->where('id', $sessionId)
            ->where('last_activity', '>', now()->subMinutes(Config::lifetime())->timestamp)
            ->first();
    }

    private function resolveSessionId(Request $request): ?string
    {
        $raw = $request->cookie($this->cookieName());

        if (! is_string($raw)) {
            return null;
        }

        if (Config::cookieEncryption() === 'laravel') {
            return $this->decrypt(payload: $raw, unserialize: false, isCookie: true);
        }

        return $raw;
    }

    private function hydrateContext(int $userId, LegacyPayload $payload): void
    {
        $carryKeys = Config::contextCarryKeys();

        if ($carryKeys !== []) {
            /** @var array<string, mixed> $context */
            $context = $payload->only($carryKeys);
            session($context);
        }

        if (Config::contextFlash()) {
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
        $resolvedSessionId = $this->resolveSessionId($request);

        if (Config::invalidation() === 'never') {
            return;
        }

        try {
            DB::connection(Config::connection())
                ->table(Config::table())
                ->where('id', $resolvedSessionId)
                ->delete();

            Cookie::queue(Cookie::forget($this->cookieName()));

        } catch (Throwable $throwable) {
            $this->log('warning', 'could not invalidate legacy session', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function shouldInvalidateAfterWrite(): bool
    {
        return Config::invalidation() === 'after_write';
    }

    private function cookieName(): string
    {
        return Config::cookie();
    }

    /**
     * @param  array<mixed>  $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (! Config::loggingEnabled()) {
            return;
        }

        $channel = Config::loggingChannel();

        $logger = is_string($channel) ? Log::channel($channel) : Log::getFacadeRoot();

        $logger->{$level}('legacy-bridge: '.$message, $context);
    }
}
