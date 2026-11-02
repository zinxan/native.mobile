<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

it('loads the mobile provider and optional UI trait without the native runtime installed', function (): void {
    Http::preventStrayRequests();

    $script = <<<'PHP'
require $argv[1];
$source = $argv[2];
spl_autoload_register(static function (string $class) use ($source): void {
    $prefix = 'ZXN\\NativeWatch\\Mobile\\';
    if (str_starts_with($class, $prefix)) {
        $file = $source.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) require $file;
    }
}, true, true);
if (class_exists('Native\\Mobile\\Runtime') || class_exists('Native\\Mobile\\Edge\\NativeComponent')) exit(1);
$app = require $argv[3];
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app->make('config')->set([
    'nativewatch.enabled' => true,
    'nativewatch.key' => 'nw_compatibility_test',
    'nativewatch.url' => 'https://zxguard.test',
    'nativewatch.require_consent' => true,
    'nativewatch.consent.path' => $argv[4].'/missing-consent.json',
    'nativewatch.outbox.path' => $argv[4].'/outbox',
]);
$app->register(ZXN\NativeWatch\Mobile\MobileServiceProvider::class);
if (! trait_exists(ZXN\NativeWatch\Mobile\Concerns\ReportsNativeUiErrors::class)) exit(2);
echo 'optional-runtime-compatible';
PHP;

    $process = new Process([
        PHP_BINARY, '-r', $script,
        base_path('vendor/autoload.php'),
        dirname(__DIR__).'/src',
        base_path('bootstrap/app.php'),
        temporaryOutbox(),
    ], base_path(), ['JUMP_BRIDGE_PORT' => false]);
    $process->mustRun();

    expect($process->getOutput())->toBe('optional-runtime-compatible');
    Http::assertNothingSent();
});
