<?php

declare(strict_types=1);

namespace Native\Mobile\Events\PushNotification;

if (! class_exists(TokenGenerated::class)) {
    final class TokenGenerated
    {
        public function __construct(public string $token, public ?string $id = null) {}
    }
}
