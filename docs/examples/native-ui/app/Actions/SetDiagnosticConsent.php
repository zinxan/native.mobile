<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Validation\ValidationException;
use ZXN\NativeWatch\Core\Laravel\NativeWatch;

final class SetDiagnosticConsent
{
    public function handle(bool $granted): void
    {
        if (! NativeWatch::setConsent($granted)) {
            throw ValidationException::withMessages([
                'diagnostics' => 'Your diagnostic choice could not be confirmed. Please try again.',
            ]);
        }
    }
}
