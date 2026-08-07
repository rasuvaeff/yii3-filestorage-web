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

    public static function file(
        string $id = 'file-1',
        string $mimeType = 'text/plain',
        int $size = 11,
        ?string $contentHash = null,
        ?DateTimeImmutable $updatedAt = null,
    ): File {
        return File::create(
            id: $id,
            storeName: 'memory',
            groupName: 'common',
            relativePath: 'common/ab/cd/key/original.txt',
            originalName: 'report notes.txt',
            size: $size,
            createdAt: self::now(),
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
