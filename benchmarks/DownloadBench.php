<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Benchmarks;

use DateTimeImmutable;
use DateTimeZone;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Url\HmacUrlSigner;
use Rasuvaeff\Yii3Filestorage\Url\SignedPayload;
use Rasuvaeff\Yii3Filestorage\Url\SigningKeyRing;
use Rasuvaeff\Yii3FilestorageWeb\ActiveMediaTypes;
use Rasuvaeff\Yii3FilestorageWeb\Http\ByteRange;
use Testo\Bench;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * The per-request work this package adds, with the store taken out.
 *
 * A download is dominated by reading and writing bytes; what is measured here
 * is everything that happens *before* the body — the parts that run on a 304
 * too, and therefore on the requests that are supposed to be cheap.
 *
 * @internal
 */
final class DownloadBench
{
    private static ?HmacUrlSigner $signer = null;
    private static ?string $token = null;

    /**
     * Verification runs on every request, valid or not, so it is the floor on
     * what a download costs. Against the raw HMAC it wraps.
     */
    #[Bench(
        callables: ['a bare hash_hmac' => [self::class, 'bareHmac']],
        calls: 2_000,
        iterations: 10,
    )]
    public static function verifyAToken(): ?SignedPayload
    {
        return self::signer()->verify(self::token());
    }

    public static function bareHmac(): string
    {
        return hash_hmac('sha256', self::token(), 'secret');
    }

    /**
     * The validator computed for every response, including the 304s. Compared
     * with the SHA-256 it would have been if the fallback reused the content
     * hash function.
     */
    #[Bench(
        callables: ['sha256' => [self::class, 'sha256Etag']],
        calls: 5_000,
        iterations: 10,
    )]
    public static function computeAnEtag(): string
    {
        $file = self::file();

        return '"' . hash('xxh128', $file->id . '|' . $file->size . '|' . $file->updatedAt->format('U.u')) . '"';
    }

    public static function sha256Etag(): string
    {
        $file = self::file();

        return '"' . hash('sha256', $file->id . '|' . $file->size . '|' . $file->updatedAt->format('U.u')) . '"';
    }

    /**
     * Range parsing, against the `preg_match` that is most of it. A video
     * element seeking issues a lot of these.
     */
    #[Bench(
        callables: ['the regex alone' => [self::class, 'rangeRegex']],
        calls: 5_000,
        iterations: 10,
    )]
    public static function parseARange(): ByteRange|false|null
    {
        return ByteRange::parse('bytes=1024-2047', 1_048_576);
    }

    public static function rangeRegex(): int|false
    {
        return preg_match('/^bytes=(\d*)-(\d*)\z/i', 'bytes=1024-2047');
    }

    /**
     * Runs once per response and decides whether a body is executable. A
     * linear scan of a short list, against the hash lookup it could have been.
     */
    #[Bench(
        callables: ['an isset on a flipped map' => [self::class, 'flippedLookup']],
        calls: 10_000,
        iterations: 10,
    )]
    public static function classifyAMediaType(): bool
    {
        return (new ActiveMediaTypes())->contains('text/html; charset=utf-8');
    }

    public static function flippedLookup(): bool
    {
        return isset(array_flip(ActiveMediaTypes::DEFAULT)['text/html']);
    }

    private static function signer(): HmacUrlSigner
    {
        return self::$signer ??= new HmacUrlSigner(
            clock: new StaticClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00', new DateTimeZone('UTC'))),
            keys: new SigningKeyRing('bench', ['bench' => str_repeat('a', 64)]),
        );
    }

    private static function token(): string
    {
        return self::$token ??= self::signer()->sign(
            new SignedPayload(fileId: 'bench-file', scopeId: 'tenant-a'),
            new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
        );
    }

    private static function file(): File
    {
        return File::create(
            id: 'bench-file',
            storeName: 'memory',
            groupName: 'common',
            relativePath: 'common/a/b/original.txt',
            originalName: 'notes.txt',
            size: 4096,
            createdAt: new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'),
            mimeType: 'text/plain',
        );
    }
}
