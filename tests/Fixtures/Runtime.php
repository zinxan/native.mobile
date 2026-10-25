<?php

declare(strict_types=1);

namespace Native\Mobile;

final class Runtime
{
    /**
     * @var list<callable>
     */
    public static array $callbacks = [];

    public static function onReset(callable $callback): void
    {
        self::$callbacks[] = $callback;
    }

    public static function reset(): void
    {
        foreach (self::$callbacks as $callback) {
            $callback();
        }
    }
}
