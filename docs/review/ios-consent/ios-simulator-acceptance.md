# JabKeeper Release Simulator acceptance

Verified on 2 November 2026 using the actual JabKeeper app, compiled with Xcode's
**Release** configuration, and mobile SDK runtime candidate
`c47acd82e7649556a186531d16424a9f1ae55410`. The app ran locally in **iOS Simulator**;
its receiver was NativeWatch production. This is not an App Store publication
or a physical-iPhone test. Version 0.2.1 remains a release target until its tag
and Composer package are published.

Tests used disposable app data. The native Settings toggle, normal Weight tab
and actual process crash/relaunch exercised the app, rather than submitting
handwritten events or inserting dashboard rows.

| Check | Actual result |
| --- | --- |
| Diagnostics off, then the normal Weight screen encounters a missing SQLite table | The original QueryException error screen remained visible. No SDK outbox or production issue-count growth was observed. |
| Diagnostics on, then the same normal Weight-screen failure | Production issue **49** grew from one to two occurrences. New event: `ab99bdbb-b1d4-43c2-afb4-1f7ecf522ea2`. |
| Successful SQLite query delayed by real database contention | The Queries page received the actual **11,203 ms** query activity. This was not a manually fabricated duration. |
| Release process receives SIGABRT, then the app is relaunched | The native crash was ingested as production issue **52**, event `eec447ce-a8b5-452d-8ad7-7c045a2c4044`. |

The native UI adapter therefore has real Release Simulator evidence for the
missing component-error reporting path, consent gating, a query activity and
next-launch native crash ingestion. The PHP-only adapter does not change the
Swift native recorder.

## Original screenshots

The files are copied unchanged from the actual Simulator captures. See
[SHA-256 checksums](screenshot-checksums.json). The Settings captures show their
current scroll position; they do not show the entire privacy disclosure at once.
Screenshots show UI state, while the collector read-back supplies the event and
count evidence above.

- [Diagnostics off](screenshots/release-consent-off.png)
- [Real Weight-screen error while sharing is off](screenshots/release-denied-error.png)
- [Diagnostics on](screenshots/release-consent-on.png)
- [Real Weight-screen error while sharing is on](screenshots/release-allowed-error.png)

![The real JabKeeper Settings diagnostics choice enabled](screenshots/release-consent-on.png)

## Remaining limits

The exact dSYM upload was indexed, but the production symbolication job failed
during this run and remains under investigation. Native crash capture and
ingestion are verified; successful native function/file/line symbolication is
not established by this record.

Physical-device flight mode, MetricKit delivery and Android behavior were not
tested in this acceptance run. The offline/retry cases in the library suite use
controlled HTTP failures; they are not evidence of a physical flight-mode test.
Do not describe these results as covering every activity category or a complete
platform release checklist.
