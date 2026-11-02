<?php

declare(strict_types=1);

namespace App\NativeComponents;

use App\Actions\SetDiagnosticConsent;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;
use ZXN\NativeWatch\Core\Laravel\NativeWatch;
use ZXN\NativeWatch\Mobile\Concerns\ReportsNativeUiErrors;

final class Diagnostics extends NativeComponent
{
    use ReportsNativeUiErrors;

    public bool $sharing = false;

    public string $saveError = '';

    public function mount(): void
    {
        $this->sharing = NativeWatch::hasConsent();
    }

    public function updatedSharing(bool $granted): void
    {
        $this->saveError = '';

        try {
            app(SetDiagnosticConsent::class)->handle($granted);
        } catch (ValidationException) {
            $this->saveError = 'Your diagnostic choice could not be confirmed. Please try again.';
        }

        $this->sharing = NativeWatch::hasConsent();
    }

    public function render(): View
    {
        return view('native.diagnostics');
    }
}
