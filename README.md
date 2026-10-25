# zxn/zx-mobile

Error tracking for NativePHP iOS and Android apps. Errors go to [ZXGuard](https://zxguard.app).

```bash
composer require zxn/zx-mobile
```

Add the key of your ZXGuard project to `.env`:

```env
ZXGUARD_KEY=zxg_...
ZXGUARD_URL=https://zxguard.app
```

Send a test error:

```bash
php artisan zxguard:test
```

Unhandled exceptions are reported from now on. Events wait in an outbox on the device and are sent after the response, so reporting never slows your app down and survives being offline.

Report something yourself:

```php
use ZXN\ZXGuard\Core\Laravel\ZXGuard;
use ZXN\ZXGuard\Core\Level;

ZXGuard::addBreadcrumb('sync', 'Started the sync');
ZXGuard::captureMessage('The disk is almost full', Level::Warning);
ZXGuard::captureException($exception);
```

Publish the config to change sampling, scrubbing or breadcrumbs:

```bash
php artisan vendor:publish --tag=zxguard-config
```
