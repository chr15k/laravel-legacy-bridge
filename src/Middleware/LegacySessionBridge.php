<?php

namespace Chr15k\LegacyBridge\Middleware;

use Chr15k\LegacyBridge\Payload\LegacyPayload;
use Chr15k\LegacyBridge\Payload\PayloadDecoder;
use Chr15k\LegacyBridge\Resolvers\Contracts\LegacyContextResolver;
use Chr15k\LegacyBridge\Resolvers\Contracts\LegacyUserResolver;
use Closure;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class LegacySessionBridge
{
    public function __construct(
        private Auth $auth,
        private PayloadDecoder $decoder,
        private LegacyUserResolver $resolver,
        private ?LegacyContextResolver $contextResolver = null,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldBridge($request)) {
            return $next($request);
        }

        try {
            $this->bridge($request);
        } catch (Throwable $throwable) {
            // Never let bridge failures break the request lifecycle.
            // Log and continue — the user will land on the guest flow.
            $this->log('error', 'bridge failed', ['error' => $throwable->getMessage()]);
        }

        $response = $next($request);

        if ($this->shouldInvalidateAfterWrite()) {
            $this->invalidateLegacySession(
                $request->cookie($this->cookieName())
            );
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

    private function bridge(Request $request): void
    {
        $sessionId = $request->cookie($this->cookieName());
        $row = $this->fetchLegacySession($sessionId);

        if ($row === null) {
            return;
        }

        $payload = $this->decoder->decode(
            $row->payload,
            config('legacy-bridge.format', 'auto'),
        );

        if ($payload->isEmpty()) {
            return;
        }

        $userId = $this->resolver->resolve($payload);

        if (! $userId) {
            return;
        }

        $this->auth->guard()->loginUsingId($userId);
        $this->hydrateContext($userId, $payload);

        if (config('legacy-bridge.invalidation') === 'immediate') {
            $this->invalidateLegacySession($sessionId);
        }

        $this->log('info', 'session bridged', [
            'user_id'    => $userId,
            'session_id' => mb_substr($sessionId, 0, 8).'…',
            'format'     => $payload->format(),
        ]);
    }

    private function fetchLegacySession(string $sessionId): ?object
    {
        $lifetime = (int) config('legacy-bridge.lifetime', 120);

        return DB::connection(config('legacy-bridge.connection'))
            ->table(config('legacy-bridge.table'))
            ->where('id', $sessionId)
            ->where('last_activity', '>', now()->subMinutes($lifetime)->timestamp)
            ->first();
    }

    private function hydrateContext(int $userId, LegacyPayload $payload): void
    {
        $carryKeys = config('legacy-bridge.context.carry_keys', []);

        if (! empty($carryKeys)) {
            foreach ($payload->only($carryKeys) as $key => $value) {
                session([$key => $value]);
            }
        }

        if (config('legacy-bridge.context.flash', false)) {
            $flash = $payload->get('_flash', []);
            foreach ((array) ($flash['new'] ?? []) as $key) {
                session()->flash($key, $payload->get($key));
            }
        }

        if ($this->contextResolver instanceof LegacyContextResolver) {
            foreach ($this->contextResolver->resolve($userId, $payload) as $key => $value) {
                session([$key => $value]);
            }
        }
    }

    private function invalidateLegacySession(string $sessionId): void
    {
        if (config('legacy-bridge.invalidation') === 'never') {
            return;
        }

        try {
            DB::connection(config('legacy-bridge.connection'))
                ->table(config('legacy-bridge.table'))
                ->where('id', $sessionId)
                ->delete();
        } catch (Throwable $throwable) {
            $this->log('warning', 'could not invalidate legacy session', [
                'error' => $throwable->getMessage(),
            ]);
        }
    }

    private function shouldInvalidateAfterWrite(): bool
    {
        return config('legacy-bridge.invalidation') === 'after_write';
    }

    private function cookieName(): string
    {
        return config('legacy-bridge.cookie', 'PHPSESSID');
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (! config('legacy-bridge.logging.enabled', true)) {
            return;
        }

        $channel = config('legacy-bridge.logging.channel');
        $logger = $channel ? Log::channel($channel) : Log::getFacadeRoot();

        $logger->{$level}('legacy-bridge: '.$message, $context);
    }
}
