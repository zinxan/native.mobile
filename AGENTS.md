# zxn/zx-mobile

`zx-mobile` reports the errors of NativePHP iOS and Android apps, including SuperNative screens. It holds two classes: `MobileServiceProvider` hooks `zx-core` into the app and the persistent runtime, and `MobileContext` reads the device through the native bridge. Everything else comes from `zx-core`. Read `packages/zx-core/AGENTS.md` first.

---

## 1. Rules

- Put a feature that desktop apps also need in `zx-core`, not here.
- Call the bridge only through `nativephp_call()`, behind `function_exists('nativephp_call')`. Outside a device (tests, the web preview) the function does not exist, and the SDK reports the platform `mobile`.
- Read the device once per process. `MobileContext` caches it, because each bridge call costs a round trip.
- Clear the scope between requests. NativePHP mobile keeps one PHP process alive (`Native\Mobile\Runtime`), so breadcrumbs and users leak into the next request without `Runtime::onReset()`. Keep the hook in `MobileServiceProvider::bootPlatform()`.
- Send the device name and the device ID only when `send_default_pii` is `true`.
- Treat the release `DEBUG` (the default version of a development build) as no release.
- Do not import a class of `nativephp/mobile` outside a `class_exists()` check. Declare each bridge symbol that Larastan needs in `stubs/nativephp.php`.

---

## 2. Native crash capture (next)

A PHP SDK cannot see a crash in Swift or Kotlin. To capture one, turn this package into a NativePHP plugin:

1. Set `"type": "nativephp-plugin"` in `composer.json` and add `nativephp.json` with the namespace `ZXGuard`.
2. Add Swift code in `resources/ios/Sources/` and Kotlin code in `resources/android/src/` that writes each crash report to the outbox directory of `zx-core`.
3. Let `zx-core` send the reports on the next start, the same way it sends PHP events.
4. Validate the plugin with `php artisan native:plugin:validate` in `examples/mobile`.

---

## 3. Tests

- Put the tests in `packages/zx-mobile/tests/`. They run in the root suite.
- Use `tests/Fixtures/bridge.php` for a fake `nativephp_call()` and `tests/Fixtures/Runtime.php` for a fake runtime. Do not install `nativephp/mobile` in the root app.
- Try the SDK on a simulator with `examples/mobile`.
