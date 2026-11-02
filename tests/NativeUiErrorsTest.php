<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Mobile\MobileServiceProvider;
use ZXN\NativeWatch\Mobile\NativeUiLifecycle;
use ZXN\NativeWatch\Mobile\Tests\Fixtures\NativeUiScreen;

require_once __DIR__.'/Fixtures/bridge.php';
require_once __DIR__.'/Fixtures/NativeUiParent.stub';

beforeEach(function (): void {
    $this->app->useStoragePath(temporaryOutbox());
    Http::preventStrayRequests();
    Http::fake(['zxguard.test/*' => static fn () => config('nativewatch.testing_offline', false)
        ? throw new ConnectionException('Offline')
        : Http::response(status: 202)]);
    $GLOBALS['nativewatch_bridge'] = ['reports' => [], 'calls' => [], 'generation' => str_repeat('a', 32)];
    config([
        'nativephp-internal.running' => true,
        'nativewatch.key' => 'nw_mobile',
        'nativewatch.url' => 'https://zxguard.test',
        'nativewatch.outbox.path' => temporaryOutbox(),
    ]);
    $this->app->register(MobileServiceProvider::class);
});

it('paints the original overlay before reporting a handled UI error', function (): void {
    $screen = new NativeUiScreen;
    $error = new RuntimeException('The native import failed.');
    $screen->onUiEvent = static fn () => throw $error;
    $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $reported) use ($screen, $error): void {
        expect($screen->painted)->toBe([$error])
            ->and($reported)->toBe($error);
    });

    $screen->press(['type' => 1, 'callback_id' => 7, 'health_value' => 'private-diary-sentinel']);

    expect($screen->uiEvents)->toHaveCount(1)
        ->and(app(Client::class)->pending())->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['exception']['value'] === 'The native import failed.'
        && ! str_contains($request->body(), 'private-diary-sentinel'));
});

it('reports the same error overlay only once when it is repainted', function (): void {
    $screen = new NativeUiScreen;
    $error = new RuntimeException('The same overlay.');
    $reports = 0;
    $this->app->make(ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$reports): void {
        $reports++;
    });

    $screen->renderErrorScreen($error);
    $screen->renderErrorScreen($error);

    expect($screen->painted)->toBe([$error, $error])->and($reports)->toBe(1);
    Http::assertSentCount(1);
});

it('reports an error handled by the native callback dispatcher', function (): void {
    $screen = new NativeUiScreen;
    $error = new RuntimeException('The native callback failed.');
    $screen->onNativeEvent = static fn () => throw $error;

    $screen->receiveNative(['event' => 'example.failed']);

    expect($screen->painted)->toBe([$error]);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request['exception']['value'] === 'The native callback failed.');
});

it('respects Laravel exceptions that should not be reported', function (): void {
    $error = new class extends RuntimeException implements ShouldntReport {};
    $screen = new NativeUiScreen;
    $screen->onUiEvent = static fn () => throw $error;

    $screen->press();

    expect($screen->painted)->toBe([$error]);
    Http::assertNothingSent();
});

it('keeps reporting denied while preserving the native error screen', function (): void {
    $GLOBALS['nativewatch_bridge']['generation'] = null;
    $screen = new NativeUiScreen;
    $screen->onUiEvent = static fn () => throw new RuntimeException('Declined UI error.');

    $screen->press();

    expect($screen->painted)->toHaveCount(1)->and(app(Client::class)->pending())->toBe(0);
    Http::assertNothingSent();
});

it('retries an offline error after a later successful interaction', function (): void {
    config(['nativewatch.testing_offline' => true]);
    $screen = new NativeUiScreen;
    $screen->onUiEvent = static fn () => throw new RuntimeException('Queued native UI error.');
    $screen->press();
    expect(app(Client::class)->pending())->toBe(1);

    config(['nativewatch.testing_offline' => false]);
    $screen->onUiEvent = null;
    $screen->press();

    expect(app(Client::class)->pending())->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request['exception']['value'] === 'Queued native UI error.');
});

it('drains activities and clears scope after native callbacks', function (): void {
    $client = app(Client::class);
    $screen = new NativeUiScreen;
    $screen->onNativeEvent = function () use ($client): void {
        $client->activities->record('jobs', 'Native callback completed', 'processed');
        $client->addBreadcrumb('test', 'Only this callback');
    };
    $event = ['event' => 'example.finished', 'payload' => ['health_value' => 'private-native-sentinel']];

    $screen->receiveNative($event);
    $client->captureMessage('After the native callback');
    $client->flush();

    expect($screen->nativeEvents)->toBe([$event]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://zxguard.test/api/v1/activities'
        && $request['entries'][0]['name'] === 'Native callback completed'
        && ! str_contains($request->body(), 'private-native-sentinel'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://zxguard.test/api/v1/occurrences'
        && $request['message'] === 'After the native callback'
        && $request['breadcrumbs'] === []);
});

it('contains reporting and lifecycle failures without changing parent behavior', function (): void {
    $screen = new NativeUiScreen;
    $this->app->bind(NativeUiLifecycle::class, static fn () => throw new RuntimeException('SDK resolution failed.'));
    $this->app->make(ExceptionHandler::class)->reportable(static fn (Throwable $exception) => throw new RuntimeException('Reporting failed.'));
    $error = new RuntimeException('The app error.');

    $screen->renderErrorScreen($error);
    $screen->press(['callback_id' => 9]);
    $screen->receiveNative(['event' => 'example.finished']);

    expect($screen->painted)->toBe([$error])
        ->and($screen->uiEvents)->toBe([['callback_id' => 9]])
        ->and($screen->nativeEvents)->toBe([['event' => 'example.finished']]);
});
