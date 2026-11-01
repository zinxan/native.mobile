<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Mobile;

use ZXN\NativeWatch\Core\Client;

final class NativeCrashes
{
    private bool $collected = false;

    public function __construct(
        private readonly Bridge $bridge,
        private readonly Client $client,
    ) {}

    /**
     * Move the crash reports that native code stored into the outbox, once per launch.
     *
     * Returns the number of reports that were collected.
     */
    public function collect(): int
    {
        if ($this->collected || ! $this->client->isEnabled() || ! $this->bridge->isAvailable()) {
            return 0;
        }

        $this->collected = true;

        $reports = $this->bridge->call('NativeWatch.PendingCrashReports')['reports'] ?? [];
        $ids = [];

        foreach (is_array($reports) ? $reports : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null)) {
                continue;
            }

            $report = Bridge::decode($entry['json'] ?? null);

            if ($report !== [] && $this->client->captureEventResult($report)->retryable) {
                continue;
            }

            $ids[] = $entry['id'];
        }

        if ($ids !== []) {
            $this->bridge->call('NativeWatch.DeleteCrashReports', ['ids' => $ids]);
        }

        return count($ids);
    }

    /**
     * Crash the app on purpose, to check that crash reporting works. Returns false off-device.
     *
     * "exception" raises an NSException (iOS) or a RuntimeException on the main thread (Android).
     * "fatal" calls fatalError() (iOS, a signal) or throws on a background thread (Android).
     */
    public function crashForTest(string $kind = 'exception'): bool
    {
        if (! $this->bridge->isAvailable()) {
            return false;
        }

        $this->bridge->call('NativeWatch.TestCrash', ['kind' => $kind]);

        return true;
    }
}
