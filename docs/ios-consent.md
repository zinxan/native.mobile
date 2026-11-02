# iOS consent with NativePHP 4

NativeWatch's mobile plugin already provides a persisted native consent gate.
You do not need to write Swift, request an advertising identifier, or call an
iOS permission dialog. Add your app's disclosure and optional control before
allowing collection. Keep the app usable when the user declines.

## Install and rebuild

```sh
composer require zinxan/native-mobile
php artisan vendor:publish --tag=nativephp-plugins-provider
php artisan native:plugin:register zinxan/native-mobile
```

If your app already has `NativeServiceProvider`, retain its existing plugin list;
do not force-publish over it. Add these variables before booting the SDK:

```dotenv
NATIVEWATCH_ENABLED=true
NATIVEWATCH_REQUIRE_CONSENT=true
NATIVEWATCH_KEY=nw_your_project_key
NATIVEWATCH_URL=https://native.watch
```

Clear cached configuration, then rebuild the actual native app. For example,
with an available iOS Simulator UDID and NativePHP Mobile 4:

```sh
php artisan config:clear
php artisan native:plugin:validate vendor/zinxan/native-mobile
php artisan native:run ios YOUR_SIMULATOR_UDID --build=release
```

Composer installation, a PHP browser preview or Jump alone does not establish
that the installed binary contains the native reporter. A missing or failing
native consent bridge remains denied. Test the rebuilt app.

## Add a native Settings control

Copy these small files into the corresponding directories of your app:

- [Action](examples/native-ui/app/Actions/SetDiagnosticConsent.php)
- [Native component](examples/native-ui/app/NativeComponents/Diagnostics.php)
- [Native Blade view](examples/native-ui/resources/views/native/diagnostics.blade.php)
- [Pest component tests](examples/native-ui/tests/Feature/DiagnosticsTest.php)

Register the component alongside your existing native routes, using your app's
usual settings layout and navigation:

```php
use App\NativeComponents\Diagnostics;
use Illuminate\Support\Facades\Route;

Route::native('/settings/diagnostics', Diagnostics::class);
```

The example uses NativePHP 4's `native:model` toggle and `updatedSharing()` hook.
It reads the real saved state on mount and after each attempt. A failed write
shows an error and restores that state; it never displays a fabricated success.
Adapt the disclosure and add an accessible link to **your app's** privacy policy
before shipping. Do not present the generic example text as your complete notice.
The Pest example uses your app's `Tests\TestCase`, the real SDK client and a
controlled consent boundary. It tests PHP/native-component behavior, not the
compiled Swift reporter. Run it with `php artisan test --filter=DiagnosticsTest`.

## Native UI error capture

NativePHP 4.5 catches component errors itself and keeps one PHP request open
while its native event loop waits. Laravel's normal exception and response
termination hooks do not cover every interaction in that loop.

Mobile SDK **0.2.1 and later** provides an explicit adapter. Use the trait in each
native component, including child components, or in your own shared base:

```php
use Native\Mobile\Edge\NativeComponent;
use ZXN\NativeWatch\Mobile\Concerns\ReportsNativeUiErrors;

abstract class AppNativeComponent extends NativeComponent
{
    use ReportsNativeUiErrors;
}
```

Make your app's screens extend that base. The adapter preserves the parent error
overlay, reports the original exception through Laravel's reporting policy, and
avoids reporting a repaint of the same exception twice. It stores activities and
attempts delivery after UI/native callbacks; screen lifecycle events also collect
previous native/PHP fatal reports and drain the outbox before the loop waits.
Scope is cleared between interactions/screens. The adapter never records native
tree or event payloads, and collection/delivery remains behind diagnostic consent.
A failed interaction retains its scope until the outer error screen reports it,
so its breadcrumbs are included before that scope is cleared.
Delivery attempts use the configured SDK network timeouts. They run after the
parent interaction/error screen, while the PHP event loop still owns that thread.

An app catch that handles an error without displaying the component error screen
must still call `report($error)` or `NativeWatch::captureException($error)` if it
should be reported. Native SDK callbacks, native local notifications and bridge
calls are not automatically Laravel HTTP requests, jobs or mail events. Report
only categories the app actually uses, and verify its real failure and delivery
paths in the compiled app. A working toggle or CLI test is not that evidence.

