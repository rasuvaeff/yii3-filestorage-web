<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Support;

use DateTimeImmutable;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3Filestorage\Url\HmacUrlSigner;
use Rasuvaeff\Yii3Filestorage\Url\SigningKeyRing;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\Route;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * @internal
 */
final class Fixtures
{
    public const string SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public static function factory(): Psr17Factory
    {
        return new Psr17Factory();
    }

    public static function signer(?DateTimeImmutable $now = null): HmacUrlSigner
    {
        return new HmacUrlSigner(
            clock: new StaticClock($now ?? self::now()),
            keys: new SigningKeyRing('k1', ['k1' => self::SECRET]),
        );
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00');
    }

    /**
     * @param string $storeName Overridable because a row naming a store the
     *        configuration no longer has is the simplest way to reach the
     *        action's exception boundary — old rows keep the old name.
     */
    public static function file(
        string $id = 'file-1',
        string $mimeType = 'text/plain',
        int $size = 11,
        ?string $contentHash = null,
        ?DateTimeImmutable $updatedAt = null,
        string $storeName = 'memory',
        ?DateTimeImmutable $createdAt = null,
    ): File {
        return File::create(
            id: $id,
            storeName: $storeName,
            groupName: 'common',
            relativePath: 'common/ab/cd/key/original.txt',
            originalName: 'report notes.txt',
            size: $size,
            createdAt: $createdAt ?? self::now(),
            mimeType: $mimeType,
            contentHash: $contentHash,
            updatedAt: $updatedAt ?? self::now(),
        );
    }

    public static function deliveryOptions(bool $forceDownload = true, string $mimeType = 'text/plain'): DeliveryOptions
    {
        return DeliveryOptions::fromFile(
            file: self::file(mimeType: $mimeType),
            policy: new DeliveryPolicy(allowDirectPublicUrl: false, forceDownload: $forceDownload),
        );
    }

    /**
     * A request whose token attribute is whatever the router happened to put
     * there — including something that is not a string at all.
     */
    public static function requestWithRawToken(mixed $token): ServerRequestInterface
    {
        return (new ServerRequest('GET', '/files/'))->withAttribute('token', $token);
    }

    /**
     * The request as `yiisoft/router` hands it to an action: matched, but
     * carrying no token attribute — that router keeps its arguments in
     * CurrentRoute and never copies them into the request.
     */
    public static function routedRequest(string $token): ServerRequestInterface
    {
        return new ServerRequest('GET', '/files/' . $token);
    }

    /**
     * A CurrentRoute holding what the router matched, the way the Router
     * middleware leaves it behind for the action.
     *
     * @param array<string, string> $arguments
     */
    public static function currentRoute(array $arguments): CurrentRoute
    {
        $current = new CurrentRoute();
        $current->setRouteWithArguments(Route::get('/files/{token}'), $arguments);

        return $current;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function request(string $token = '', array $headers = []): ServerRequestInterface
    {
        $request = new ServerRequest('GET', '/files/' . $token);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request->withAttribute('token', $token);
    }
}
