<?php

declare(strict_types=1);

namespace ZXN\ZXGuard\Mobile;

use ZXN\ZXGuard\Core\Client;

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
        if ($this->collected || ! $this->bridge->isAvailable()) {
            return 0;
        }

        $this->collected = true;

        $reports = $this->bridge->call('ZXGuard.PendingCrashReports')['reports'] ?? [];
        $ids = [];

        foreach (is_array($reports) ? $reports : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null)) {
                continue;
            }

            $report = Bridge::decode($entry['json'] ?? null);

            if ($report !== []) {
                $this->client->captureEvent($report);
            }

            $ids[] = $entry['id'];
        }

        if ($ids !== []) {
            $this->bridge->call('ZXGuard.DeleteCrashReports', ['ids' => $ids]);
        }

        return count($ids);
    }

    /**
     * Crash the app on purpose, to check that crash reporting works. Returns false off-device.
     */
    public function crashForTest(): bool
    {
        if (! $this->bridge->isAvailable()) {
            return false;
        }

        $this->bridge->call('ZXGuard.TestCrash');

        return true;
    }
}
