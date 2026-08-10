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

## Mutation testing

`minMsi` is **93, and no mutator is ignored** — 208 of 223 mutants killed. The
15 survivors are five groups, and every one of them is an equivalent mutant:
there is no input for which the mutated program answers differently. Do not
chase them with tests that assert implementation.

| Group | Example | Why no test kills it |
|---|---|---|
| ETag permutations that stay injective | swapping the `inline`/`attachment` labels; moving a `'\|'` to the end | The validator only has to be *injective* — different files, different validator — and a bijective relabelling still is. The permutations that would break injectivity are killed: the separator between id and size, and the one before the timestamp, each have a collision test built from two real files |
| Clamps on values their source cannot produce | `max(0, …)` in `ByteRange` and in the size the response reports, after `$size === 0` and `$first >= $size` are already refused | Defence against an input the parser rejected upstream |
| Boundaries an earlier guard already settled | `$first >= $size` weakened to `>` | With `$size === 0` refused above, `$first === $size` falls through to `$last < $first` and returns the same `null` |
| Checks a callee repeats | `trim()` on `Range` (`ByteRange::parse()` trims again); `(int)` on a digits-only capture; `explode(';', …, 2)` raised to `3` | Unobservable from outside. They stay because each function should be correct on its own input. The three *other* trims — `If-None-Match`, `If-Modified-Since`, `If-Range` — are not repeated anywhere and are covered by a request double that does not trim |
| Early returns whose fall-through lands on the same answer | `if ($ifModifiedSince === '') { return false; }` | An empty date fails to parse and yields `false` two lines later; an empty `Range` reaches the factory, which treats `''` and `null` alike |

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
  first, a body that is both seekable and of known size second, otherwise no
  `Accept-Ranges`. Never
  window a forward-only body by reading and discarding the prefix: that turns
  "seek to the last minute" into a full download while advertising the
  opposite.
- **One normalizer, applied at every boundary that reads a stored media type.**
  `Http\MediaType::headerSafe()` strips CR, LF and NUL, and it is what the
  action calls before the `ActiveMediaTypes` lookup and the validator, and what
  `FileResponseFactory` calls before writing `Content-Type`. Cleaning *only*
  where the header is written left exactly one string in between:
  `text/ht\r\nml` misses the active-type lookup, is therefore ruled inline, and
  then reaches the client as `text/html`. The bug was two different treatments
  of one value, not two calls to one idempotent function — the factory is
  `@api` and is called directly, so dropping its call would move the hole into
  the public surface. Never add a *second* normalizer, and never let a caller
  hand the factory a media type separate from the one inside its
  `DeliveryOptions`: two arguments that can disagree is the same bug again.
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
  `ConfigWiringTest` exercises it through a real container instead. What no
  package-local test can see is the *other* three packages: run
  `bin/config-merge-harness @filestorage --with=yiisoft/cache:^3.2
  --with=yiisoft/db-sqlite:^2.0` from the monorepo root after touching
  `config/`.
- **This package declares no `params['yiisoft/yii-console']` key, and must not
  start.** It ships no commands. An empty `commands` array would still make a
  third vendor package claim that top-level key, and `yiisoft/config` only
  tolerates two because every Yii3 runner merges `params` recursively — spending
  that tolerance on a contribution worth nothing is how a family stops merging.
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
