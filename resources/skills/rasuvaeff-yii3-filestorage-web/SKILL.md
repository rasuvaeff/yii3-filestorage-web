---
name: rasuvaeff-yii3-filestorage-web
description: >-
  Signed download URLs and the download action for rasuvaeff/yii3-filestorage —
  HmacProxyUrlGenerator, FileDownloadAction with scope-matched resolution and
  conditional GET, capability-aware byte ranges, and the active-content list a
  delivery policy cannot override. Use when writing, reviewing or debugging file
  download endpoints, signed URLs or Range handling in a project that has this
  package installed.
---

# rasuvaeff/yii3-filestorage-web

Serving a stored file over a signed URL. Namespace
`Rasuvaeff\Yii3FilestorageWeb\`. Full API reference: `llms.txt`.

## Safety rules — verify these on every change

1. **Every failure answers 404.** Bad signature, expired token, wrong scope,
   missing bytes. A 403 or 401 confirms the id exists, and an opaque token
   exists precisely so that it cannot.

2. **Resolve by id *and* scope, never by id alone.** The scope comes from the
   verified token, not from the request — a signed URL is served without a
   session on purpose.

3. **Answer conditional requests before opening the store** — and before
   asking `DerivativeResolverInterface::stream()`, which may generate the
   rendition; a variant's validator comes from `descriptor()` alone. A 304 must
   not depend on the object still being there. `If-Modified-Since` confirms only
   an inline response: the ETag carries the disposition, a date does not, and a
   304 by date would keep a cached `inline` after the policy switched to
   attachment.

4. **Force attachment for active content.** HTML, SVG, XML, JavaScript, feeds
   and friends served inline from your own origin are stored XSS. Use core's
   configured `Mime\ActiveMediaTypes` (one instance for the family, from core's
   `activeMediaTypes.extra`/`allowInline` params), read through
   `DeliveryPolicyRegistry` — never a second list or param in this package,
   never a binding of `ActiveMediaTypes::class`. The delivery policy chooses
   between download and display, not between safe and unsafe. Always add
   `nosniff`. Normalize the media type once (CR, LF, NUL) *before* the
   active-type lookup, the validator and the header — cleaning only at the
   header lets `text/ht\r\nml` miss the list and still arrive as `text/html`.

5. **A `variant` token is served only through `DerivativeResolverInterface`**
   (bound by `yii3-filestorage-images`). Without a resolver, or for an unknown
   preset, it answers 404 — never the original, which would defeat the reason
   the variant is signed.

6. **Advertise ranges only where they are cheap.** Ask
   `RangeReadableStoreInterface` first, use a stream that is both seekable and
   of known size second, and
   otherwise send a full 200 with no `Accept-Ranges`. Never window a
   forward-only body by reading and discarding the prefix.

7. **No suppressions.** No `@psalm-suppress`, no baseline.

8. **Verification is mandatory.** `make build` before claiming done.

## Gotchas

- `DATE_RFC7231` is deprecated in PHP 8.5. The HTTP date format is spelled out
  as `D, d M Y H:i:s \G\M\T`.
- `If-None-Match` wins over `If-Modified-Since` when both are present (RFC
  9110), and `W/` prefixes compare equal.
- `If-Range` mismatch means send the whole file: resuming across a change
  splices two different files together.
- A link minted outside a request (queued email, job) gets the provider's
  scope at that moment — usually null, so a tenant's file answers 404. Use
  `HmacProxyUrlGenerator::forScope($tenantId)` (inject the class; it is bound
  with the configured route) or enter the tenant's context first. Facades
  (`Derivatives::urlFor()`, `Srcset`, core `Storage::urlFor()`) use the
  unpinned DI generator: `-images` 0.9+ has
  `$derivatives->withProxyUrls($urls->forScope($tenantId))`; core 0.10+ has
  `Storage::withProxyUrls()` (concrete class; `-db`'s `DeduplicatingStorage`
  0.4+ too — not on `StorageInterface`).
- Upload size refusals: catch core's `SizeLimitExceededException`, not a list
  of classes.
- The `route` param and the registered route must agree — one is matched, the
  other is minted into every URL.
- Key rotation works because the key id is inside the signed envelope. Keep a
  retired key in `keys` for at least the longest TTL you mint.
- `yiisoft/response-download` was evaluated and not used: its Range support is
  unreleased, and a factory handed only a stream cannot ask the store for a
  window. Revisit when it tags a release with `processRange()`.
