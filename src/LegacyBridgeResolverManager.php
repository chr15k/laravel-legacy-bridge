<?php

declare(strict_types=1);

namespace Chr15k\LegacyBridge;

use Chr15k\LegacyBridge\Contracts\LegacyUserResolver;
use Chr15k\LegacyBridge\Resolvers\AutoResolver;
use Chr15k\LegacyBridge\Resolvers\KeyResolver;
use Chr15k\LegacyBridge\Support\Config;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final readonly class LegacyBridgeResolverManager
{
    public function __construct(private Container $app, private Config $config) {}

    public function make(): LegacyUserResolver
    {
        $config = $this->config->resolver();
        $driver = $config['driver'];

        return match ($driver) {
            'auto'   => new AutoResolver,
            'key'    => new KeyResolver($config['key'] ?? 'user_id'),
            'custom' => $this->makeCustom($config['class'] ?? null),
            default  => throw new InvalidArgumentException(
                sprintf('legacy-bridge: unknown resolver driver [%s]. ', $driver).
                'Supported: auto, key, custom.'
            ),
        };
    }

    private function makeCustom(?string $class): LegacyUserResolver
    {
        if (! $class || ! class_exists($class)) {
            throw new InvalidArgumentException(
                'legacy-bridge: resolver driver is "custom" but resolver.class '.
                sprintf('[%s] does not exist. Publish the stub with: ', $class).
                'php artisan vendor:publish --tag=legacy-bridge-stubs'
            );
        }

        $resolver = $this->app->make($class);

        if (! $resolver instanceof LegacyUserResolver) {
            throw new InvalidArgumentException(
                sprintf('legacy-bridge: [%s] must implement ', $class).
                LegacyUserResolver::class
            );
        }

        return $resolver;
    }
}