For an existing Settings component, the essential calls are:

```php
use ZXN\NativeWatch\Core\Laravel\NativeWatch;

$sharing = NativeWatch::hasConsent();
$saved = NativeWatch::setConsent($granted); // A boolean from the user's choice.
```

Only call `setConsent(true)` after an affirmative choice. Do not grant in a
service provider, seed, login handler or health-permission callback. Native
consent belongs to this installation, independently of app authentication and
HealthKit access. A fresh or unreadable state is denied.

`setConsent(false)` records denial and clears pending SDK reports, the PHP
outbox, buffered activities and user scope. Reports already dispatched cannot
be recalled. Uploaded reports follow the server's retention/deletion process.
Granting again starts a new interval; historical reports are not relabeled and
sent. Treat a `false` result as an unconfirmed update, not success.

## Explain what leaves the device

The standard payload can include error messages, stack paths, source excerpts,
device/runtime details and activity metadata. Arbitrary exception text, logs,
raw SQL literals, cache keys and URL paths can contain personal or health data.
`send_default_pii=false` and key scrubbing do not make these anonymous.

For health, financial or other sensitive apps, inspect actual outgoing payloads.
Disable unnecessary watchers, set `source_code_lines` to `0`, disable log
breadcrumbs when needed, and use `NativeWatch::beforeSend()` for app-specific
redaction. It must handle both exception events and `type=activities` batches.
Keep native UUID, architecture, binary offset and address kind when retaining
native frames, so matching dSYMs can still resolve them. Do not promise that no
diary data leaves until your payload tests establish that.

Identify the app operator, NativeWatch recipient, actual categories, purpose,
retention and deletion contact. Disclose any additional AI processing separately;
general crash-report permission is not blanket permission for AI use.

## Apple privacy requirements

- Apple's [collection rules](https://developer.apple.com/app-store/review/guidelines/#data-collection-and-storage)
  address consent, withdrawal and privacy-policy disclosures. An in-app optional
  diagnostics control is separate from Apple's own analytics-sharing setting.
- [ATT](https://developer.apple.com/app-store/user-privacy-and-data-use/) concerns
  tracking across other companies' apps/sites for advertising or data brokers.
  Technical diagnostics for maintaining your app do not by themselves require
  ATT. Assess your complete app and other SDKs separately.
- Declare the actual crash, performance and other diagnostic data in
  [App Store Connect](https://developer.apple.com/app-store/app-privacy-details/).
  Ongoing collection remains disclosable after an initial opt-in. It does not
  meet Apple's optional-disclosure exemption just because the toggle is optional.
- Audit the final archive's privacy manifests, including transitive SDKs and
  required-reason APIs. This plugin does not populate App Store Connect forms or
  generate your app's complete privacy manifest. Do not leave a host manifest
  claiming no collected data when the app sends diagnostics.

## Verify on the real iOS app

Use disposable app data and a dedicated project/key or clearly identified test
build. Record event IDs and screenshots; do not manually insert dashboard rows.

1. Fresh install: leave sharing off, trigger a real app error and relaunch. Verify
   no report is collected or delivered. A browser check is not native evidence.
2. Turn sharing on through Settings. Trigger a real application failure. Match
   its event ID, release, environment and native context in NativeWatch.
3. Go offline. Trigger another failure. Confirm it remains pending, reconnect,
   then resume/use the app and verify delivery. There is no promise of a network
   reconnect callback or guaranteed background delivery.
4. Repeat offline, then turn sharing off before reconnecting. Confirm pending
   reports clear and do not arrive. Opt in again and verify a new failure works
   while the old interval's report stays absent.
5. In an internal test build, use the plugin's real native test crash below.
   Run without an attached debugger, relaunch and verify the crash arrives.
   Test `exception` and `fatal` separately. Remove test controls before release.

```php
use ZXN\NativeWatch\Mobile\NativeCrashes;

app(NativeCrashes::class)->crashForTest('exception');
// In a separate run:
app(NativeCrashes::class)->crashForTest('fatal');
```

Full native source locations require dSYMs from that exact binary. Upload them
from CI using the separate private release token, which must never enter the
app bundle. Follow the [symbolication guide](https://native.watch/docs/ios-symbolication).
MetricKit reports are delayed and require physical-device verification; simulator
crash tests do not establish MetricKit delivery.
