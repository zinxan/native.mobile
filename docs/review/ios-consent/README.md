# Consent example verification

Verified on 2 November 2026 against NativePHP Mobile 4.5 and `zinxan/native-core`
0.2.1, using this PR's mobile SDK source and JabKeeper's installed dependencies.

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
manifest were inspected. The consent example uses the existing public facade.
The guide documents archive/privacy declarations as app responsibilities rather
than claiming the plugin configures them.

The NativePHP 4.5 component runtime catches UI exceptions without Laravel
reporting and keeps its PHP request open. This PR adds the explicit
`ReportsNativeUiErrors` adapter and guarded screen lifecycle delivery hooks.
The complete mobile package suite passes 40 tests with 115 assertions, including
new parent-boundary tests for overlay preservation, original exception reporting,
same-object repaint deduplication, Laravel's do-not-report policy, declined
consent, offline retry on a later interaction, scope clearing, previous native
and PHP fatal collection before the next screen waits, SDK failure containment
and a separate process without NativePHP installed. HTTP is faked in these
library tests; they are not native crash acceptance evidence.

Two regression cases cover exceptions propagated by the UI and native dispatch
parents to the outer error loop. Both failed before the dispatch-order fix
because their queued event lost its breadcrumb. They now preserve the original
Throwable, breadcrumb and user scope until capture, clear scope afterwards,
and keep one queued event when the same overlay is repainted.

PHPStan level 7 passes with zero errors. Its analysis includes a typed native
parent host fixture so the opt-in trait is actually checked, without ignoring
unused-trait diagnostics. Pint, PHP syntax and diff checks pass.

The guide still requires real compiled iOS failure and delivery verification
before shipping. The separate [Release Simulator acceptance record](ios-simulator-acceptance.md)
contains actual JabKeeper checks, original screenshots and collector event IDs.
Those results do not follow from the unit tests above.
