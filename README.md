# NativeWatch for NativePHP Mobile

<img src="https://raw.githubusercontent.com/zinxan/native.mobile/main/docs/nativewatch.svg" width="72" height="72" alt="NativeWatch" />

Error tracking for NativePHP iOS and Android apps. Report Laravel exceptions,
native UI errors and native crashes to [NativeWatch](https://native.watch), with
device context, breadcrumbs and a durable on-device delivery queue.

The plugin is **free and open source under the [MIT license](LICENSE)**.
It sends diagnostics to a NativeWatch project. The hosted service requires an
account and offers a free tier and paid tiers; see [current plans](https://native.watch/#pricing).

## Requirements

| Component | Requirement |
| --- | --- |
| PHP | 8.4 or later within PHP 8.x |
| Laravel | 11, 12 or 13, through `zinxan/native-core` |
| NativePHP Mobile | NativePHP 4; native UI integration verified with 4.5 |
| iOS | 17.0 or later |
| Android | API 26 (Android 8.0) or later |
| Android historical ANR/NDK reports | API 30 (Android 11) or later |

NativePHP's own build requirements also apply. The plugin declares its Swift
Package Manager dependencies in `nativephp.json`: the NativeWatch KSCrash fork
`2.6.1-nativewatch.1` and Apple Swift Atomics `1.3.1`. Android uses platform APIs
and does not declare additional Gradle dependencies or runtime permissions.

## Install

Install [NativePHP Mobile 4](https://nativephp.com/docs/mobile/4/getting-started/introduction) in your app
first. The plugin steps below use its `nativephp-plugins-provider` tag and
`native:plugin:*` commands, which do not exist before NativePHP Mobile is
installed.

Create a mobile project in [NativeWatch](https://native.watch) and copy its
ingest key. Then install SDK 0.2.1 or later:

```bash
composer require zinxan/native-mobile:^0.2.1
```

Add these settings to your app's `.env`:

```dotenv
NATIVEWATCH_ENABLED=true
NATIVEWATCH_REQUIRE_CONSENT=true
NATIVEWATCH_KEY=nw_your_project_key
NATIVEWATCH_URL=https://native.watch
```

Publish the NativePHP provider once, then register the plugin:

```bash
php artisan vendor:publish --tag=nativephp-plugins-provider
php artisan native:plugin:register zinxan/native-mobile
php artisan native:plugin:validate vendor/zinxan/native-mobile
php artisan config:clear
```

Keep any plugins already registered in `NativeServiceProvider`; do not
force-publish over that file. Rebuild your actual iOS or Android app using
NativePHP's normal build command. Rebuild after native SDK upgrades too:
Composer installation, a browser preview or Jump alone does not verify the
Swift/Kotlin reporter is compiled into your app.

## Ask for diagnostic consent

Native reporting starts denied. Add an optional Settings control and explain
what your app sends before asking the user to enable it. Keep your app usable
when they decline.

```php
use ZXN\NativeWatch\Core\Laravel\NativeWatch;

$sharing = NativeWatch::hasConsent();
$saved = NativeWatch::setConsent($granted); // The user's affirmative choice.
```

Check the boolean result before showing success. `setConsent(false)` withdraws
consent and clears pending SDK reports and scope. It cannot recall events
already sent to the service. A missing or failing native consent bridge stays
denied.

Start with the [runnable consent and native UI example](https://github.com/zinxan/native.mobile/blob/main/docs/ios-consent.md)
and the [privacy guide](https://native.watch/docs/privacy-and-consent). The guide
covers disclosure, refusal, withdrawal, redaction and app privacy declarations.
The SDK does not complete your app's privacy policy or store submissions.

## Capture NativePHP native UI errors

NativePHP 4's component loop handles some errors before Laravel's ordinary
request hooks can report them. In SDK **0.2.1 or later**, add the opt-in adapter
to every native screen and child component, or to your shared component base:

```php
use Native\Mobile\Edge\NativeComponent;
use ZXN\NativeWatch\Mobile\Concerns\ReportsNativeUiErrors;

abstract class AppNativeComponent extends NativeComponent
{
    use ReportsNativeUiErrors;
}
```

The adapter preserves NativePHP's normal error screen, reports through Laravel's
exception policy and attempts queued delivery after UI interactions. It clears
scope between interactions and does not record native tree or event payloads.
See the [complete native UI setup](https://github.com/zinxan/native.mobile/blob/main/docs/ios-consent.md#native-ui-error-capture).

## Report errors and messages

Laravel's reportable exceptions are captured automatically after consent.
For errors your app handles itself, call `report($exception)` or the facade:

```php
use ZXN\NativeWatch\Core\Laravel\NativeWatch;
use ZXN\NativeWatch\Core\Level;

NativeWatch::addBreadcrumb('sync', 'Started the sync');
NativeWatch::captureMessage('The disk is almost full', Level::Warning);
NativeWatch::captureException($exception);
```

Publish configuration to adjust sampling, scrubbing and activity collection:

```bash
php artisan vendor:publish --tag=nativewatch-config
```

After consent, events enter a bounded device outbox and normally send after
responses or supported native UI boundaries. Failed network delivery can retry
later. Capture, disk work and delivery attempts have a cost; if the outbox is
unwritable, capture can attempt immediate network delivery. Do not assume
guaranteed background delivery or an automatic reconnect callback.

## Native crashes and source locations

The native reporter stores crashes locally for collection on a later launch:

- **iOS:** KSCrash captures Objective-C, Mach and signal crashes, including fatal
  Swift failures. MetricKit can add delayed device diagnostics.
- **Android:** uncaught JVM exceptions are recorded before the previous handler
  runs. On Android 11+, `ApplicationExitInfo` supplies historical ANR and NDK
  crash information when the operating system makes it available.

Function, file and line resolution on iOS requires the dSYMs from the **exact
Release binary**. Some optimized frames have no line information. Upload dSYMs
from CI with `php artisan nativewatch:symbols` and a separate private CI token;
never include that token in an app. Follow the
[iOS symbolication guide](https://native.watch/docs/ios-symbolication).
Android ProGuard/R8 mapping and native symbolication are separate work.

To show the commit that likely caused an error, publish each release from CI
with `php artisan nativewatch:release`. It needs `NATIVEWATCH_RELEASE_TOKEN`, a
CI-only secret. See [release and commit tracking](https://native.watch/docs/releases-and-commits).

## Verify your integration

Use a dedicated project and disposable app data. Verify the rebuilt native app
with consent off, consent on, a real application error, offline retention and
retry, and withdrawal before reconnecting. Match real event IDs in NativeWatch.
The [installation guide](https://native.watch/docs/install-mobile) and
[device checklist](https://github.com/zinxan/native.mobile/blob/main/docs/ios-consent.md#verify-on-the-real-ios-app)
describe the full process. `php artisan nativewatch:test` is a transport smoke
test; it does not establish native crash capture or consent behavior in a build.

The published [JabKeeper Release Simulator acceptance record](https://github.com/zinxan/native.mobile/blob/main/docs/review/ios-consent/ios-simulator-acceptance.md)
includes actual consent/error screens, native crash/relaunch ingestion and
offline retry evidence. It records the limits of that run. Physical-iPhone and
Android-device acceptance, physical flight mode and MetricKit delivery were not
completed by that record; verify them in your app before release.

## Documentation and support

- [NativeWatch documentation](https://native.watch/docs)
- [Consent and native UI example](https://github.com/zinxan/native.mobile/blob/main/docs/ios-consent.md)
- [Report a bug or request a feature](https://github.com/zinxan/native.mobile/issues)
- [Contributing](https://github.com/zinxan/native.core/blob/main/CONTRIBUTING.md)
- [MIT license](LICENSE)
