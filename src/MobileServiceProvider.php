<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Mobile;

use Illuminate\Contracts\Foundation\Application;
use Native\Mobile\Runtime;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Core\Config;
use ZXN\NativeWatch\Core\Contracts\ContextProvider;
use ZXN\NativeWatch\Core\Laravel\NativeWatchServiceProvider;

final class MobileServiceProvider extends NativeWatchServiceProvider
{
    /**
     * Get the name of the SDK, as it shows on every event.
     */
    protected function sdkName(): string
    {
        return 'zinxan/native-mobile';
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
     * Hook into the persistent runtime and collect the native crashes of earlier launches.
     */
    protected function bootPlatform(Client $client): void
    {
        if (class_exists(Runtime::class)) {
            Runtime::onReset(fn (): null => $client->scope->clear());
        }

        $this->app->singleton(NativeCrashes::class);

        $this->app->terminating(function () use ($client): void {
            if ($this->app->make(NativeCrashes::class)->collect() > 0) {
                $client->flush();
            }
        });
    }
}
