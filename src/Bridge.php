<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Mobile;

use JsonException;

final readonly class Bridge
{
    /**
     * Decode a JSON object, or return an empty array for anything else.
     *
     * @return array<string, mixed>
     */
    public static function decode(mixed $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Determine if the app runs on a device, where native code answers.
     */
    public function isAvailable(): bool
    {
        return function_exists('nativephp_call');
    }

    /**
     * Call a native bridge function. Returns an empty array off-device or when the call fails.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function call(string $function, array $parameters = []): array
    {
        if (! $this->isAvailable()) {
            return [];
        }

        try {
            $response = self::decode(nativephp_call($function, json_encode($parameters, JSON_THROW_ON_ERROR)));
        } catch (JsonException) {
            return [];
        }

        return ($response['status'] ?? null) === 'error' ? [] : $response;
    }
}
