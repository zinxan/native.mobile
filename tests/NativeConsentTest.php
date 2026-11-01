<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Mobile\MobileServiceProvider;

require_once __DIR__.'/Fixtures/Runtime.php';
require_once __DIR__.'/Fixtures/bridge.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    $GLOBALS['nativewatch_bridge'] = ['reports' => [], 'calls' => [], 'generation' => null];
    config(['nativephp-internal.running' => true, 'nativewatch.key' => 'nw_mobile', 'nativewatch.url' => 'https://zxguard.test', 'nativewatch.outbox.path' => temporaryOutbox()]);
});

it('consults native consent before device context and native reports', function (): void {
    $this->app->register(MobileServiceProvider::class);
    report(new RuntimeException('Declined diagnostics'));
    $this->app->terminate();
    Http::assertNothingSent();
    $methods = array_column($GLOBALS['nativewatch_bridge']['calls'], 0);
    expect($methods)->not->toContain('Device.GetInfo', 'NativeWatch.PendingCrashReports');
});

it('grants and withdraws the real mobile client through the bridge boundary', function (): void {
    $this->app->register(MobileServiceProvider::class);
    $client = app(Client::class);
    expect($client->hasConsent())->toBeFalse()
        ->and($client->setConsent(true))->toBeTrue();
    $client->captureMessage('Granted interval');
    expect($client->pending())->toBe(1)
        ->and($client->setConsent(false))->toBeTrue()
        ->and($client->pending())->toBe(0)
        ->and($client->hasConsent())->toBeFalse();
    $client->flush();
    Http::assertNothingSent();
});

it('does not claim success when the native consent update fails', function (): void {
    $this->app->register(MobileServiceProvider::class);
    $GLOBALS['nativewatch_bridge']['consent_failure'] = true;
    $client = app(Client::class);
    expect($client->setConsent(true))->toBeFalse()
        ->and($client->isEnabled())->toBeFalse();
    $client->captureMessage('Do not send');
    $client->flush();
    Http::assertNothingSent();
});
