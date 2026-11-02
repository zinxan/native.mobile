<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Mobile;

use Throwable;
use ZXN\NativeWatch\Core\Client;

/**
 * Drains diagnostics while a native component's PHP request remains open.
 */
final readonly class NativeUiLifecycle
{
    public function __construct(
        private Client $client,
        private NativeCrashes $nativeCrashes,
        private FatalErrors $fatalErrors,
    ) {}

    public function handle(): void
    {
        try {
            $this->nativeCrashes->collect();
            $this->fatalErrors->collectFrom(storage_path('nativewatch/php-errors.log'));
        } catch (Throwable) {
            // A malformed or inaccessible report must not stop queued delivery.
        }

        try {
            $this->client->storeActivities();
            $this->client->flush();
        } catch (Throwable) {
            // Diagnostics must never escape into the native event loop.
        } finally {
            $this->client->scope->clear();
        }
    }
}
