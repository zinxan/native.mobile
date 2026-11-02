<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Mobile\Tests\Fixtures;

use Native\Mobile\Edge\NativeComponent;
use ZXN\NativeWatch\Mobile\Concerns\ReportsNativeUiErrors;

/** PHPStan checks the opt-in trait against the supported parent's API. */
final class NativeUiTypeCheck extends NativeComponent
{
    use ReportsNativeUiErrors;
}
