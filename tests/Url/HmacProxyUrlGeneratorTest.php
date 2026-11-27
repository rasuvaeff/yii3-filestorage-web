<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Url;

use InvalidArgumentException;
use Override;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageWeb\Url\HmacProxyUrlGenerator;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(HmacProxyUrlGenerator::class)]
final class HmacProxyUrlGeneratorTest
{
    public function putsASignedTokenIntoTheRoute(): void
    {
        $generator = new HmacProxyUrlGenerator(Fixtures::signer());

        $url = $generator->url(Fixtures::file(), Fixtures::now()->modify('+1 hour'));

        Assert::string($url)->contains('/files/v1.k1.');
        // and it verifies back to the file it was minted for
        $token = substr($url, strlen('/files/'));
        Assert::same(Fixtures::signer()->verify($token)?->fileId, 'file-1');
    }

    public function theRouteCanBeAbsolute(): void
    {
        $generator = new HmacProxyUrlGenerator(Fixtures::signer(), 'https://cdn.example.com/d/{token}');

        Assert::string($generator->url(Fixtures::file(), Fixtures::now()->modify('+1 hour')))
            ->contains('https://cdn.example.com/d/v1.k1.');
    }

    /**
     * The scope is stamped in at minting time, from the request doing the
     * minting — so the URL carries the tenant it was created for and cannot
     * resolve anything else later.
     */
    public function theCurrentScopeTravelsInsideTheToken(): void
    {
        $generator = new HmacProxyUrlGenerator(Fixtures::signer(), scopes: new FixedScope('tenant-a'));

        $url = $generator->url(Fixtures::file(), Fixtures::now()->modify('+1 hour'));
        $payload = Fixtures::signer()->verify(substr($url, strlen('/files/')));

        Assert::same($payload?->scopeId, 'tenant-a');
    }

    public function withoutAScopeProviderTheTokenIsUnscoped(): void
    {
        $generator = new HmacProxyUrlGenerator(Fixtures::signer());

        $url = $generator->url(Fixtures::file(), Fixtures::now()->modify('+1 hour'));

        Assert::null(Fixtures::signer()->verify(substr($url, strlen('/files/')))?->scopeId);
    }

    /**
     * A rendition URL carries the variant inside the signature, the same way
     * presigned store URLs carry their constraints: a thumbnail token cannot
     * be replayed as the full-resolution file.
     */
    public function variantUrlSignsTheVariantIntoThePayload(): void
    {
        $generator = new HmacProxyUrlGenerator(Fixtures::signer());

        $url = $generator->variantUrl(Fixtures::file(), 'thumb', Fixtures::now()->modify('+1 hour'));

        $payload = Fixtures::signer()->verify(substr($url, strlen('/files/')));

        Assert::same($payload?->fileId, 'file-1');
        Assert::same($payload?->variant, 'thumb');
        Assert::null($payload?->scopeId);
    }

    /**
     * The scope stamps in for a rendition token exactly as for an original —
     * a minted thumbnail URL is for the tenant that asked.
     */
    public function variantUrlCarriesTheCurrentScope(): void
    {
        $generator = new HmacProxyUrlGenerator(Fixtures::signer(), scopes: new FixedScope('tenant-a'));

        $payload = Fixtures::signer()->verify(
            substr($generator->variantUrl(Fixtures::file(), 'thumb', Fixtures::now()->modify('+1 hour')), strlen('/files/')),
        );

        Assert::same($payload?->scopeId, 'tenant-a');
        Assert::same($payload?->variant, 'thumb');
    }

    /**
     * Review 1.0 #11. A link minted outside a request — a queued email, a
     * report job — has no current tenant: the provider answers null, the
     * token said "unscoped", and the download looked the tenant's file up
     * under `scope IS NULL`: a 404 in the recipient's inbox. `forScope()`
     * states the tenant explicitly and ignores the provider.
     */
    public function aJobMintsForTheTenantItNames(): void
    {
        $generator = new HmacProxyUrlGenerator(Fixtures::signer(), scopes: new FixedScope(null));

        $pinned = $generator->forScope('tenant-a');
        $original = Fixtures::signer()->verify(
            substr($pinned->url(Fixtures::file(), Fixtures::now()->modify('+1 hour')), strlen('/files/')),
        );
        $variant = Fixtures::signer()->verify(
            substr($pinned->variantUrl(Fixtures::file(), 'thumb', Fixtures::now()->modify('+1 hour')), strlen('/files/')),
        );

        Assert::same($original?->scopeId, 'tenant-a');
        Assert::same($variant?->scopeId, 'tenant-a');
        Assert::same($variant?->variant, 'thumb');
    }

    /**
     * The pinned scope wins over a provider that does answer, and pinning
     * null is an explicit "unscoped" — never "whatever the provider says".
     */
    public function aPinnedScopeOverridesTheProvider(): void
    {
        $generator = new HmacProxyUrlGenerator(Fixtures::signer(), scopes: new FixedScope('tenant-a'));

        $toB = Fixtures::signer()->verify(substr(
            $generator->forScope('tenant-b')->url(Fixtures::file(), Fixtures::now()->modify('+1 hour')),
            strlen('/files/'),
        ));
        $unscoped = Fixtures::signer()->verify(substr(
            $generator->forScope(null)->url(Fixtures::file(), Fixtures::now()->modify('+1 hour')),
            strlen('/files/'),
        ));

        Assert::same($toB?->scopeId, 'tenant-b');
        Assert::notNull($unscoped);
        Assert::null($unscoped?->scopeId);
        // the original is untouched
        Assert::same(
            Fixtures::signer()->verify(substr(
                $generator->url(Fixtures::file(), Fixtures::now()->modify('+1 hour')),
                strlen('/files/'),
            ))?->scopeId,
            'tenant-a',
        );
    }

    public function anEmptyPinnedScopeIsRefused(): void
    {
        Expect::exception(InvalidArgumentException::class)->withMessageContaining('cannot be empty');

        (new HmacProxyUrlGenerator(Fixtures::signer()))->forScope('');
    }

    /**
     * The route travels with the copy.
     */
    public function aPinnedCopyKeepsTheRoute(): void
    {
        $generator = new HmacProxyUrlGenerator(Fixtures::signer(), 'https://cdn.example.com/d/{token}');

        Assert::string($generator->forScope('tenant-a')->url(Fixtures::file(), Fixtures::now()->modify('+1 hour')))
            ->contains('https://cdn.example.com/d/v1.k1.');
    }

    /**
     * A route with no placeholder produces the same URL for every file — one
     * that resolves to whatever the router does with it, which is not a
     * download. Better to refuse at wiring time than to serve it.
     */
    #[DataProvider('badRouteProvider')]
    public function aRouteWithoutThePlaceholderIsRefused(string $route): void
    {
        Expect::exception(InvalidArgumentException::class)
            ->withMessageContaining('must contain the {token} placeholder');

        new HmacProxyUrlGenerator(Fixtures::signer(), $route);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badRouteProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'no placeholder' => ['/files'];
        yield 'a different placeholder' => ['/files/{id}'];
        yield 'a partial placeholder' => ['/files/{tok}'];
    }
}

/**
 * @internal
 */
final readonly class FixedScope implements FileScopeProviderInterface
{
    public function __construct(private ?string $scopeId) {}

    #[Override]
    public function currentScopeId(): ?string
    {
        return $this->scopeId;
    }
}
