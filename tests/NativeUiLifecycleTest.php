<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\Screen\ScreenMounted;
use Native\Mobile\Events\Screen\ScreenResumed;
use Native\Mobile\Events\Screen\ScreenUnmounted;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Mobile\MobileServiceProvider;

require_once __DIR__.'/Fixtures/bridge.php';
require_once __DIR__.'/Fixtures/ScreenEvents.php';
require_once __DIR__.'/Fixtures/CrashReport.php';

beforeEach(function (): void {
    $this->app->useStoragePath(temporaryOutbox());
    Http::preventStrayRequests();
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    $GLOBALS['nativewatch_bridge'] = ['reports' => [], 'calls' => [], 'generation' => str_repeat('a', 32)];
    config([
        'nativephp-internal.running' => true,
        'nativewatch.key' => 'nw_mobile',
        'nativewatch.url' => 'https://zxguard.test',
        'nativewatch.outbox.path' => temporaryOutbox(),
    ]);
});

it('drains the outbox and clears scope at each available native screen boundary', function (string $event): void {
    $this->app->register(MobileServiceProvider::class);
    $client = app(Client::class);
    $client->addBreadcrumb('navigation', 'Leaving this screen');
    $client->captureMessage('Before the screen boundary');

    $this->app->make(Dispatcher::class)->dispatch(new $event('App\\Screen', '/private-diary-sentinel'));
    expect($client->pending())->toBe(0);
    $client->captureMessage('After the screen boundary');
    $client->flush();

    Http::assertSent(fn (Request $request): bool => $request['message'] === 'After the screen boundary'
        && $request['breadcrumbs'] === []
        && ! str_contains($request->body(), 'private-diary-sentinel'));
})->with([ScreenMounted::class, ScreenResumed::class, ScreenUnmounted::class]);

it('collects the previous native crash before a mounted screen waits for input', function (): void {
    $entry = nativeCrashReport();
    $GLOBALS['nativewatch_bridge']['reports'] = [$entry];
    $this->app->register(MobileServiceProvider::class);
    Http::assertNothingSent();

    $this->app->make(Dispatcher::class)->dispatch(new ScreenMounted('App\\Screen', '/'));

    Http::assertSent(fn (Request $request): bool => $request['event_id'] === $entry['id']
        && $request['context']['crash']['mechanism'] === 'ios.nsexception');
    expect($GLOBALS['nativewatch_bridge']['calls'])->toContain(
        ['NativeWatch.DeleteCrashReports', ['ids' => [$entry['id']]]],
    );
});

it('collects a prior PHP fatal on the first native screen without request termination', function (): void {
    $this->app->register(MobileServiceProvider::class);
    $directory = storage_path('nativewatch');
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    $log = $directory.'/php-errors.log';
    $previous = is_file($log) ? file_get_contents($log) : null;
    file_put_contents($log, '[02-Nov-2026 13:00:00 UTC] PHP Fatal error:  Prior native fatal in '.base_path('app/Import.php').' on line 42'."\n");

    try {
        $this->app->make(Dispatcher::class)->dispatch(new ScreenMounted('App\\Screen', '/'));
        Http::assertSent(fn (Request $request): bool => $request['exception']['value'] === 'Prior native fatal'
            && $request['level'] === 'fatal');
        expect(file_get_contents($log))->toBe('');
    } finally {
        if ($previous === null) {
            unlink($log);
        } else {
            file_put_contents($log, $previous);
        }
    }
});
