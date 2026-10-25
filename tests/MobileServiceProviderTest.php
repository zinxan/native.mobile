<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Runtime;
use ZXN\ZXGuard\Core\Laravel\ZXGuard;
use ZXN\ZXGuard\Mobile\MobileContext;
use ZXN\ZXGuard\Mobile\MobileServiceProvider;

require_once __DIR__.'/Fixtures/Runtime.php';
require_once __DIR__.'/Fixtures/bridge.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    Runtime::$callbacks = [];

    config([
        'zxguard.key' => 'zxg_mobile',
        'zxguard.url' => 'https://zxguard.test',
        'zxguard.outbox.path' => temporaryOutbox(),
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
        && $request['sdk']['name'] === 'zxn/zx-mobile');
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

    ZXGuard::addBreadcrumb('navigation', 'Opened the camera');
    Runtime::reset();
    ZXGuard::captureMessage('After reset');
    ZXGuard::flush();

    Http::assertSent(fn (Request $request): bool => $request['breadcrumbs'] === []);
});
