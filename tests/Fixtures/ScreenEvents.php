<?php

declare(strict_types=1);

namespace Native\Mobile\Events\Screen;

if (! class_exists(ScreenMounted::class)) {
    final class ScreenMounted
    {
        public function __construct(public string $component, public ?string $uri = null) {}
    }
}

if (! class_exists(ScreenResumed::class)) {
    final class ScreenResumed
    {
        public function __construct(public string $component, public ?string $uri = null) {}
    }
}

if (! class_exists(ScreenUnmounted::class)) {
    final class ScreenUnmounted
    {
        public function __construct(public string $component, public ?string $uri = null) {}
    }
}
