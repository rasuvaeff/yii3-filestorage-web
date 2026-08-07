# AGENTS.md — yii3-filestorage-web

Guidance for AI agents working on this package. Read before changing code.

## What this is

The HTTP half of `rasuvaeff/yii3-filestorage`: signed URLs for files a store
cannot hand out itself, and the action that serves them. Namespace
`Rasuvaeff\Yii3FilestorageWeb`.

Public API: `Action\FileDownloadAction`, `Url\HmacProxyUrlGenerator`,
`Http\FileResponseFactory`, `Http\ByteRange`, `ActiveMediaTypes`.

DI wiring: `config/di.php` binds `SigningKeyRing`, `UrlSignerInterface`,
`ProxyUrlGeneratorInterface`, `FileResponseFactory`, `ActiveMediaTypes` and
`FileDownloadAction`. It must **not** bind `StorageInterface` (core's),
`RepositoryInterface` (`-db`'s) or `StoreInterface` (a store backend's) —
`yiisoft/config` allows one vendor package per key.

`ScopedFileResolverInterface` comes from `-db`. This package cannot resolve a
signed download without it, deliberately: the alternative is a lookup by id
with no scope.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `make build`.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Everything that fails answers 404, and resolution is always scoped.** A
   403 on a wrong scope, or a 401 on a bad signature, tells an attacker the id
   exists — which is the one fact an opaque token is meant to withhold. And a
   lookup by id alone reads any file whose id leaks, which is why
   `ScopedFileResolverInterface` is the only way in.
4. **Preserve the public contract.** Update `README.md` **and `README.ru.md`**,
   `llms.txt` and the tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
make build
make cs-fix
make psalm
make test
make mutation
make release-check
```

`composer.lock` is gitignored (library).

## Before the first release

Two development-only things must go, together, when
`rasuvaeff/yii3-filestorage` is published and tagged:

- the `repositories` block in `composer.json` (a path repository pointing at
  `../yii3-filestorage`, with a pinned `options.versions`);
- the monorepo-root mount in the `Makefile`'s `DOCKER` variable.

**Do not push this repository to GitHub before core is on Packagist.** A CI
checkout has no sibling directory and no registry copy, so `composer install`
cannot resolve `rasuvaeff/yii3-filestorage` in any job. See
`docs/evolved-rules.md` ER-019.

## Mutation testing

`minMsi` is **86, and no mutator is ignored.** The 24 survivors are four
groups:

| Group | Example | Why no test kills it |
|---|---|---|
| Concat permutations in the ETag fallback | swapping `$file->id` and `'\|'` | The fallback only has to be *injective* — different files, different validator. A permutation of the same three components still is. The one property that matters, that the separators stop `id="a", size=11` colliding with `id="a1", size=1`, has a test |
| Clamps on values their source cannot produce | `max(0, …)` and `min(…, $size - 1)` in `ByteRange` after the bounds are already checked | Defence against an input the parser has already rejected |
| Trims a callee repeats | `trim()` on the `Range` header, which `ByteRange::parse()` trims again | Removing one is unobservable. It stays because each function should be correct on its own input |
| Guards the type system already makes true | `isset(…) && \is_string(…)` on a request attribute | Written this way so psalm narrows without a `@var` tag that rector then deletes as redundant |

## Invariants & gotchas

- **`DATE_RFC7231` is deprecated in PHP 8.5** for ignoring the timezone it is
  given, and this package supports 8.5. The IMF-fixdate format is spelled out
  as `D, d M Y H:i:s \G\M\T` in `FileDownloadAction::HTTP_DATE`. Do not
  "simplify" it back to the constant — it emits a deprecation on every request.
- **Conditional GET runs before the store is opened**, and a test proves it by
  getting a `304` for a file whose object has been removed. Moving the check
  after the read would still pass a naive test and would cost a read per
  request.
- **`If-None-Match` wins over `If-Modified-Since`** when both are present (RFC
  9110), and `W/` prefixes compare equal — a weak validator is the right kind
  for a conditional GET.
- **`If-Range` mismatch means send the whole file.** Resuming a download across
  a change splices two different files into one.
- **Ranges follow the store, not the stream.** `RangeReadableStoreInterface`
  first, a seekable sized body second, otherwise no `Accept-Ranges`. Never
  window a forward-only body by reading and discarding the prefix: that turns
  "seek to the last minute" into a full download while advertising the
  opposite.
- **`ActiveMediaTypes` overrides the delivery policy, not the other way round.**
  A group configured for inline images must not become an XSS vector the day
  somebody uploads an SVG to it. SVG is the one people forget.
- **`yiisoft/response-download` is not a dependency, and the reason is
  versioned.** Its master branch has the whole response half including ranges;
  the released `1.1.0` has none of it. Evaluate against `vendor/`, not against
  a clone of master — that is how this nearly went in. Revisit if it tags a
  release with `processRange()`; `xSendFile()` would come with it.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types, named arguments, trailing commas.
- Every validation regex ends with `\z`, never `$` (`docs/evolved-rules.md`
  ER-001).
- `config/di.php` is covered by neither cs, nor psalm, nor `src`-scoped tests.
  `ConfigWiringTest` exercises it through a real container instead.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` references a 40-char commit
  SHA with a `# vN` trailing comment; never revert to floating `@vN` tags.
  Workflows carry `permissions: { contents: read }` and
  `persist-credentials: false` on every checkout. Verify with
  `zizmor --persona=auditor .github/`.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit), plus
  `llms.txt`, `resources/skills/*/SKILL.md` and `examples/` if usage changed;
  update `CHANGELOG.md` when releasing.
- Re-run `make build`. Paste the output.
