<?php

declare(strict_types=1);

/**
 * A fake native bridge. Tests put crash reports in $GLOBALS['nativewatch_bridge']['reports']
 * and read the calls that PHP made from $GLOBALS['nativewatch_bridge']['calls'].
 */
$GLOBALS['nativewatch_bridge'] = ['reports' => [], 'calls' => []];

if (! function_exists('nativephp_call')) {
    function nativephp_call(string $method, string $parameters): string|false
    {
        $GLOBALS['nativewatch_bridge']['calls'][] = [$method, json_decode($parameters, true)];

        return match ($method) {
            'Device.GetInfo' => json_encode(['info' => json_encode([
                'name' => "Tilly's iPhone",
                'model' => 'iPhone17,1',
                'platform' => 'ios',
                'operatingSystem' => 'iOS',
                'osVersion' => '26.0',
                'manufacturer' => 'Apple',
                'isVirtual' => true,
                'webViewVersion' => '26.0',
            ])]),
            'NativeWatch.PendingCrashReports' => json_encode(['reports' => $GLOBALS['nativewatch_bridge']['reports']]),
            'NativeWatch.Broken' => json_encode(['status' => 'error', 'code' => 'x', 'message' => 'Broken', 'data' => []]),
            default => json_encode([]),
        };
    }
}
