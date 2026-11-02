# Consent example verification

Verified on 2 November 2026 against NativePHP Mobile 4.5, `zinxan/native-mobile`
0.2.0 and `zinxan/native-core` 0.2.1, installed in JabKeeper's existing app.

The copied action and native component were loaded directly from this repository.
Its example Blade view was registered as a Laravel view location. The example
Pest tests ran through NativePHP's real `Native::test()` renderer and toggle-event
handling, with the real final NativeWatch `Client` and a controlled
`ReportingConsent` interface. HTTP stray requests were prevented; nothing was
sent to a production collector by these tests.

Four tests passed with 38 assertions: initially off with an accessible control,
successful opt-in and read-back, withdrawal, and failed persistence showing an
accessible error while restoring the actual denied state. Both PHP example
source files passed `php -l`; `git diff --check` passed.

This establishes the example's PHP and NativePHP 4 component behavior. It does
not establish compilation, native crash capture, physical-device delivery or
App Review approval. The guide explicitly requires the real iOS verification
procedure for those boundaries.

The installed plugin's existing native consent implementation and bridge
manifest were inspected. No new runtime API or fallback was needed. The guide
uses the existing public facade and documents archive/privacy declarations as
app responsibilities rather than claiming the plugin configures them.
