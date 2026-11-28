<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Exception\InvalidConfigException;
use Rasuvaeff\Yii3Filestorage\Mime\ActiveMediaTypes;
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
use Yiisoft\Di\BuildingException;
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
     * The active-type list is core's (`Mime\ActiveMediaTypes`, one instance
     * for the family, built from core's `activeMediaTypes` param), so this
     * package must not bind that key — and what the application configures
     * there, extras and the PDF opt-out, has to reach the action. Core's real
     * `config/di.php` binds both the list and the delivery registry here.
     */
    public function coresConfiguredActiveListReachesTheAction(): void
    {
        Assert::false(\array_key_exists(ActiveMediaTypes::class, $this->definitions()));

        $store = new InMemoryStore('memory', Fixtures::factory(), new StaticClock(Fixtures::now()));
        $store->write(
            Upload::fromStream(Fixtures::factory()->createStream('hello world'), 'a.txt', Fixtures::factory()),
            'common',
            new FixedPath('common/ab/cd/key/original.txt'),
        );
        $files = new InMemoryResolver();
        $files->add(Fixtures::file());
        $files->add(Fixtures::file('pdf', mimeType: 'application/pdf'));
        $token = static fn(string $id): string
            => Fixtures::signer()->sign(new SignedPayload(fileId: $id), Fixtures::now()->modify('+1 hour'));
        $disposition = fn(array $activeMediaTypes, string $id): string => $this->container([
            ...$this->coreBindings(['activeMediaTypes' => $activeMediaTypes]),
            StoreRegistry::class => static fn(): StoreRegistry => new StoreRegistry([$store]),
            ScopedFileResolverInterface::class => static fn(): ScopedFileResolverInterface => $files,
        ])->get(FileDownloadAction::class)->handle(Fixtures::request($token($id)))->getHeaderLine('Content-Disposition');

        $stock = ['extra' => [], 'allowInline' => []];
        Assert::string($disposition($stock, 'file-1'))->contains('inline');
        Assert::string($disposition(['extra' => ['text/plain'], 'allowInline' => []], 'file-1'))->contains('attachment');
        Assert::string($disposition($stock, 'pdf'))->contains('attachment');
        Assert::string($disposition(['extra' => [], 'allowInline' => ['application/pdf']], 'pdf'))->contains('inline');
    }

    /**
     * The package's own `extraActiveMediaTypes` param is gone (0.7.0). An
     * application still setting it would otherwise lose those types silently —
     * files it meant to force to attachment served inline — so booting with
     * it is refused, naming the replacement.
     */
    public function aLeftoverExtraActiveMediaTypesParamIsRefused(): void
    {
        $container = $this->container(paramOverrides: ['extraActiveMediaTypes' => ['text/plain']]);

        $refusal = null;

        try {
            $container->get(FileDownloadAction::class);
        } catch (BuildingException $e) {
            $refusal = $e->getPrevious();
        }

        Assert::instanceOf($refusal, InvalidConfigException::class);
        Assert::string($refusal?->getMessage())->contains('"activeMediaTypes" => "extra"');
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

        foreach (['route', 'tokenAttribute', 'cacheControl', 'signingKeys'] as $key) {
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

    /**
     * Core's own bindings of the active list and the delivery registry, from
     * its shipped `config/di.php` and `config/params.php` with an inline
     * group, so the disposition is decided by the active list alone.
     *
     * @param array<string, mixed> $coreOverrides
     *
     * @return array<string, mixed>
     */
    private function coreBindings(array $coreOverrides): array
    {
        $core = __DIR__ . '/../vendor/rasuvaeff/yii3-filestorage/config';
        $params = require $core . '/params.php';
        $params['rasuvaeff/yii3-filestorage'] = [
            ...$params['rasuvaeff/yii3-filestorage'],
            'delivery' => ['*' => ['allowDirectPublicUrl' => false, 'forceDownload' => false]],
            ...$coreOverrides,
        ];
        $definitions = (static fn(array $params): array => require $core . '/di.php')($params);

        return [
            ActiveMediaTypes::class => $definitions[ActiveMediaTypes::class],
            DeliveryPolicyRegistry::class => $definitions[DeliveryPolicyRegistry::class],
        ];
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
