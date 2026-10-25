<?php

declare(strict_types=1);

namespace ZXN\ZXGuard\Mobile;

use Illuminate\Contracts\Foundation\Application;
use Native\Mobile\Runtime;
use ZXN\ZXGuard\Core\Client;
use ZXN\ZXGuard\Core\Config;
use ZXN\ZXGuard\Core\Contracts\ContextProvider;
use ZXN\ZXGuard\Core\Laravel\ZXGuardServiceProvider;

final class MobileServiceProvider extends ZXGuardServiceProvider
{
    /**
     * Get the name of the SDK, as it shows on every event.
     */
    protected function sdkName(): string
    {
        return 'zxn/zx-mobile';
    }

    /**
     * Get the context provider of the platform.
     */
    protected function contextProvider(Application $app): ContextProvider
    {
        return new MobileContext(
            $app->make('config'),
            $app->version(),
            $app->make(Config::class)->sendDefaultPii,
        );
    }

    /**
     * Clear the breadcrumbs and user of a request before the persistent runtime serves the next one.
     */
    protected function bootPlatform(Client $client): void
    {
        if (class_exists(Runtime::class)) {
            Runtime::onReset(fn (): null => $client->scope->clear());
        }
    }
}
