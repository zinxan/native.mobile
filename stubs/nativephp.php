<?php

declare(strict_types=1);

namespace Native\Mobile {
    final class Runtime
    {
        public static function onReset(callable $callback): void {}
    }
}

namespace {
    function nativephp_call(string $method, string $parameters): string|false|null
    {
        return null;
    }
}
