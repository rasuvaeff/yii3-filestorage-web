# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

Initial development. Not released.

- `Url\HmacProxyUrlGenerator`, binding the `ProxyUrlGeneratorInterface` core
  declares and leaves unbound: a signed, expiring URL for a store that cannot
  hand out one of its own, with the tenant scope stamped in at minting time.
- `Action\FileDownloadAction`: token verification, scope-matched resolution,
  conditional GET answered before the store is opened, delivery policy applied,
  and active content forced to an attachment with `nosniff`.
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
