# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

- `Action\FileUploadAction`: the upload half M4 promised — one uploaded file
  per request through `Storage::add()`, with honest statuses (413/415/400/500)
  from the typed policy exceptions introduced in core 0.3.0. #12
- `Validation\FileRule` + `FileRuleHandler`: the same checks as a
  `yiisoft/validator` rule (new dependency), for form-level validation.
- Requires `rasuvaeff/yii3-filestorage: ^0.3` and `yiisoft/validator: ^2.1`.

## 0.3.0 — 2026-10-28

- Variant tokens are served: `FileDownloadAction` takes the core's
  `DerivativeResolverInterface` as an optional last constructor parameter
  (bound by installing `rasuvaeff/yii3-filestorage-images`) and resolves a
  rendition with its authoritative media type and extension. #9
- `Url\HmacProxyUrlGenerator::variantUrl()`: the signed proxy URL for a
  rendition — the variant is inside the payload's HMAC. #9
- `Http\FileResponseFactory::create()` accepts a nullable store: a rendition
  stream has no store range primitive, so ranges come from the body when it is
  seekable. #9

## 0.2.0 — 2026-10-17

- `FileDownloadAction` reads the download token from `yiisoft/router`'s
  `CurrentRoute` when the request attribute is absent. That router never
  copies its arguments into request attributes, so the route recipe in the
  README answered 404 to *every* download — valid token or not — in an
  application without a middleware that copies them. The attribute stays the
  first source and wins when both are present; `yiisoft/router` is a `suggest`
  (optional) dependency, and a container without it resolves the new nullable
  `currentRoute` parameter to null. Fixes #5.
- Wire `AddNameToLiteralArgumentRector` into `rector.php`. The rule shipped in
  `require-dev` from the first release but was never listed in the config, so it
  had never run. Enabling it names the `strict` flag of the `in_array()` call in
  `ActiveMediaTypes`. Internal only — no behaviour or public API change.

## 0.1.0 — 2026-08-10

First release. Tracks `rasuvaeff/yii3-filestorage` `0.x`: the API settles
together with core's while the family is built out. `ScopedFileResolverInterface`
comes from `rasuvaeff/yii3-filestorage-db`, which this package needs and does
not provide.

- `Url\HmacProxyUrlGenerator`, binding the `ProxyUrlGeneratorInterface` core
  declares and leaves unbound: a signed, expiring URL for a store that cannot
  hand out one of its own, with the tenant scope stamped in at minting time.
- `Action\FileDownloadAction`: token verification, scope-matched resolution,
  conditional GET answered before the store is opened, delivery policy applied,
  and active content forced to an attachment with `nosniff`. The media type is
  normalized once — `Http\MediaType` — before the active-content lookup, the
  validator and the header, so a stored `text/ht\r\nml` cannot miss the list and
  still reach the client as `text/html`, inline.
- `Http\FileResponseFactory` and `Http\ByteRange`: single byte ranges, `206`,
  `416`, `Accept-Ranges`, taking the window from the *store* when it has a
  range primitive and from the stream when it is seekable.
- `ActiveMediaTypes`: the list a delivery policy is not allowed to override.
- Dropped the empty `params['yiisoft/yii-console']['commands']` this package
  declared. It shipped no commands, so the key bought nothing and cost a third
  vendor package claiming a top-level `params` key that `yiisoft/config` accepts
  only because a Yii3 runner merges `params` recursively. Found by the
  four-package merge harness (`bin/config-merge-harness` in the monorepo), which
  reports the duplicate the moment that recursion is not there.
