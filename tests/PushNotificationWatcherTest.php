<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\PushNotification\TokenGenerated;
use ZXN\NativeWatch\Mobile\MobileServiceProvider;

require_once __DIR__.'/Fixtures/Runtime.php';
require_once __DIR__.'/Fixtures/bridge.php';
require_once __DIR__.'/Fixtures/TokenGenerated.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    $GLOBALS['nativewatch_bridge'] = ['reports' => [], 'calls' => [], 'generation' => str_repeat('a', 32)];
    config(['nativephp-internal.running' => true, 'nativewatch.key' => 'nw_mobile', 'nativewatch.url' => 'https://zxguard.test', 'nativewatch.outbox.path' => temporaryOutbox()]);
});

it('records push notification enrollments without the token', function (): void {
    $this->app->register(MobileServiceProvider::class);

    event(new TokenGenerated('secret-device-token'));

    $entries = sentActivities('push-notifications');

    expect($entries)->toHaveCount(1)
        ->and($entries[0])->name->toBe('token')->status->toBe('registered')
        ->and($entries[0]['attributes'])->toBe(['event' => 'token', 'platform' => 'ios'])
        ->and(json_encode($entries))->not->toContain('secret-device-token');
});

it('can be turned off', function (): void {
    config(['nativewatch.activity.push-notifications.enabled' => false]);
    $this->app->register(MobileServiceProvider::class);

    event(new TokenGenerated('secret-device-token'));

    expect(sentActivities('push-notifications'))->toBe([]);
});
