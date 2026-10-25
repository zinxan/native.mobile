<?php

declare(strict_types=1);

if (! function_exists('nativephp_call')) {
    function nativephp_call(string $method, string $parameters): string|false
    {
        return $method === 'Device.GetInfo'
            ? json_encode(['info' => json_encode([
                'name' => "Tilly's iPhone",
                'model' => 'iPhone17,1',
                'platform' => 'ios',
                'operatingSystem' => 'iOS',
                'osVersion' => '26.0',
                'manufacturer' => 'Apple',
                'isVirtual' => true,
                'webViewVersion' => '26.0',
            ])])
            : false;
    }
}
