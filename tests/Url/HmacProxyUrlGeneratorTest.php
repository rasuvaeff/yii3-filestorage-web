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
