<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Mobile;

use Illuminate\Contracts\Foundation\Application;
use Native\Mobile\Runtime;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Core\Config;
use ZXN\NativeWatch\Core\Contracts\ContextProvider;
use ZXN\NativeWatch\Core\Contracts\ReportingConsent;
use ZXN\NativeWatch\Core\Laravel\NativeWatchServiceProvider;
use ZXN\NativeWatch\Mobile\Watchers\PushNotificationWatcher;

final class MobileServiceProvider extends NativeWatchServiceProvider
{
    protected function reportingConsent(Application $app): ?ReportingConsent
    {
        $bridge = new Bridge;

        return $bridge->isAvailable()
            ? new NativeConsent($bridge, $app->storagePath('nativewatch/php-errors.log'), configurePhpLog: ! $app->runningUnitTests())
            : parent::reportingConsent($app);
    }

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
     * Hook into the persistent runtime: report the fatal error of the last request, forget its
     * scope, and collect the native crashes of earlier launches.
     */
    protected function bootPlatform(Client $client): void
    {
        $this->app->singleton(NativeCrashes::class);
        $this->app->singleton(FatalErrors::class);

        $errorLog = $this->app->storagePath('nativewatch/php-errors.log');

        // Only on a device. The embedded PHP of NativePHP reports the "cli" SAPI, so
        // runningInConsole() cannot tell a device from a terminal. Tests must still see errors.
        if ($client->isEnabled() && new Bridge()->isAvailable() && ! $this->app->runningUnitTests()) {
            FatalErrors::logTo($errorLog);
        }

        if (class_exists(Runtime::class)) {
            Runtime::onReset(function () use ($client): void {
                $this->app->make(FatalErrors::class)->report(error_get_last());
                $client->scope->clear();
            });
        }

        if ($this->app->make('config')->get('nativewatch.activity.push-notifications.enabled', true)) {
            new PushNotificationWatcher()->register($this->app, $client->activities, []);
        }

        $this->app->terminating(function () use ($client, $errorLog): void {
            $collected = $this->app->make(NativeCrashes::class)->collect()
                + $this->app->make(FatalErrors::class)->collectFrom($errorLog);

            if ($collected > 0) {
                $client->flush();
            }
        });
    }
}
