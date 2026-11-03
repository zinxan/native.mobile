# Standalone SDK CI evidence

Verified on 3 November 2026 with PHP 8.4.27 and Laravel 13.

- `composer validate --strict`: passed.
- `composer audit`: no advisories or abandoned dependencies.
- `composer test`: Pint and PHPStan level 7 passed, 100% PHP type coverage,
  40 Pest tests and 115 assertions passed.
- `git diff --check`: passed.

The workflow repeats Pest across PHP 8.4/8.5 and Laravel 11/12/13. Its Quality
job validates Composer, audits dependencies and runs lint, static analysis and
type coverage. Every job runs for every pull request, without path filters.
Both Actions are pinned to full commit hashes; checkout credentials are not
persisted, the token is read-only and the workflow uses no repository secrets.

The public suite runs against Orchestra Testbench rather than the private
NativeWatch server. Server acceptance and end-to-end native crash behavior
still require their respective integration checks. This CI does not compile
or execute Swift, Kotlin, an iOS simulator or an Android device.

The first standalone run passed 39 tests and failed the subprocess compatibility
check because it loaded the private server bootstrap. It now starts a fresh
Testbench application in the subprocess and still checks that no NativePHP
runtime is installed. All 40 existing tests execute, without skips.
