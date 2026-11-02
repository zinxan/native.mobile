<?php

declare(strict_types=1);

use App\NativeComponents\Diagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Core\Config;
use ZXN\NativeWatch\Core\Contracts\ContextProvider;
use ZXN\NativeWatch\Core\Contracts\ReportingConsent;
use ZXN\NativeWatch\Core\EventFactory;
use ZXN\NativeWatch\Core\Laravel\NativeWatch;
use ZXN\NativeWatch\Core\Transport\Transport;

uses(RefreshDatabase::class);

function nativeWatchConsentExampleClient(ReportingConsent $consent): void
{
    $config = Config::fromArray(['key' => 'nw_example_test', 'url' => 'https://zxguard.test']);
    $context = Mockery::mock(ContextProvider::class);
    $transport = Mockery::mock(Transport::class);
    $transport->shouldNotReceive('send');
    NativeWatch::swap(new Client($config, new EventFactory($config, $context, 'zinxan/native-mobile'), $transport, consent: $consent));
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('starts with reporting off and an accessible native control', function (): void {
    $consent = Mockery::mock(ReportingConsent::class);
    $consent->shouldReceive('generation')->andReturn(null);
    nativeWatchConsentExampleClient($consent);
    Native::test(Diagnostics::class)
        ->assertSet('sharing', false)
        ->assertSee('Sharing is off. New reports are not collected or sent.')
        ->assertAccessible();
    Http::assertNothingSent();
});

it('updates the real saved state after an affirmative toggle', function (): void {
    $consent = Mockery::mock(ReportingConsent::class);
    $granted = false;
    $consent->shouldReceive('generation')->andReturnUsing(function () use (&$granted): ?string {
        return $granted ? str_repeat('a', 32) : null;
    });
    $consent->shouldReceive('setGranted')->once()->with(false)->andReturn(true);
    $consent->shouldReceive('setGranted')->once()->with(true)->andReturnUsing(function (bool $value) use (&$granted): bool {
        $granted = $value;

        return true;
    });
    nativeWatchConsentExampleClient($consent);
    Native::test(Diagnostics::class)
        ->toggle('diagnostics-toggle', true)
        ->assertSet('sharing', true)
        ->assertSet('saveError', '')
        ->assertSee('Sharing is on.');
    Http::assertNothingSent();
});

it('withdraws through the same native control', function (): void {
    $consent = Mockery::mock(ReportingConsent::class);
    $granted = true;
    $consent->shouldReceive('generation')->andReturnUsing(function () use (&$granted): ?string {
        return $granted ? str_repeat('a', 32) : null;
    });
    $consent->shouldReceive('setGranted')->once()->with(false)->andReturnUsing(function (bool $value) use (&$granted): bool {
        $granted = $value;

        return true;
    });
    nativeWatchConsentExampleClient($consent);
    Native::test(Diagnostics::class)
        ->toggle('diagnostics-toggle', false)
        ->assertSet('sharing', false)
        ->assertSet('saveError', '');
    Http::assertNothingSent();
});

it('shows a persistence failure and reads back the actual denied state', function (): void {
    $consent = Mockery::mock(ReportingConsent::class);
    $consent->shouldReceive('generation')->andReturn(null);
    $consent->shouldReceive('setGranted')->once()->with(false)->andReturn(true);
    $consent->shouldReceive('setGranted')->once()->with(true)->andReturn(false);
    nativeWatchConsentExampleClient($consent);
    Native::test(Diagnostics::class)
        ->toggle('diagnostics-toggle', true)
        ->assertSet('sharing', false)
        ->assertSee('Your diagnostic choice could not be confirmed. Please try again.')
        ->assertAccessible();
    Http::assertNothingSent();
});
