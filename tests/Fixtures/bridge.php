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
            'NativeWatch.GetConsent' => json_encode(['generation' => array_key_exists('generation', $GLOBALS['nativewatch_bridge']) ? $GLOBALS['nativewatch_bridge']['generation'] : str_repeat('a', 32)]),
            'NativeWatch.SetConsent' => nativeWatchTestConsent(json_decode($parameters, true)['granted']),
            'NativeWatch.Broken' => json_encode(['status' => 'error', 'code' => 'x', 'message' => 'Broken', 'data' => []]),
            default => json_encode([]),
        };
    }
}

function nativeWatchTestConsent(bool $granted): string
{
    if ($GLOBALS['nativewatch_bridge']['consent_failure'] ?? false) {
        return json_encode(['saved' => false], JSON_THROW_ON_ERROR);
    }

    $GLOBALS['nativewatch_bridge']['generation'] = $granted ? bin2hex(random_bytes(16)) : null;
    $GLOBALS['nativewatch_bridge']['reports'] = [];

    return json_encode(['saved' => true], JSON_THROW_ON_ERROR);
}
