<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Mobile\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Native\Mobile\Events\PushNotification\TokenGenerated;
use ZXN\NativeWatch\Core\Activity\ActivityRecorder;
use ZXN\NativeWatch\Core\Contracts\ContextProvider;
use ZXN\NativeWatch\Core\Laravel\Watchers\Watcher;

/**
 * Records push notification enrollments. NativePHP Mobile does not expose deliveries
 * or taps to PHP yet; they need native hooks in the plugin.
 */
final class PushNotificationWatcher implements Watcher
{
    public function signal(): string
    {
        return 'push-notifications';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function register(Application $app, ActivityRecorder $recorder, array $options): void
    {
        $app->make(Dispatcher::class)->listen(TokenGenerated::class, function () use ($app, $recorder): void {
            $recorder->record($this->signal(), 'token', 'registered', null, [
                'event' => 'token',
                'platform' => $app->make(ContextProvider::class)->platform(),
            ]);
        });
    }
}
