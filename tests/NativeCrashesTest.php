<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ZXN\NativeWatch\Core\Client;
use ZXN\NativeWatch\Mobile\Bridge;
use ZXN\NativeWatch\Mobile\MobileServiceProvider;
use ZXN\NativeWatch\Mobile\NativeCrashes;

require_once __DIR__.'/Fixtures/Runtime.php';
require_once __DIR__.'/Fixtures/bridge.php';

/**
 * @return array{id: string, json: string}
 */
function nativeCrashReport(string $id = '7f1c4a0e-5b8e-4c1a-9a51-8d3c2b1a0f00'): array
{
    return ['id' => $id, 'json' => json_encode([
        'event_id' => $id,
        'timestamp' => '2026-10-24T21:13:08Z',
        'level' => 'fatal',
        'release' => '3.0.9',
        'exception' => [
            'type' => 'NSInternalInconsistencyException',
            'value' => 'Invalid update: invalid number of rows in section 0.',
            'frames' => [
                ['file' => 'CoreFoundation', 'line' => 0, 'function' => '__exceptionPreprocess + 164', 'in_app' => false],
                ['file' => 'NativePHP', 'line' => 0, 'function' => 'NotesListController.reload() + 88', 'in_app' => true],
            ],
        ],
        'context' => ['crash' => ['mechanism' => 'ios.nsexception', 'handled' => false]],
    ], JSON_THROW_ON_ERROR)];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake(['zxguard.test/*' => Http::response(status: 202)]);
    $GLOBALS['nativewatch_bridge'] = ['reports' => [], 'calls' => []];

    config([
        'nativephp-internal.running' => true,
        'nativewatch.key' => 'nw_mobile',
        'nativewatch.url' => 'https://zxguard.test',
        'nativewatch.outbox.path' => temporaryOutbox(),
        'nativephp.version' => '3.1.0',
    ]);
});

it('sends the native crashes of an earlier launch after the first response', function (): void {
    $GLOBALS['nativewatch_bridge']['reports'] = [nativeCrashReport()];
    $this->app->register(MobileServiceProvider::class);

    $this->app->terminate();

    Http::assertSent(fn (Request $request): bool => $request['event_id'] === '7f1c4a0e-5b8e-4c1a-9a51-8d3c2b1a0f00'
        && $request['level'] === 'fatal'
        && $request['timestamp'] === '2026-10-24T21:13:08Z'
        && $request['release'] === '3.0.9'
        && $request['platform'] === 'ios'
        && $request['exception']['type'] === 'NSInternalInconsistencyException'
        && $request['context']['crash']['mechanism'] === 'ios.nsexception'
        && $request['context']['device']['model'] === 'iPhone17,1'
        && $request['sdk']['name'] === 'zinxan/native-mobile');

    expect($GLOBALS['nativewatch_bridge']['calls'])->toContain(
        ['NativeWatch.DeleteCrashReports', ['ids' => ['7f1c4a0e-5b8e-4c1a-9a51-8d3c2b1a0f00']]],
    );
});

it('preserves exact symbolication evidence through native collection and event completion', function (): void {
    $entry = nativeCrashReport();
    $report = json_decode($entry['json'], true, flags: JSON_THROW_ON_ERROR);
    $native = ['image_uuid' => '1352463a-578e-3bdc-8944-fb98c0c96b31', 'architecture' => 'arm64', 'instruction_offset' => '0x1f580', 'address_kind' => 'return_address'];
    $report['exception']['frames'][1]['native'] = $native;
    $entry['json'] = json_encode($report, JSON_THROW_ON_ERROR);
    $GLOBALS['nativewatch_bridge']['reports'] = [$entry];
    $this->app->register(MobileServiceProvider::class);
    $this->app->terminate();
    Http::assertSent(fn (Request $request): bool => $request['exception']['frames'][1]['native'] === $native);
});

it('collects native crashes once per launch', function (): void {
    $GLOBALS['nativewatch_bridge']['reports'] = [nativeCrashReport()];
    $this->app->register(MobileServiceProvider::class);
    $crashes = resolve(NativeCrashes::class);

    expect($crashes->collect())->toBe(1)
        ->and($crashes->collect())->toBe(0);
});

it('deletes reports that native code wrote broken', function (): void {
    $GLOBALS['nativewatch_bridge']['reports'] = [['id' => 'broken', 'json' => '{not json']];
    $this->app->register(MobileServiceProvider::class);

    expect(resolve(NativeCrashes::class)->collect())->toBe(1);

    Http::assertNothingSent();
    expect($GLOBALS['nativewatch_bridge']['calls'])->toContain(['NativeWatch.DeleteCrashReports', ['ids' => ['broken']]]);
});

it('crashes the app on purpose through the bridge', function (): void {
    $this->app->register(MobileServiceProvider::class);

    expect(resolve(NativeCrashes::class)->crashForTest())->toBeTrue()
        ->and(resolve(NativeCrashes::class)->crashForTest('fatal'))->toBeTrue()
        ->and($GLOBALS['nativewatch_bridge']['calls'])
        ->toContain(['NativeWatch.TestCrash', ['kind' => 'exception']])
        ->toContain(['NativeWatch.TestCrash', ['kind' => 'fatal']]);
});

it('treats a bridge error as an empty answer', function (): void {
    expect(new Bridge()->call('NativeWatch.Broken'))->toBe([]);
});

it('retains the native report when both the outbox and delivery fail', function (): void {
    $path = temporaryOutbox().'/not-a-directory';
    file_put_contents($path, 'occupied');
    config(['nativewatch.outbox.path' => $path, 'nativewatch.url' => 'https://offline.test']);
    Http::fake(['offline.test/*' => Http::response(status: 503)]);
    $GLOBALS['nativewatch_bridge']['reports'] = [nativeCrashReport()];
    $this->app->register(MobileServiceProvider::class);

    expect(resolve(NativeCrashes::class)->collect())->toBe(0);

    expect(array_column($GLOBALS['nativewatch_bridge']['calls'], 0))->not->toContain('NativeWatch.DeleteCrashReports');
});

it('deletes native reports that the app deliberately discards', function (bool $sampledOut): void {
    config(['nativewatch.sample_rate' => $sampledOut ? 0.0 : 1.0]);
    $GLOBALS['nativewatch_bridge']['reports'] = [nativeCrashReport()];
    $this->app->register(MobileServiceProvider::class);

    if (! $sampledOut) {
        resolve(Client::class)->beforeSend(fn (array $event): null => null);
    }

    expect(resolve(NativeCrashes::class)->collect())->toBe(1)
        ->and($GLOBALS['nativewatch_bridge']['calls'])->toContain(['NativeWatch.DeleteCrashReports', ['ids' => [nativeCrashReport()['id']]]]);
    Http::assertNothingSent();
})->with([false, true]);
