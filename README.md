# rasuvaeff/yii3-filestorage-web

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/yii3-filestorage-web/v)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-web)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-filestorage-web/downloads)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-web)
[![Build](https://github.com/rasuvaeff/yii3-filestorage-web/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-web/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-filestorage-web/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-web/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/yii3-filestorage-web/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-filestorage-web/php)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-web)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[Русская версия](README.ru.md)

The HTTP half of [`rasuvaeff/yii3-filestorage`](https://github.com/rasuvaeff/yii3-filestorage):
signed download URLs for files an object store cannot hand out itself, and an
action that serves them without any of the ways this usually goes wrong.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.
> Projects using the [llm/skills](https://github.com/roxblnfk/skills) Composer plugin also get this package's agent skill synced into `.agents/skills/` automatically on install.

**Status: `0.x`.**

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-filestorage` ^0.1, plus a store backend
- `rasuvaeff/yii3-filestorage-db` — for `ScopedFileResolverInterface`, which
  this package needs and does not provide
- `yiisoft/http` ^1.2, a PSR-17 implementation and a PSR-20 clock

## Installation

```bash
composer require rasuvaeff/yii3-filestorage-web
```

```php
// config/common/params.php
return [
    'rasuvaeff/yii3-filestorage-web' => [
        'route' => '/files/{token}',
        'tokenAttribute' => 'token',
        'cacheControl' => 'private, max-age=3600',
        'signingKeys' => [
            'active' => '2026-08',
            'keys' => ['2026-08' => $_ENV['FILESTORAGE_SIGNING_KEY']],
        ],
        'extraActiveMediaTypes' => [],
    ],
];
```

```php
// config/common/routes.php
use Rasuvaeff\Yii3FilestorageWeb\Action\FileDownloadAction;
use Yiisoft\Router\Route;

return [
    Route::get('/files/{token}')->action(FileDownloadAction::class)->name('file/download'),
];
```

Keep the route and the `route` param in step — one is what the router matches,
the other is what goes into every URL. Generate a key with
`php -r "echo bin2hex(random_bytes(32));"`; anything under 32 bytes is refused.

That is all the wiring. `Storage::urlFor()` starts returning signed URLs for
private stores the moment this package is installed, because core declares
`ProxyUrlGeneratorInterface` optional and leaves it unbound for exactly this.

## What the action guarantees

| Rule | Why |
|---|---|
| **Everything that fails is a 404** — bad signature, expired token, wrong scope, missing bytes | A 403 confirms the id exists. An opaque token exists so that it cannot |
| **The scope comes from the token, never the request** | A signed URL is served without a session on purpose. The tenant travels inside the HMAC and is matched as a second predicate, so a leaked id resolves to nothing in another tenant |
| **Conditional requests are answered before the store is opened** | A `304` costs one metadata read. It also means a client with a current copy still gets `304` when the object itself has gone |
| **Active content is an attachment whatever the policy says** | HTML, SVG, XML and friends served inline from your origin are stored XSS. `nosniff` stops a browser from finding one where the media type says there is none |
| **The media type is normalized once, before anything reads it** | CR, LF and NUL are stripped before the type is matched against the active list, put in the validator and written to `Content-Type`. Deciding on the raw value and cleaning only at the header is how a stored `text/ht\r\nml` misses the list and still arrives as `text/html`, inline |
| **A token minted for a variant is not served the original** | The variant is inside the signature so a thumbnail URL cannot be replayed for the full-resolution file |

## Ranges

Advertised and served when the range can actually be cheap, which is a question
about the *store*:

| Store | Result |
|---|---|
| Implements `RangeReadableStoreInterface` | The store is asked for the window — one ranged read |
| Does not, but the body is seekable and reports its size (a local file) | Windowed with a seek |
| Neither: no range primitive, and a body that is forward-only or of unknown size | No `Accept-Ranges`, and a `Range` request gets a correct full `200` |

The last row is what an object store's `readStream()` gives today, so that is
where an S3 or Flysystem-backed group lands — but it is the two capabilities
that are checked, never the kind of store.

Single ranges only: `206`, `Content-Range`, `Content-Length`, and `416` with
`bytes */size` for a request past the end. A multi-range request gets the whole
representation, which RFC 9110 permits. `If-Range` is honoured, so a resumed
download of a file that changed restarts instead of splicing two files together.

For S3 the better answer is a presigned URL: S3 serves ranges on it natively.
The delivery policy decides — `urlFor()` returns the presigned URL when the
policy allows one *and* the store can encode that policy into it (the
disposition and content type it demands); otherwise the signed proxy URL this
package mints stands in.

> **Why not `yiisoft/response-download`?** It was evaluated. Its master branch
> covers most of the response half, including ranges — but none of that is in
> the released `1.1.0`, whose `sendStreamAsFile()` produces two headers and a
> body. Writing it here also lets the range come from the *store*, which a
> factory handed only a stream cannot do. Worth revisiting when it tags a
> release with `processRange()`.

## Key rotation

The key id is part of the signed envelope, so rotation does not break URLs
already in inboxes:

```php
'signingKeys' => [
    'active' => '2026-09',                    // signs from now on
    'keys' => [
        '2026-09' => $_ENV['FILESTORAGE_SIGNING_KEY'],
        '2026-08' => $_ENV['FILESTORAGE_SIGNING_KEY_PREVIOUS'],  // still verifies
    ],
],
```

Keep the old key for at least the longest TTL you mint, then drop it.

## Examples

Runnable and self-contained — see [`examples/`](examples/). No server needed.

## Development

No PHP or Composer on the host; everything runs in Docker.

```bash
make build
make cs-fix
make mutation
make release-check
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
