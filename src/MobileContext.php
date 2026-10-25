<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Mobile;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository;
use ZXN\NativeWatch\Core\Contracts\ContextProvider;

final class MobileContext implements ContextProvider
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $device = null;

    public function __construct(
        private readonly Repository $config,
        private readonly string $laravelVersion,
        private readonly bool $sendDefaultPii = false,
        private readonly Bridge $bridge = new Bridge,
    ) {}

    /**
     * Get the platform that the app runs on: "ios", "android", or "mobile" outside a device.
     */
    public function platform(): string
    {
        $platform = $this->device()['platform'] ?? null;

        return in_array($platform, ['ios', 'android'], true) ? $platform : 'mobile';
    }

    /**
     * Get the version of the NativePHP app.
     */
    public function release(): ?string
    {
        $version = $this->config->get('nativephp.version');

        return is_string($version) && $version !== '' && $version !== 'DEBUG' ? $version : null;
    }

    /**
     * Get the context that describes the device, the OS and the runtime.
     *
     * @return array<string, array<string, mixed>>
     */
    public function context(): array
    {
        $device = $this->device();

        return array_filter([
            'os' => $this->only($device, ['operatingSystem' => 'name', 'osVersion' => 'version']),
            'device' => $this->only($device, [
                'model' => 'model',
                'manufacturer' => 'manufacturer',
                'isVirtual' => 'simulator',
                'memUsed' => 'memory_used',
                'language' => 'language',
                ...$this->sendDefaultPii ? ['name' => 'name'] : [],
            ]),
            'app' => array_filter([
                'id' => $this->config->get('nativephp.app_id'),
                'version' => $this->release(),
            ], fn (mixed $value): bool => $value !== null),
            'runtime' => array_filter([
                'php' => PHP_VERSION,
                'laravel' => $this->laravelVersion,
                'nativephp' => $this->packageVersion('nativephp/mobile'),
                'webview' => $device['webViewVersion'] ?? null,
            ]),
        ]);
    }

    /**
     * Get the device info from the native bridge, once per process.
     *
     * @return array<string, mixed>
     */
    private function device(): array
    {
        return $this->device ??= Bridge::decode($this->bridge->call('Device.GetInfo')['info'] ?? null);
    }

    /**
     * Pick keys of the device info and rename them.
     *
     * @param  array<string, mixed>  $source
     * @param  array<string, string>  $keys  Source key to target key.
     * @return array<string, mixed>
     */
    private function only(array $source, array $keys): array
    {
        $picked = [];

        foreach ($keys as $from => $to) {
            if (array_key_exists($from, $source)) {
                $picked[$to] = $source[$from];
            }
        }

        return $picked;
    }

    private function packageVersion(string $package): ?string
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)
            ? InstalledVersions::getPrettyVersion($package)
            : null;
    }
}
