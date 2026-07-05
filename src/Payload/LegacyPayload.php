<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge\Payload;

use Chr15k\LegacyBridge\Enums\PayloadFormat;
use Illuminate\Support\Arr;

/**
 * A safe, traversable wrapper around a decoded legacy session payload.
 *
 * Provides dot-notation access, nested object resolution, and type-safe
 * scalar extraction without the consumer needing to know anything about
 * the raw format the legacy app used.
 */
final readonly class LegacyPayload
{
    /**
     * @param  array<mixed>  $data
     */
    public function __construct(private array $data) {}

    /**
     * Get a value from the payload using dot-notation.
     *
     * Examples:
     *   $payload->get('user_id')
     *   $payload->get('auth.user.id')
     *   $payload->get('cart.items.0.product_id')
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->data, $key, $default);
    }

    /**
     * Determine whether a key exists and is non-null in the payload.
     */
    public function has(string $key): bool
    {
        return Arr::get($this->data, $key) !== null;
    }

    /**
     * Resolve a user ID from a given dot-notation path, handling the
     * common case where the value is a scalar, an array with an 'id'
     * key, or a serialized object with an 'id' property.
     *
     * Examples:
     *   $payload->resolveId('user_id')                    // scalar
     *   $payload->resolveId('auth.user.id')               // nested scalar
     *   $payload->resolveId('cartalyst.sentinel')         // object/array with id
     */
    public function resolveId(string $path): ?int
    {
        $value = $this->get($path);

        $scalar = match (true) {
            is_int($value) || is_string($value) => $value,
            is_object($value)                   => $value->id ?? null,
            is_array($value)                    => $value['id'] ?? null,
            default                             => null,
        };

        if (! is_int($scalar) && ! is_string($scalar)) {
            return null;
        }

        return (int) $scalar ?: null;
    }

    /**
     * Return the raw decoded payload as an array.
     *
     * @return array<mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Return only the specified keys from the payload.
     *
     * @param  list<string>  $keys
     * @return array<mixed>
     */
    public function only(array $keys): array
    {
        return Arr::only($this->data, $keys);
    }

    /**
     * Determine whether the payload contains any data at all.
     */
    public function isEmpty(): bool
    {
        return $this->data === [];
    }
}
