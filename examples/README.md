# Examples

Runnable scripts. Each is self-contained: it wires the package by hand, so the
whole flow reads in one file without a framework or an HTTP server in the way.

```bash
composer install
php examples/signed-download.php
```

| Script | Shows | Needs a server? |
|---|---|---|
| [`signed-download.php`](signed-download.php) | Minting a signed URL and serving it: the whole round trip, plus a 304, a byte range, and the two ways a request gets refused | No |

The script uses `nyholm/psr7` and `yiisoft/test-support` because both are
development dependencies here. Any PSR-7/PSR-17 implementation and any PSR-20
clock work; the package names none.
