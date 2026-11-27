<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Mime\ActiveMediaTypes;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Url\SignedPayload;
use Rasuvaeff\Yii3Filestorage\Url\SigningKeyRing;
use Rasuvaeff\Yii3Filestorage\Url\UrlSignerInterface;
use Rasuvaeff\Yii3FilestorageWeb\Action\FileDownloadAction;
use Rasuvaeff\Yii3FilestorageWeb\Http\FileResponseFactory;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\FixedPath;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\InMemoryResolver;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\MemoryDerivativeResolver;
use Rasuvaeff\Yii3FilestorageWeb\Url\HmacProxyUrlGenerator;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * `config/di.php` is covered by neither cs, nor psalm, nor the unit suite — it
 * is not in `src`. Without this test a mistake there surfaces at deploy time,
 * so it is exercised through a real container rather than by reading the array.
 *
 * @internal
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function everyServiceThisPackageOwnsResolves(): void
    {
        $container = $this->container();

        Assert::instanceOf($container->get(SigningKeyRing::class), SigningKeyRing::class);
        Assert::instanceOf($container->get(UrlSignerInterface::class), UrlSignerInterface::class);
        Assert::instanceOf($container->get(ProxyUrlGeneratorInterface::class), HmacProxyUrlGenerator::class);
        Assert::instanceOf($container->get(FileResponseFactory::class), FileResponseFactory::class);
        Assert::instanceOf($container->get(FileDownloadAction::class), FileDownloadAction::class);
    }

    /**
     * Installing this package is what gives a private store a URL at all —
     * core declares the generator optional and leaves it unbound.
     */
    public function theWiredGeneratorMintsAUsableToken(): void
    {
        $container = $this->container();
        $url = $container->get(ProxyUrlGeneratorInterface::class)
            ->url(Fixtures::file(), Fixtures::now()->modify('+1 hour'));

        Assert::string((string) $url)->contains('/files/v1.k1.');
        Assert::same(
            $container->get(UrlSignerInterface::class)->verify(substr((string) $url, strlen('/files/')))?->fileId,
            'file-1',
        );
    }

    /**
     * A background job asks for the concrete generator to call `forScope()`.
     * Autowiring it would have used the default route and no scope provider;
     * the binding hands out the same configured instance the interface gets.
     */
    public function theConcreteGeneratorIsTheConfiguredOne(): void
    {
        $container = $this->container(paramOverrides: ['route' => 'https://app.example.com/dl/{token}']);

        $concrete = $container->get(HmacProxyUrlGenerator::class);

        Assert::same($container->get(ProxyUrlGeneratorInterface::class), $concrete);
        Assert::string($concrete->forScope('tenant-a')->url(Fixtures::file(), Fixtures::now()->modify('+1 hour')))
            ->contains('https://app.example.com/dl/v1.k1.');
    }

    /**
     * The active-type list is core's (`Mime\ActiveMediaTypes`, one list for
     * the family), so this package must not bind its key — and the
     * application's extras still have to reach the action.
     */
    public function extraActiveMediaTypesReachTheActionWithoutBindingCoresKey(): void
    {
        Assert::false(\array_key_exists(ActiveMediaTypes::class, $this->definitions()));

        $store = new InMemoryStore('memory', Fixtures::factory(), new StaticClock(Fixtures::now()));
        $store->write(
            Upload::fromStream(Fixtures::factory()->createStream('hello world'), 'a.txt', Fixtures::factory()),
            'common',
            new FixedPath('common/ab/cd/key/original.txt'),
        );
        $inline = [
            DeliveryPolicyRegistry::class => static fn(): DeliveryPolicyRegistry
                => new DeliveryPolicyRegistry(['*' => new DeliveryPolicy(forceDownload: false)]),
            StoreRegistry::class => static fn(): StoreRegistry => new StoreRegistry([$store]),
        ];
        $token = Fixtures::signer()->sign(new SignedPayload(fileId: 'file-1'), Fixtures::now()->modify('+1 hour'));

        $stock = $this->container($inline)->get(FileDownloadAction::class);
        $strict = $this->container($inline, ['extraActiveMediaTypes' => ['text/plain']])
            ->get(FileDownloadAction::class);

        Assert::string($stock->handle(Fixtures::request($token))->getHeaderLine('Content-Disposition'))
            ->contains('inline');
        Assert::string($strict->handle(Fixtures::request($token))->getHeaderLine('Content-Disposition'))
            ->contains('attachment');
    }

    /**
     * `-images` is optional: without a DerivativeResolverInterface binding the
     * action still builds, and a variant token is a 404 — never the original.
     */
    public function withoutAResolverAVariantTokenAnswersNotFound(): void
    {
        $action = $this->container()->get(FileDownloadAction::class);

        Assert::same($action->handle(Fixtures::request($this->variantToken()))->getStatusCode(), 404);
    }

    /**
     * `-images` binds DerivativeResolverInterface; the wired action must pick
     * it up, or every variant token in a stock install is a 404 (#21).
     */
    public function aBoundResolverServesVariantTokens(): void
    {
        $action = $this->container([
            DerivativeResolverInterface::class => static fn(): DerivativeResolverInterface
                => new MemoryDerivativeResolver(
                    presets: ['thumb' => ['extension' => 'webp', 'mediaType' => 'image/webp']],
                    bodies: ['file-1/thumb' => 'thumbnail bytes'],
                ),
        ])->get(FileDownloadAction::class);

        $response = $action->handle(Fixtures::request($this->variantToken()));

        Assert::same($response->getStatusCode(), 200);
        Assert::same((string) $response->getBody(), 'thumbnail bytes');
        Assert::same($response->getHeaderLine('Content-Type'), 'image/webp');
    }

    /**
     * The one-source rule. Core binds the facade, `-db` the metadata, a store
     * backend the bytes, and this package the HTTP half. Either side claiming
     * another's key makes installing both a `Duplicate key` error.
     */
    public function thisPackageBindsOnlyItsOwnHalf(): void
    {
        $definitions = $this->definitions();

        foreach ([StorageInterface::class, RepositoryInterface::class, StoreInterface::class] as $foreign) {
            Assert::false(\array_key_exists($foreign, $definitions), "must not bind {$foreign}");
        }
    }

    /**
     * `params.php` has to carry every key `di.php` reads, or the package fails
     * to boot against its own defaults.
     */
    public function everyParameterTheWiringReadsIsShipped(): void
    {
        $own = $this->params()['rasuvaeff/yii3-filestorage-web'];

        foreach (['route', 'tokenAttribute', 'cacheControl', 'signingKeys', 'extraActiveMediaTypes'] as $key) {
            Assert::true(\array_key_exists($key, $own), "params is missing \"{$key}\"");
        }
        Assert::true(\array_key_exists('active', $own['signingKeys']));
        Assert::true(\array_key_exists('keys', $own['signingKeys']));
    }

    /**
     * The shipped default is `private`: a signed URL is per-recipient, so a
     * shared cache storing one would serve a tenant's file to the next request.
     */
    public function theShippedCacheControlIsPrivate(): void
    {
        Assert::string((string) $this->params()['rasuvaeff/yii3-filestorage-web']['cacheControl'])
            ->contains('private');
    }

    /**
     * @return array<string, mixed>
     */
    private function definitions(array $paramOverrides = []): array
    {
        $params = $this->params();
        $params['rasuvaeff/yii3-filestorage-web'] = [
            ...$params['rasuvaeff/yii3-filestorage-web'],
            'signingKeys' => ['active' => 'k1', 'keys' => ['k1' => Fixtures::SECRET]],
            ...$paramOverrides,
        ];

        return require __DIR__ . '/../config/di.php';
    }

    private function variantToken(): string
    {
        return Fixtures::signer()->sign(
            new SignedPayload(fileId: 'file-1', variant: 'thumb'),
            Fixtures::now()->modify('+1 hour'),
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @param array<string, mixed> $paramOverrides
     */
    private function container(array $extra = [], array $paramOverrides = []): Container
    {
        $psr = Fixtures::factory();
        $definitions = $this->definitions($paramOverrides);

        $definitions[ClockInterface::class] = static fn(): ClockInterface => new StaticClock(Fixtures::now());
        $definitions[ResponseFactoryInterface::class] = static fn(): ResponseFactoryInterface => $psr;
        $definitions[StreamFactoryInterface::class] = static fn(): StreamFactoryInterface => $psr;
        $files = new InMemoryResolver();
        $files->add(Fixtures::file());
        $definitions[ScopedFileResolverInterface::class] = static fn(): ScopedFileResolverInterface => $files;
        $definitions[StoreRegistry::class] = static fn(): StoreRegistry => new StoreRegistry([
            new InMemoryStore('memory', $psr, new StaticClock(Fixtures::now())),
        ]);
        $definitions[DeliveryPolicyRegistry::class] = static fn(): DeliveryPolicyRegistry
            => new DeliveryPolicyRegistry();

        return new Container(ContainerConfig::create()->withDefinitions([...$definitions, ...$extra]));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function params(): array
    {
        return require __DIR__ . '/../config/params.php';
    }
}
