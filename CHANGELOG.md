# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

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
