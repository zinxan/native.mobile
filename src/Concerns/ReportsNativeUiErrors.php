<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Mobile\Concerns;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Native\Mobile\Edge\NativeComponent;
use Throwable;
use WeakMap;
use ZXN\NativeWatch\Mobile\NativeUiLifecycle;

/**
 * Reports errors that NativePHP 4's native component loop handles itself.
 * A throwing interaction keeps its scope until the outer loop paints/reports it.
 *
 * @phpstan-require-extends NativeComponent
 */
trait ReportsNativeUiErrors
{
    /** @var WeakMap<Throwable, true>|null */
    private ?WeakMap $nativeWatchReportedErrors = null;

    public function renderErrorScreen(Throwable $e): void
    {
        try {
            parent::renderErrorScreen($e);
        } finally {
            $this->nativeWatchReportedErrors ??= new WeakMap;

            if (! isset($this->nativeWatchReportedErrors[$e])) {
                $this->nativeWatchReportedErrors[$e] = true;

                try {
                    app(ExceptionHandler::class)->report($e);
                } catch (Throwable) {
                    // Reporting must not replace the component's error screen.
                }
            }

            $this->flushNativeWatchDiagnostics();
        }
    }

    /** @param array<array-key, mixed> $event */
    protected function dispatchUiEvent(array $event): void
    {
        parent::dispatchUiEvent($event);
        $this->flushNativeWatchDiagnostics();
    }

    /** @param array<array-key, mixed> $event */
    protected function dispatchNativeEvent(array $event): void
    {
        parent::dispatchNativeEvent($event);
        $this->flushNativeWatchDiagnostics();
    }

    private function flushNativeWatchDiagnostics(): void
    {
        try {
            app(NativeUiLifecycle::class)->handle();
        } catch (Throwable) {
            // Resolving an optional diagnostic service must not break the app.
        }
    }
}
