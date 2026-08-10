<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Action;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3Filestorage\Url\SignedPayload;
use Rasuvaeff\Yii3FilestorageWeb\Action\FileDownloadAction;
use Rasuvaeff\Yii3FilestorageWeb\ActiveMediaTypes;
use Rasuvaeff\Yii3FilestorageWeb\Http\FileResponseFactory;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\FixedPath;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\InMemoryResolver;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\ThrowingResolver;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\UntrimmedRequest;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(FileDownloadAction::class)]
final class FileDownloadActionTest
{
    private InMemoryResolver $files;
    private InMemoryStore $store;
    private FileDownloadAction $action;

    #[BeforeTest]
    public function setUp(): void
    {
        $factory = Fixtures::factory();
        $this->files = new InMemoryResolver();
        // testo reuses the test instance across methods; the store is fresh
        // each time, so the flag tracking whether it holds the object must be
        $this->stored = false;
        $this->store = new InMemoryStore('memory', $factory, new StaticClock(Fixtures::now()));

        $this->action = new FileDownloadAction(
            signer: Fixtures::signer(),
            files: $this->files,
            stores: new StoreRegistry([$this->store]),
            deliveryPolicies: new DeliveryPolicyRegistry(),
            downloads: new FileResponseFactory($factory, $factory),
            responses: $factory,
            activeMediaTypes: new ActiveMediaTypes(),
        );
    }

    public function servesAFileForAValidToken(): void
    {
        $response = $this->action->handle(Fixtures::request($this->tokenFor('file-1')));

        Assert::same($response->getStatusCode(), 200);
        Assert::same((string) $response->getBody(), 'hello world');
        Assert::same($response->getHeaderLine('Content-Type'), 'text/plain');
        Assert::string($response->getHeaderLine('Content-Disposition'))->contains('attachment');
        Assert::same($response->getHeaderLine('X-Content-Type-Options'), 'nosniff');
        Assert::same($response->getHeaderLine('Cache-Control'), 'private, max-age=3600');
    }

    /**
     * RFC 6266: a quoted ASCII fallback plus `filename*` for the real name.
     * Delegated to `yiisoft/http`, asserted here because it is a contract this
     * package promises rather than an implementation detail.
     */
    public function theFilenameSurvivesInBothForms(): void
    {
        $response = $this->action->handle(Fixtures::request($this->tokenFor('file-1')));
        $disposition = $response->getHeaderLine('Content-Disposition');

        Assert::string($disposition)->contains('filename="report notes.txt"');
        Assert::string($disposition)->contains("filename*=utf-8''report%20notes.txt");
    }

