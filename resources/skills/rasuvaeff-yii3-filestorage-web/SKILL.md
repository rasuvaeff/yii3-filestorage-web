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

3. **Answer conditional requests before opening the store.** A 304 must not
   depend on the object still being there.

4. **Force attachment for active content.** HTML, SVG, XML and friends served
   inline from your own origin are stored XSS. The delivery policy chooses
   between download and display, not between safe and unsafe. Always add
   `nosniff`.

5. **Refuse a token carrying a `variant`** until the images package exists.
   Serving the original instead defeats the reason the variant is signed.

6. **Advertise ranges only where they are cheap.** Ask
   `RangeReadableStoreInterface` first, use a seekable sized stream second, and
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
- The `route` param and the registered route must agree — one is matched, the
  other is minted into every URL.
- Key rotation works because the key id is inside the signed envelope. Keep a
  retired key in `keys` for at least the longest TTL you mint.
- `yiisoft/response-download` was evaluated and not used: its Range support is
  unreleased, and a factory handed only a stream cannot ask the store for a
  window. Revisit when it tags a release with `processRange()`.
