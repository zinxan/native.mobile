<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Runtime;
use ZXN\NativeWatch\Core\Laravel\NativeWatch;
use ZXN\NativeWatch\Mobile\FatalErrors;
use ZXN\NativeWatch\Mobile\MobileContext;
use ZXN\NativeWatch\Mobile\MobileServiceProvider;

require_once __DIR__.'/Fixtures/Runtime.php';
require_once __DIR__.'/Fixtures/bridge.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    Runtime::$callbacks = [];
    $GLOBALS['nativewatch_bridge'] = ['reports' => [], 'calls' => [], 'generation' => str_repeat('a', 32)];

    config([
        'nativephp-internal.running' => true,
        'nativewatch.key' => 'nw_mobile',
        'nativewatch.url' => 'https://zxguard.test',
        'nativewatch.outbox.path' => temporaryOutbox(),
        'nativephp.version' => '3.1.0',
    ]);
});

it('reports exceptions with the device of the native bridge', function (): void {
    $this->app->register(MobileServiceProvider::class);

    report(new RuntimeException('Camera permission denied.'));
    $this->app->terminate();

    Http::assertSent(fn (Request $request): bool => $request['platform'] === 'ios'
        && $request['release'] === '3.1.0'
        && $request['context']['os'] === ['name' => 'iOS', 'version' => '26.0']
        && $request['context']['device']['model'] === 'iPhone17,1'
        && $request['context']['device']['simulator'] === true
        && ! array_key_exists('name', $request['context']['device'])
        && $request['sdk']['name'] === 'zinxan/native-mobile');
});

it('only sends the device name when personal data is allowed', function (): void {
    $context = new MobileContext(config(), '13.0.0', sendDefaultPii: true);

    expect($context->context()['device']['name'])->toBe("Tilly's iPhone");
});

it('ignores the debug version of a development build', function (): void {
    config(['nativephp.version' => 'DEBUG']);

    expect(new MobileContext(config(), '13.0.0')->release())->toBeNull();
});

it('forgets breadcrumbs when the persistent runtime serves the next request', function (): void {
    $this->app->register(MobileServiceProvider::class);

    NativeWatch::addBreadcrumb('navigation', 'Opened the camera');
    Runtime::reset();
    NativeWatch::captureMessage('After reset');
    NativeWatch::flush();

    Http::assertSent(fn (Request $request): bool => $request['breadcrumbs'] === []);
});

it('reports the fatal error that ended the last request of the persistent runtime', function (): void {
    $this->app->register(MobileServiceProvider::class);
    NativeWatch::addBreadcrumb('navigation', 'Opened the import');

    $this->app->make(FatalErrors::class)->report([
        'type' => E_ERROR,
        'message' => 'Allowed memory size of 134217728 bytes exhausted (tried to allocate 500032 bytes)',
        'file' => base_path('app/Import.php'),
        'line' => 42,
    ]);
    $this->app->terminate();

    Http::assertSent(fn (Request $request): bool => $request['level'] === 'fatal'
        && $request['exception']['type'] === Symfony\Component\ErrorHandler\Error\FatalError::class
        && str_starts_with($request['exception']['value'], 'Allowed memory size')
        && $request['exception']['frames'][0]['file'] === 'app/Import.php'
        && $request['exception']['frames'][0]['line'] === 42
        && $request['breadcrumbs'][0]['message'] === 'Opened the import');
});

it('leaves warnings of the last request alone', function (): void {
    $this->app->register(MobileServiceProvider::class);

    $this->app->make(FatalErrors::class)->report(['type' => E_WARNING, 'message' => 'Undefined variable', 'file' => 'a.php', 'line' => 1]);
    $this->app->terminate();

    Http::assertNothingSent();
});

it('reports the fatal errors that PHP wrote to its log before the runtime stopped', function (): void {
    $this->app->register(MobileServiceProvider::class);
    $log = temporaryOutbox().'/php-errors.log';
    file_put_contents($log, implode("\n", [
        '[26-Oct-2026 16:58:30 UTC] PHP Warning:  Undefined variable $x in /app/a.php on line 3',
        '[26-Oct-2026 16:58:31 UTC] PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 500032 bytes) in '.base_path('app/Import.php').' on line 42',
        '',
    ]));

    expect($this->app->make(FatalErrors::class)->collectFrom($log))->toBe(1)
        ->and(file_get_contents($log))->toBe('');
    $this->app->terminate();

    Http::assertSent(fn (Request $request): bool => $request['level'] === 'fatal'
        && str_starts_with($request['exception']['value'], 'Allowed memory size')
        && $request['exception']['frames'][0]['file'] === 'app/Import.php'
        && $request['exception']['frames'][0]['line'] === 42);
});
