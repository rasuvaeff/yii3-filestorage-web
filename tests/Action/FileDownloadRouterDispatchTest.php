<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Action;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3Filestorage\Url\SignedPayload;
use Rasuvaeff\Yii3FilestorageWeb\Action\FileDownloadAction;
use Rasuvaeff\Yii3FilestorageWeb\Http\FileResponseFactory;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\FixedPath;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\InMemoryResolver;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Middleware\Dispatcher\MiddlewareFactory;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\FastRoute\UrlMatcher;
use Yiisoft\Router\Middleware\Router;
use Yiisoft\Router\Route;
use Yiisoft\Router\RouteCollection;
use Yiisoft\Router\RouteCollector;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * The README recipe, dispatched for real. `yiisoft/router` never copies its
 * arguments into request attributes — they live in `CurrentRoute` — and the
 * unit tests set the token's sources by hand, so none of them could see the
 * gap this suite closes: a real `Router` middleware, a real FastRoute
 * matcher, no attribute middleware, no parameters resolver.
 *
 * @internal
 */
#[Test]
#[CoversNothing]
final class FileDownloadRouterDispatchTest
{
    public function theReadmeRecipeServesTheFile(): void
    {
        $token = self::tokenFor('file-1');

        $response = $this->router()->process(new ServerRequest('GET', '/files/' . $token), self::unmatched());

        Assert::same($response->getStatusCode(), 200);
        Assert::same((string) $response->getBody(), 'hello world');
    }

    /**
     * A tampered token reaches the action through the same route and gets the
     * same 404 as anywhere else — the transport did not change the contract.
     */
    public function theReadmeRecipeRefusesATamperedToken(): void
    {
        $response = $this->router()->process(new ServerRequest('GET', '/files/v1.k1.tampered'), self::unmatched());

        Assert::same($response->getStatusCode(), 404);
        Assert::same($response->getHeaderLine('Cache-Control'), 'no-store');
    }

    /**
     * A path no route matches is not this package's to answer: the request
     * reaches the host's own handler untouched.
     */
    public function anUnmatchedPathReachesTheHostHandler(): void
    {
        $response = $this->router()->process(new ServerRequest('GET', '/elsewhere'), self::unmatched());

        Assert::same($response->getStatusCode(), 418);
    }

    private function router(): Router
    {
        $factory = Fixtures::factory();

        $files = new InMemoryResolver();
        $files->add(Fixtures::file('file-1'));
        $store = new InMemoryStore('memory', $factory, new StaticClock(Fixtures::now()));
        $store->write(
            Upload::fromStream($factory->createStream('hello world'), 'a.txt', $factory),
            'common',
            new FixedPath('common/ab/cd/key/original.txt'),
        );

        $currentRoute = new CurrentRoute();
        $action = new FileDownloadAction(
            signer: Fixtures::signer(),
            files: $files,
            stores: new StoreRegistry([$store]),
            deliveryPolicies: new DeliveryPolicyRegistry(),
            downloads: new FileResponseFactory($factory, $factory),
            responses: $factory,
            currentRoute: $currentRoute,
        );

        // One container, one CurrentRoute — the wiring a Yii3 application
        // has, where the Router middleware and the action share the singleton
        // the container hands out.
        $container = new Container(ContainerConfig::create()->withDefinitions([
            FileDownloadAction::class => static fn(): FileDownloadAction => $action,
        ]));

        $collector = new RouteCollector();
        $collector->addRoute(
            Route::get('/files/{token}')->action(FileDownloadAction::class)->name('file/download'),
        );

        return new Router(
            matcher: new UrlMatcher(new RouteCollection($collector)),
            responseFactory: $factory,
            middlewareFactory: new MiddlewareFactory($container),
            currentRoute: $currentRoute,
        );
    }

    private static function tokenFor(string $id): string
    {
        return Fixtures::signer()->sign(
            new SignedPayload(fileId: $id),
            Fixtures::now()->modify('+1 hour'),
        );
    }

    private static function unmatched(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory())->createResponse(418);
            }
        };
    }
}
