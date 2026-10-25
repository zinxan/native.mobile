# zinxan/native-mobile

`native-mobile` reports the errors of NativePHP iOS and Android apps, including SuperNative screens and native crashes. It is a NativePHP plugin (`"type": "nativephp-plugin"`, manifest in `nativephp.json`). The PHP side hooks `native-core` into the app; the native side catches crashes in Swift and Kotlin. Everything else comes from `native-core`. Read `packages/native-core/AGENTS.md` first.

| Path | What it is |
| --- | --- |
| `src/MobileServiceProvider.php` | Hooks into the persistent runtime and collects native crashes after the first response of a launch. |
| `src/MobileContext.php` | Reads the device, the OS and the app through the bridge. |
| `src/Bridge.php` | The only class that calls `nativephp_call()`. |
| `src/NativeCrashes.php` | Moves native crash reports into the outbox of `native-core`. |
| `resources/ios/Sources/` | Swift: the crash reporter (`init_function`) and the bridge functions. |
| `resources/android/src/` | Kotlin: the crash reporter (`init_function`) and the bridge functions. |

---

## 1. Rules

- Put a feature that desktop apps also need in `native-core`, not here.
- Call the bridge only through `nativephp_call()`, behind `function_exists('nativephp_call')`. Outside a device (tests, the web preview) the function does not exist, and the SDK reports the platform `mobile`.
- Read the device once per process. `MobileContext` caches it, because each bridge call costs a round trip.
- Clear the scope between requests. NativePHP mobile keeps one PHP process alive (`Native\Mobile\Runtime`), so breadcrumbs and users leak into the next request without `Runtime::onReset()`. Keep the hook in `MobileServiceProvider::bootPlatform()`.
- Send the device name and the device ID only when `send_default_pii` is `true`.
- Treat the release `DEBUG` (the default version of a development build) as no release.
- Do not import a class of `nativephp/mobile` outside a `class_exists()` check. Declare each bridge symbol that Larastan needs in `stubs/nativephp.php`.

---

## 2. Native crashes

A crashing process can only write a file. Sending happens on the next launch, in PHP:

1. NativePHP calls the `init_function` of each platform at launch: `NativeWatchCrashReporter.install()` (Swift) and `installNativeWatchCrashReporter(context)` (Kotlin). Keep both idempotent.
2. On a crash, native code writes one JSON report in the shape of the ingest API to `nativewatch/crashes/<event_id>.json`: Application Support on iOS, `filesDir` on Android. Keep at most 20 reports.
3. After the first response of the next launch, `NativeCrashes::collect()` reads the reports through `NativeWatch.PendingCrashReports`, passes each to `Client::captureEvent()`, and deletes them through `NativeWatch.DeleteCrashReports`.

Sources of crashes:

- iOS: Objective-C exceptions through `NSSetUncaughtExceptionHandler` (written at once, then the previous handler runs), and hard crashes (bad memory access, Swift traps) through MetricKit, which iOS delivers on the next launch. MetricKit does not deliver on the simulator. Do not add signal handlers: they are not safe and they fight other crash reporters.
- Android: uncaught JVM exceptions through `Thread.setDefaultUncaughtExceptionHandler` (written at once, then the previous handler runs), and ANRs and native (NDK) crashes through `ApplicationExitInfo` (Android 11 and later) on the next launch.

Rules:

- Keep native frames stable between launches of one build. Use binary offsets, never absolute addresses: ASLR changes them on each launch and splits every crash into its own issue.
- Mark a frame `in_app` when it belongs to the app: the executable of the bundle on iOS, the package name of the app on Android.
- Add a bridge function to `nativephp.json`, Swift and Kotlin together. Give each Android bridge class `"android_params": ["context"]`.
- Never let crash reporting throw. Wrap native file work in `try?` (Swift) or `try` (Kotlin), and always run the previous handler.
- Type-check Swift before you finish: `xcrun --sdk iphonesimulator swiftc -typecheck -target arm64-apple-ios18.2-simulator <stub> resources/ios/Sources/*.swift`, with a stub file that declares `BridgeFunction` and `BridgeResponse` the way `vendor/nativephp/mobile/resources/xcode/NativePHP/Bridge/BridgeRouter.swift` does.
- Validate the plugin from `examples/mobile`: `php artisan native:plugin:validate vendor/zinxan/native-mobile`.
- Next: upload dSYM files and ProGuard mappings at build time (a `post_build` hook) so the server can symbolicate native frames.

---

## 3. Tests

- Put the tests in `packages/native-mobile/tests/`. They run in the root suite.
- Use `tests/Fixtures/bridge.php` for a fake `nativephp_call()` and `tests/Fixtures/Runtime.php` for a fake runtime. Put pending crash reports in `$GLOBALS['nativewatch_bridge']['reports']` and read the calls of PHP from `$GLOBALS['nativewatch_bridge']['calls']`. Do not install `nativephp/mobile` in the root app.
- Remember that the fake `nativephp_call()` exists for the whole test process once a mobile test file loads. Do not write a test that needs the function to be missing.
- Try the SDK on a simulator with `examples/mobile`.
