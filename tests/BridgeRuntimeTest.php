<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Mobile\Bridge;
use ZXN\NativeWatch\Mobile\MobileServiceProvider;

require_once __DIR__.'/Fixtures/Runtime.php';
require_once __DIR__.'/Fixtures/bridge.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'nativephp-internal.running' => false,
        'nativewatch.key' => 'nw_mobile', 'nativewatch.url' => 'https://zxguard.test',
        'nativewatch.require_consent' => true,
        'nativewatch.outbox.path' => temporaryOutbox(),
        'nativewatch.consent.path' => temporaryOutbox().'/consent.json',
    ]);
    $GLOBALS['nativewatch_bridge'] = ['reports' => [], 'calls' => [], 'generation' => null];
});

it('does not mistake an off-device PHP bridge function for a native runtime', function (): void {
    expect(function_exists('nativephp_call'))->toBeTrue()->and(new Bridge()->isAvailable())->toBeFalse();
    $this->app->register(MobileServiceProvider::class);
    $client = app(Client::class);

    expect($client->hasConsent())->toBeFalse()->and($client->setConsent(true))->toBeTrue()
        ->and($client->hasConsent())->toBeTrue();
    expect($GLOBALS['nativewatch_bridge']['calls'])->toBe([]);
});

it('keeps device reporting behind the native consent bridge', function (): void {
    config(['nativephp-internal.running' => true]);
    $this->app->register(MobileServiceProvider::class);

    expect(new Bridge()->isAvailable())->toBeTrue()->and(app(Client::class)->hasConsent())->toBeFalse();
    $GLOBALS['nativewatch_bridge']['consent_failure'] = true;
    expect(app(Client::class)->setConsent(true))->toBeFalse();
    Http::assertNothingSent();
});

it('recognizes an explicitly configured Jump relay without probing it', function (): void {
    $previous = getenv('JUMP_BRIDGE_PORT');
    try {
        putenv('JUMP_BRIDGE_PORT=3002');
        expect(new Bridge()->isAvailable())->toBeTrue();
        expect($GLOBALS['nativewatch_bridge']['calls'])->toBe([]);
    } finally {
        putenv($previous === false ? 'JUMP_BRIDGE_PORT' : 'JUMP_BRIDGE_PORT='.$previous);
    }
});
