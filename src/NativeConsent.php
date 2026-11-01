<?php

declare(strict_types=1);

namespace ZXN\NativeWatch\Mobile;

use ZXN\NativeWatch\Core\Contracts\ReportingConsent;

final class NativeConsent implements ReportingConsent
{
    private bool $blocked = false;

    public function __construct(private readonly Bridge $bridge, private readonly string $fatalLog, private readonly bool $configurePhpLog = true) {}

    public function generation(): ?string
    {
        $generation = $this->blocked ? null : ($this->bridge->call('NativeWatch.GetConsent')['generation'] ?? null);

        return is_string($generation) && preg_match('/^[a-f0-9]{32}$/D', $generation) === 1 ? $generation : null;
    }

    public function setGranted(bool $granted): bool
    {
        $this->blocked = true;
        $response = $this->bridge->call('NativeWatch.SetConsent', ['granted' => $granted]);

        if (($response['saved'] ?? false) !== true) {
            return false;
        }

        if (! $granted && (string) ini_get('error_log') === $this->fatalLog) {
            ini_set('error_log', '');
        }

        if (is_file($this->fatalLog) && ! @unlink($this->fatalLog)) {
            return false;
        }

        if ($granted && $this->configurePhpLog) {
            FatalErrors::logTo($this->fatalLog);
        }

        $this->blocked = false;

        return true;
    }
}
