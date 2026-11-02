<?php

declare(strict_types=1);

namespace Native\Mobile {
    final class Runtime
    {
        public static function onReset(callable $callback): void {}
    }
}

namespace Native\Mobile\Events\PushNotification {
    final class TokenGenerated
    {
        public function __construct(public string $token, public ?string $id = null) {}
    }
}

namespace Native\Mobile\Events\Screen {
    final class ScreenMounted
    {
        public function __construct(public string $component, public ?string $uri = null) {}
    }

    final class ScreenResumed
    {
        public function __construct(public string $component, public ?string $uri = null) {}
    }

    final class ScreenUnmounted
    {
        public function __construct(public string $component, public ?string $uri = null) {}
    }
}

namespace {
    function nativephp_call(string $method, string $parameters): string|false|null
    {
        return null;
    }
}