    /**
     * Everything that fails is a 404. A 403 on a bad scope, or a 401 on a bad
     * signature, confirms the id exists — which is the one thing an opaque
     * token is for.
     */
    #[DataProvider('refusedProvider')]
    public function everythingThatFailsLooksTheSame(string $token): void
    {
        $response = $this->action->handle(Fixtures::request($token));

        Assert::same($response->getStatusCode(), 404);
        Assert::same((string) $response->getBody(), '');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedProvider(): iterable
    {
        yield 'no token at all' => [''];
        yield 'not a token' => ['nonsense'];
        yield 'a tampered signature' => ['v1.k1.99999999999.eyJmaWxlSWQiOiJmaWxlLTEifQ.AAAA'];
    }

    public function anExpiredTokenIsRefused(): void
    {
        $token = Fixtures::signer()->sign(
            new SignedPayload(fileId: 'file-1'),
            Fixtures::now()->modify('-1 second'),
        );

        Assert::same($this->action->handle(Fixtures::request($token))->getStatusCode(), 404);
    }

    /**
     * The scope rides inside the HMAC, so a token minted for one tenant cannot
     * resolve another's file even though the id is right.
     */
    public function aTokenFromAnotherScopeResolvesToNothing(): void
    {
        $this->files->add(Fixtures::file('scoped'), 'tenant-a');
        $token = Fixtures::signer()->sign(
            new SignedPayload(fileId: 'scoped', scopeId: 'tenant-b'),
            Fixtures::now()->modify('+1 hour'),
        );

        Assert::same($this->action->handle(Fixtures::request($token))->getStatusCode(), 404);
    }

    public function aTokenForItsOwnScopeResolves(): void
    {
        $this->store->write(
            Upload::fromStream(Fixtures::factory()->createStream('hello world'), 'a.txt', Fixtures::factory()),
            'common',
            new FixedPath('common/ab/cd/key/original.txt'),
        );
        $this->files->add(Fixtures::file('scoped'), 'tenant-a');
        $token = Fixtures::signer()->sign(
            new SignedPayload(fileId: 'scoped', scopeId: 'tenant-a'),
            Fixtures::now()->modify('+1 hour'),
        );

        Assert::same($this->action->handle(Fixtures::request($token))->getStatusCode(), 200);
    }

    /**
     * A variant is a rendition the images package will produce. Until then a
     * token asking for one must not quietly get the original — which is the
     * whole reason the variant is inside the signature.
     */
    public function aTokenForAVariantIsNotServedTheOriginal(): void
    {
        $token = Fixtures::signer()->sign(
            new SignedPayload(fileId: 'file-1', variant: 'thumb'),
            Fixtures::now()->modify('+1 hour'),
        );

        Assert::same($this->action->handle(Fixtures::request($token))->getStatusCode(), 404);
    }

    /**
     * A row whose bytes are gone is a 404, not a 500: there is nothing to
     * serve and nothing the client can do about it.
     */
    public function aRowWithoutBytesIsNotFound(): void
    {
        $this->files->add(Fixtures::file('orphan'));

        $token = Fixtures::signer()->sign(
            new SignedPayload(fileId: 'orphan'),
            Fixtures::now()->modify('+1 hour'),
        );

        Assert::same($this->action->handle(Fixtures::request($token))->getStatusCode(), 404);
    }

    public function aMatchingEtagAnswers304(): void
    {
        $first = $this->action->handle(Fixtures::request($this->tokenFor('file-1')));
        $etag = $first->getHeaderLine('ETag');
        Assert::true($etag !== '');

        $response = $this->action->handle(
            Fixtures::request($this->tokenFor('file-1'), ['If-None-Match' => $etag]),
        );

        Assert::same($response->getStatusCode(), 304);
        Assert::same((string) $response->getBody(), '');
        Assert::same($response->getHeaderLine('ETag'), $etag);
        Assert::same($response->getHeaderLine('Cache-Control'), 'private, max-age=3600');
    }

    /**
     * The conditional check runs before the store is touched, and the proof is
     * that a row whose bytes are gone — a 404 on a plain request — still gets a
     * 304 when the client says it already has them.
     */
    public function the304IsAnsweredWithoutOpeningTheStore(): void
    {
        $this->files->add(Fixtures::file('bodiless'));
        $token = Fixtures::signer()->sign(
            new SignedPayload(fileId: 'bodiless'),
            Fixtures::now()->modify('+1 hour'),
        );
        $etag = $this->action->handle(Fixtures::request($token))->getHeaderLine('ETag');

        // no bytes: a plain request cannot be served
        Assert::same($this->action->handle(Fixtures::request($token))->getStatusCode(), 404);
        Assert::true($etag === '');

        $stored = $this->action->handle(Fixtures::request($this->tokenFor('file-1')));
        $response = $this->action->handle(Fixtures::request(
            $this->tokenFor('file-1'),
            ['If-None-Match' => $stored->getHeaderLine('ETag')],
        ));
        $this->store->clear();

        Assert::same(
            $this->action->handle(Fixtures::request(
                $this->tokenFor('file-1'),
                ['If-None-Match' => $stored->getHeaderLine('ETag')],
            ))->getStatusCode(),
            304,
            'the conditional answer must not depend on the object still existing',
        );
        Assert::same($response->getStatusCode(), 304);
    }

    public function aWeakEtagStillMatches(): void
    {
        $this->files->add(Fixtures::file('hashed', contentHash: str_repeat('a', 64)));
        $etag = $this->action->handle(Fixtures::request($this->tokenFor('hashed')))->getHeaderLine('ETag');

        $response = $this->action->handle(
            Fixtures::request($this->tokenFor('hashed'), ['If-None-Match' => 'W/' . $etag]),
        );

        Assert::same($response->getStatusCode(), 304);
    }

    public function aStaleEtagGetsTheFile(): void
    {
        $response = $this->action->handle(
            Fixtures::request($this->tokenFor('file-1'), ['If-None-Match' => '"something-else"']),
        );

        Assert::same($response->getStatusCode(), 200);
    }

    public function ifModifiedSinceIsHonoured(): void
    {
        $response = $this->action->handle(Fixtures::request($this->tokenFor('file-1'), [
            'If-Modified-Since' => Fixtures::now()->format('D, d M Y H:i:s \G\M\T'),
        ]));

        Assert::same($response->getStatusCode(), 304);
    }

    /**
     * RFC 9110: a request carrying `If-None-Match` makes `If-Modified-Since`
     * irrelevant, even when the date alone would have produced a 304.
     */
    public function ifNoneMatchOverridesIfModifiedSince(): void
    {
        $response = $this->action->handle(Fixtures::request($this->tokenFor('file-1'), [
            'If-None-Match' => '"stale"',
            'If-Modified-Since' => Fixtures::now()->format('D, d M Y H:i:s \G\M\T'),
        ]));

        Assert::same($response->getStatusCode(), 200);
    }

    public function anUnparsableIfModifiedSinceIsIgnored(): void
    {
        $response = $this->action->handle(
            Fixtures::request($this->tokenFor('file-1'), ['If-Modified-Since' => 'last thursday']),
        );

        Assert::same($response->getStatusCode(), 200);
    }

    /**
     * The ETag has to change when the bytes could have, or a client keeps a
     * stale copy forever.
     */
    public function theEtagTracksTheContent(): void
    {
        $this->files->add(Fixtures::file('a', contentHash: str_repeat('a', 64)));
        $this->files->add(Fixtures::file('b', contentHash: str_repeat('b', 64)));

        Assert::true(
            $this->etagOf('a') !== $this->etagOf('b'),
            'two different contents must not share a validator',
        );
    }

    /**
     * Without a hash the validator is built from id, size and `updatedAt`, and
     * *each* has to move it: a fallback that ignored one would hand a client a
     * stale copy of a file that changed in exactly that way.
     */
    public function withoutAContentHashEveryComponentMovesTheEtag(): void
    {
        $this->files->add(Fixtures::file('a', size: 10));
        $this->files->add(Fixtures::file('b', size: 10));
        $this->files->add(Fixtures::file('c', size: 20));
        $this->files->add(Fixtures::file('d', size: 10, updatedAt: Fixtures::now()->modify('+1 second')));

        // the id alone
        Assert::true($this->etagOf('a') !== $this->etagOf('b'), 'the id must move it');
        // the size alone
        Assert::true($this->etagOf('a') !== $this->etagOf('c'), 'the size must move it');
        // the timestamp alone
        Assert::true($this->etagOf('a') !== $this->etagOf('d'), 'updatedAt must move it');
    }

    /**
     * The separators are what make the fallback unambiguous. Without them
     * `id="a", size=11` and `id="a1", size=1` both concatenate to `a11` — two
     * different files sharing a validator, so one is served the other's cached
     * copy.
     */
    public function theFallbackSeparatorsPreventACollision(): void
    {
        $this->files->add(Fixtures::file('a', size: 11));
        $this->files->add(Fixtures::file('a1', size: 1));

        Assert::true($this->etagOf('a') !== $this->etagOf('a1'));
    }

    /**
     * An ETag is a quoted string in HTTP. An unquoted one is not a valid
     * entity-tag, and a client is entitled to ignore it.
     */
    /**
     * Weak when it is weak. The fallback triple does not prove two responses
     * are byte-identical — an in-place rewrite preserving updatedAt leaves it
     * unchanged — so marking it strong claimed a strength it does not have, and
     * If-Range would then splice a resumed suffix onto a stale prefix.
     */
    public function theFallbackEtagIsMarkedWeak(): void
    {
        Assert::same(preg_match('/^W\/"[0-9a-f]{32}"\z/', $this->etagOf('file-1')), 1);
    }

    public function aContentHashGivesAStrongEtag(): void
    {
        $this->files->add(Fixtures::file('hashed', contentHash: str_repeat('a', 64)));

        Assert::same(preg_match('/^"[0-9a-f]{32}"\z/', $this->etagOf('hashed')), 1);
    }

    /**
     * A content hash is used when there is one, so two files that differ only
     * in metadata still share a validator — and one that differs in content
     * does not.
     */
    public function aContentHashTakesPrecedenceOverTheFallback(): void
    {
        $hash = str_repeat('a', 64);
        $this->files->add(Fixtures::file('same-1', size: 10, contentHash: $hash));
        $this->files->add(Fixtures::file('same-2', size: 99, contentHash: $hash));

        Assert::same($this->etagOf('same-1'), $this->etagOf('same-2'));
    }

    /**
     * Header values arrive with the whitespace the client sent. A comparison
     * that did not trim would silently stop matching for those clients.
     */
    public function surroundingWhitespaceInConditionalHeadersIsTolerated(): void
    {
        $etag = $this->etagOf('file-1');

        Assert::same(
            $this->action->handle(Fixtures::request($this->tokenFor('file-1'), [
                'If-None-Match' => "  {$etag}  ",
            ]))->getStatusCode(),
            304,
        );
        Assert::same(
            $this->action->handle(Fixtures::request($this->tokenFor('file-1'), [
                'If-Modified-Since' => '  ' . Fixtures::now()->format('D, d M Y H:i:s \G\M\T') . '  ',
            ]))->getStatusCode(),
            304,
        );
    }

    public function oneMatchingEtagAmongSeveralIsEnough(): void
    {
        $etag = $this->etagOf('file-1');

        Assert::same(
            $this->action->handle(Fixtures::request($this->tokenFor('file-1'), [
                'If-None-Match' => '"other", ' . $etag,
            ]))->getStatusCode(),
            304,
        );
    }

    public function aStarMatchesAnything(): void
    {
        Assert::same(
            $this->action->handle(
                Fixtures::request($this->tokenFor('file-1'), ['If-None-Match' => '*']),
            )->getStatusCode(),
            304,
        );
    }

    /**
     * A resumed download of a file that changed must restart, or byte 40000 of
     * the new file gets spliced onto bytes 0-39999 of the old one.
     */
    public function anIfRangeMismatchDropsTheRange(): void
    {
        $response = $this->action->handle(Fixtures::request($this->tokenFor('file-1'), [
            'Range' => 'bytes=0-4',
            'If-Range' => '"a different file"',
        ]));

        Assert::same($response->getStatusCode(), 200);
        Assert::same((string) $response->getBody(), 'hello world');
    }

    public function amatchingIfRangeKeepsIt(): void
    {
        // A file with a content hash, because only a strong validator may
        // answer If-Range at all.
        $this->files->add(Fixtures::file('hashed', contentHash: str_repeat('a', 64)));
        $etag = $this->etagOf('hashed');

        $response = $this->action->handle(Fixtures::request($this->tokenFor('hashed'), [
            'Range' => 'bytes=0-4',
            'If-Range' => $etag,
        ]));

        Assert::same($response->getStatusCode(), 206);
        Assert::same((string) $response->getBody(), 'hello');
    }

    /**
     * A weak validator cannot answer If-Range, so the range is dropped and the
     * whole file is served — which is the safe outcome, not an error.
     */
    public function aweakEtagCannotAnswerIfRange(): void
    {
        $response = $this->action->handle(Fixtures::request($this->tokenFor('file-1'), [
            'Range' => 'bytes=0-4',
            'If-Range' => $this->etagOf('file-1'),
        ]));

        Assert::same($response->getStatusCode(), 200);
    }

    /**
     * The date form is what download managers and curl/wget resume flows send.
     * Treating it as a non-match turned every resume into a silent full
     * re-download.
     */
    public function anIfRangeDateThatMatchesKeepsTheRange(): void
    {
        $response = $this->action->handle(Fixtures::request($this->tokenFor('file-1'), [
            'Range' => 'bytes=0-4',
            'If-Range' => Fixtures::now()->setTimezone(new \DateTimeZone('GMT'))
                ->format('D, d M Y H:i:s \G\M\T'),
        ]));

        Assert::same($response->getStatusCode(), 206);
    }

    /**
     * An SVG is an image everywhere else and a scriptable document here.
     * Served inline from your own origin it is stored XSS, so the delivery
     * policy does not get to say otherwise.
     */
    #[DataProvider('activeContentProvider')]
    public function activeContentIsForcedToAttachment(string $mimeType): void
    {
        $action = $this->actionWithPolicy(new DeliveryPolicy(forceDownload: false));
        $this->files->add(Fixtures::file('active', mimeType: $mimeType));

        $response = $action->handle(Fixtures::request($this->tokenFor('active')));

        Assert::string($response->getHeaderLine('Content-Disposition'))->contains('attachment');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function activeContentProvider(): iterable
    {
        yield 'html' => ['text/html'];
        yield 'svg' => ['image/svg+xml'];
        yield 'xml' => ['application/xml'];
        yield 'html with a charset' => ['text/html; charset=utf-8'];
    }

    public function harmlessContentHonoursAnInlinePolicy(): void
    {
        $action = $this->actionWithPolicy(new DeliveryPolicy(forceDownload: false));
        $this->files->add(Fixtures::file('image', mimeType: 'image/png'));

        $response = $action->handle(Fixtures::request($this->tokenFor('image')));

        Assert::string($response->getHeaderLine('Content-Disposition'))->contains('inline');
    }

    /**
     * The router puts whatever matched into the attribute, and a route this
     * package does not own can put anything there at all. A non-string is not
     * a token — passing it to the signer would be a `TypeError` where the
     * contract promises a 404.
     */
    public function aTokenAttributeThatIsNotAStringIsRefused(): void
    {
        Assert::same(
            $this->action->handle(Fixtures::requestWithRawToken(['not', 'a', 'string']))->getStatusCode(),
            404,
        );
    }

    /**
     * Each component of the fallback validator has to move it *on its own*.
     * The id is in the validator too, so varying it alongside the component
     * under test would let an operand that stopped contributing pass: the same
     * row is rewritten in place instead.
     */
    public function eachFallbackComponentMovesTheEtagOnItsOwn(): void
    {
        $this->files->add(Fixtures::file('rewritten', size: 10));
        $original = $this->etagOf('rewritten');

        $this->files->add(Fixtures::file('rewritten', size: 20));
        Assert::true($original !== $this->etagOf('rewritten'), 'the size alone must move it');

        $this->files->add(Fixtures::file('rewritten', size: 10, updatedAt: Fixtures::now()->modify('+1 second')));
        Assert::true($original !== $this->etagOf('rewritten'), 'updatedAt alone must move it');

        $this->files->add(Fixtures::file('rewritten', mimeType: 'text/csv', size: 10));
        Assert::true($original !== $this->etagOf('rewritten'), 'the media type alone must move it');
    }

    /**
     * And it holds for an inline response, where the disposition is the shorter
     * of the two labels: a validator built by concatenation can lose the media
     * type on exactly one branch of that ternary.
     */
    public function theMediaTypeMovesTheValidatorForAnInlineResponseToo(): void
    {
        $action = $this->actionWithPolicy(new DeliveryPolicy(forceDownload: false));

        $this->files->add(Fixtures::file('inline-typed', mimeType: 'image/png'));
        $png = $action->handle(Fixtures::request($this->tokenFor('inline-typed')))->getHeaderLine('ETag');

        $this->files->add(Fixtures::file('inline-typed', mimeType: 'image/gif'));
        $gif = $action->handle(Fixtures::request($this->tokenFor('inline-typed')))->getHeaderLine('ETag');

        Assert::true($png !== $gif, 'an inline response must fold its media type in too');
    }

    /**
     * The separator before the timestamp does the same job as the one after the
     * id. `size=1` at `1767225600` and `size=11` at `767225600` — an archive
     * imported with its original mtime — run together into the same
     * `11767225600.000000` without it, and the two files then share a
     * validator.
     */
    public function theSeparatorBeforeTheTimestampPreventsACollision(): void
    {
        $this->files->add(Fixtures::file('collide', size: 1, updatedAt: new DateTimeImmutable('@1767225600')));
        $first = $this->etagOf('collide');

        $this->files->add(Fixtures::file(
            'collide',
            size: 11,
            updatedAt: new DateTimeImmutable('@767225600'),
            createdAt: new DateTimeImmutable('@767225600'),
        ));

        Assert::true($first !== $this->etagOf('collide'));
    }

    /**
     * Trimming is this class's own job, not the message layer's. Nyholm trims
     * on the way in; a server that hands the value through untouched must get
     * the same answers, or a conditional request silently stops matching for
     * whoever sent the whitespace.
     */
    public function conditionalHeadersAreTrimmedByTheActionItself(): void
    {
        $token = $this->tokenFor('file-1');
        $etag = $this->etagOf('file-1');
        $lastModified = Fixtures::now()->format('D, d M Y H:i:s \G\M\T');

        Assert::same(
            $this->action->handle($this->untrimmed($token, 'If-None-Match', "  {$etag}  "))->getStatusCode(),
            304,
            'a padded If-None-Match still matches',
        );

        Assert::same(
            $this->action->handle(
                $this->untrimmed($token, 'If-Modified-Since', "  {$lastModified}  "),
            )->getStatusCode(),
            304,
            'a padded If-Modified-Since is still a date',
        );

        // Whitespace is not a validator: an If-None-Match of nothing but spaces
        // must fall through to If-Modified-Since rather than count as a
        // mismatch that forces a 200.
        $blank = $this->untrimmed($token, 'If-None-Match', '   ')
            ->withHeader('If-Modified-Since', $lastModified);

        Assert::same($this->action->handle($blank)->getStatusCode(), 304);
    }

    /**
     * `If-Range` has to be trimmed for the same reason, and the consequence is
     * heavier: an untrimmed value does not start with `"`, so it is read as a
     * date, fails to parse, and the resume turns into a full re-download.
     */
    public function anIfRangeIsTrimmedBeforeItIsClassified(): void
    {
        $this->files->add(Fixtures::file('hashed', contentHash: str_repeat('a', 64)));
        $token = $this->tokenFor('hashed');
        $etag = $this->etagOf('hashed');

        $request = $this->untrimmed($token, 'If-Range', "  {$etag}  ")
            ->withHeader('Range', 'bytes=0-4');

        Assert::same($this->action->handle($request)->getStatusCode(), 206);
    }

    /**
     * A strong validator that is not ours is still not a match. Both halves of
     * the condition matter: the ETag has to be equal *and* strong, and a test
     * using the weak fallback cannot tell the two halves apart.
     */
    public function aStrongIfRangeFromAnotherFileDropsTheRange(): void
    {
        $this->files->add(Fixtures::file('hashed', contentHash: str_repeat('a', 64)));

        $response = $this->action->handle(Fixtures::request($this->tokenFor('hashed'), [
            'Range' => 'bytes=0-4',
            'If-Range' => '"0123456789abcdef0123456789abcdef"',
        ]));

        Assert::same($response->getStatusCode(), 200);
        Assert::same((string) $response->getBody(), 'hello world');
    }

    private function untrimmed(string $token, string $header, string $value): ServerRequestInterface
    {
        return (new UntrimmedRequest('GET', '/files/' . $token))
            ->withAttribute('token', $token)
            ->withUntrimmedHeader($header, $value);
    }

    /**
     * The interior CRLF is the one media type that used to reach the client as
     * active content served inline: the disposition was decided on the raw
     * value, which misses the active-type lookup, and the header was cleaned
     * only on the way out. Both ends read the same normalized value now.
     */
    public function aCrlfMediaTypeCannotSmuggleActiveContentInline(): void
    {
        $action = $this->actionWithPolicy(new DeliveryPolicy(forceDownload: false));
        $this->files->add(Fixtures::file('smuggled', mimeType: "text/ht\r\nml"));

        $response = $action->handle(Fixtures::request($this->tokenFor('smuggled')));

        Assert::same($response->getHeaderLine('Content-Type'), 'text/html');
        Assert::string($response->getHeaderLine('Content-Disposition'))->contains('attachment');
        Assert::same($response->getHeaderLine('X-Content-Type-Options'), 'nosniff');
    }

    /**
     * Three collaborators throw where the rest of the action returns null, and
     * the contract is that they answer the same 404 — a 500 on a download route
     * distinguishes "this id exists but something is wrong with it" from "no
     * such id", which is the fact an opaque token exists to withhold.
     */
    public function aRowNamingAnUnregisteredStoreIsNotFound(): void
    {
        // Configuration dropped the store; the rows keep the old name.
        $this->files->add(Fixtures::file('decommissioned', storeName: 'gone'));

        $response = $this->action->handle(Fixtures::request($this->tokenFor('decommissioned')));

        Assert::same($response->getStatusCode(), 404);
        Assert::same($response->getHeaderLine('Cache-Control'), 'no-store');

        // The store name is the only difference — otherwise this passes on any
        // other 404, a missing object above all.
        $this->files->add(Fixtures::file('decommissioned'));

        Assert::same(
            $this->action->handle(Fixtures::request($this->tokenFor('decommissioned')))->getStatusCode(),
            200,
        );
    }

    public function aResolverThatThrowsIsNotFound(): void
    {
        $factory = Fixtures::factory();
        $action = new FileDownloadAction(
            signer: Fixtures::signer(),
            files: new ThrowingResolver(),
            stores: new StoreRegistry([$this->store]),
            deliveryPolicies: new DeliveryPolicyRegistry(),
            downloads: new FileResponseFactory($factory, $factory),
            responses: $factory,
        );

        $response = $action->handle(Fixtures::request($this->tokenFor('file-1')));

        Assert::same($response->getStatusCode(), 404);
        Assert::same($response->getHeaderLine('Cache-Control'), 'no-store');
    }

    /**
     * Normalization strips what splits a header, not everything a strict PSR-7
     * implementation refuses. What is left throws from `withHeader()`, and that
     * is a 404 too.
     */
    public function aMediaTypeThePsr7ImplementationRefusesIsNotFound(): void
    {
        $this->files->add(Fixtures::file('control-char', mimeType: "text/\x01plain"));

        $response = $this->action->handle(Fixtures::request($this->tokenFor('control-char')));

        Assert::same($response->getStatusCode(), 404);
        Assert::same($response->getHeaderLine('Cache-Control'), 'no-store');

        // The media type is the only difference: the same row, the same bytes,
        // a type the implementation accepts. Without this the test would pass
        // just as well on a missing object, which is a different 404.
        $this->files->add(Fixtures::file('control-char', mimeType: 'text/plain'));

        Assert::same(
            $this->action->handle(Fixtures::request($this->tokenFor('control-char')))->getStatusCode(),
            200,
        );
    }

    private function actionWithPolicy(DeliveryPolicy $policy): FileDownloadAction
    {
        $factory = Fixtures::factory();

        return new FileDownloadAction(
            signer: Fixtures::signer(),
            files: $this->files,
            stores: new StoreRegistry([$this->store]),
            deliveryPolicies: new DeliveryPolicyRegistry(['*' => $policy]),
            downloads: new FileResponseFactory($factory, $factory),
            responses: $factory,
        );
    }

    private function etagOf(string $id): string
    {
        return $this->action->handle(Fixtures::request($this->tokenFor($id)))->getHeaderLine('ETag');
    }

    private function tokenFor(string $id): string
    {
        if (!$this->hasFile($id)) {
            $this->files->add(Fixtures::file($id));
        }
        if (!$this->stored) {
            $this->store->write(
                Upload::fromStream(Fixtures::factory()->createStream('hello world'), 'a.txt', Fixtures::factory()),
                'common',
                new FixedPath('common/ab/cd/key/original.txt'),
            );
            $this->stored = true;
        }

        return Fixtures::signer()->sign(
            new SignedPayload(fileId: $id),
            Fixtures::now()->modify('+1 hour'),
        );
    }

    private bool $stored = false;

    private function hasFile(string $id): bool
    {
        return $this->files->findInScope($id, null) instanceof \Rasuvaeff\Yii3Filestorage\File;
    }

    /**
     * The validator has to depend on the disposition, or a cache outlives the
     * fix. A client holding a 200 with `inline` for an SVG revalidates after
     * the operator closes the hole; the bytes are unchanged, so a validator
     * built from bytes alone still matches, the 304 carries no
     * `Content-Disposition` — RFC 9110 forbids `Content-*` there — and RFC 9111
     * has the cache keep its stored one. The `inline` would survive
     * indefinitely.
     */
    public function theValidatorChangesWhenTheDispositionDoes(): void
    {
        $this->files->add(Fixtures::file('image', mimeType: 'image/png'));

        // A group that serves inline — the default forces download, which
        // would make both sides of this comparison the same and prove nothing.
        $inlinePolicies = new DeliveryPolicyRegistry(['*' => new DeliveryPolicy(forceDownload: false)]);

        $permissive = new FileDownloadAction(
            signer: Fixtures::signer(),
            files: $this->files,
            stores: new StoreRegistry([$this->store]),
            deliveryPolicies: $inlinePolicies,
            downloads: new FileResponseFactory(Fixtures::factory(), Fixtures::factory()),
            responses: Fixtures::factory(),
            activeMediaTypes: new ActiveMediaTypes(),
        );
        $inlineEtag = $permissive->handle(Fixtures::request($this->tokenFor('image')))->getHeaderLine('ETag');

        $strict = new FileDownloadAction(
            signer: Fixtures::signer(),
            files: $this->files,
            stores: new StoreRegistry([$this->store]),
            deliveryPolicies: $inlinePolicies,
            downloads: new FileResponseFactory(Fixtures::factory(), Fixtures::factory()),
            responses: Fixtures::factory(),
            activeMediaTypes: ActiveMediaTypes::withExtra(['image/png']),
        );

        $attachmentEtag = $strict->handle(Fixtures::request($this->tokenFor('image')))->getHeaderLine('ETag');

        Assert::true($inlineEtag !== $attachmentEtag, 'the two dispositions must not share a validator');
    }

    /**
     * A 404 is heuristically cacheable, and two of the paths here are
     * transient — a store outage, an unreadable row. An intermediary must not
     * pin "gone" onto a token that stays valid.
     */
    public function aNotFoundIsNeverCached(): void
    {
        $response = $this->action->handle(Fixtures::request('not-a-token'));

        Assert::same($response->getStatusCode(), 404);
        Assert::same($response->getHeaderLine('Cache-Control'), 'no-store');
    }
}
