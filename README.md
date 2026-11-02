# zinxan/native-mobile

[Contributing](https://github.com/zinxan/native.core/blob/main/CONTRIBUTING.md)

Error tracking for NativePHP iOS and Android apps. Errors go to [NativeWatch](https://native.watch).

**Start with [iOS consent and native UI setup](docs/ios-consent.md).** It includes
copyable NativePHP 4 components, refusal/withdrawal behavior and a device test checklist.

```bash
composer require zinxan/native-mobile
```

Add the key of your NativeWatch project to `.env`:

```env
NATIVEWATCH_KEY=nw_...
NATIVEWATCH_URL=https://native.watch
```

Register the plugin, so its Swift and Kotlin crash reporter is compiled into your app:

```bash
php artisan vendor:publish --tag=nativephp-plugins-provider
php artisan native:plugin:register zinxan/native-mobile
```

Native reporting starts only after explicit opt-in in the updated plugin. Read
the [privacy guide and runnable consent example](https://native.watch/docs/privacy-and-consent) before
shipping, and rebuild the native app after upgrading. Use
`NativeWatch::setConsent(true)` only after the user's affirmative choice;
`NativeWatch::setConsent(false)` withdraws it and clears pending SDK reports.
See the [installation verification steps](https://native.watch/docs/install-mobile) for actual errors and device tests.

NativePHP 4 native UI components need the opt-in `ReportsNativeUiErrors` trait
from mobile SDK **0.2.1 or later**. It reports errors handled by the native
component loop and drains queued diagnostics after real UI interactions,
while preserving the normal error overlay. Add it to every screen, including
nested components, or to your app's shared native component base. See the
[native UI setup](docs/ios-consent.md#native-ui-error-capture).

Native crashes (Objective-C and Swift on iOS, uncaught exceptions, ANRs and NDK crashes on Android) are stored on the device and sent the next time the app opens.

For iOS function/file/line resolution, archive the exact Release dSYMs and upload
them from CI with `php artisan nativewatch:symbols`. Follow the complete
[symbolication guide](https://native.watch/docs/ios-symbolication) for build settings, CI secrets,
worker tools and verification. Diagnostic consent still controls crash capture
and delivery; a CI upload token must never ship in the app.

Send a test error:

```bash
php artisan nativewatch:test
```

After consent, reportable exceptions enter a bounded device outbox and normally
send after responses. Failed network delivery can retry later. Capture and disk
work still have a cost; an unwritable outbox can cause an immediate send attempt.

Report something yourself:

```php
use ZXN\NativeWatch\Core\Laravel\NativeWatch;
use ZXN\NativeWatch\Core\Level;

NativeWatch::addBreadcrumb('sync', 'Started the sync');
NativeWatch::captureMessage('The disk is almost full', Level::Warning);
NativeWatch::captureException($exception);
```

Publish the config to change sampling, scrubbing or breadcrumbs:

```bash
php artisan vendor:publish --tag=nativewatch-config
```
