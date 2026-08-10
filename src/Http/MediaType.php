<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Http;

/**
 * The single point where a stored media type becomes one fit for a header.
 *
 * Single is the whole point. The delivery decision — inline or attachment — is
 * taken by looking the type up in
 * {@see \Rasuvaeff\Yii3FilestorageWeb\ActiveMediaTypes}, and the
 * `Content-Type` header is written from the same type. Cleaning only at the
 * header end left one string in between: a stored `text/ht\r\nml` misses the
 * active-type lookup, is therefore ruled inline, and is then emitted as
 * `text/html`. That is exactly the stored XSS the list exists to stop, reachable
 * by any row this package did not write itself — `File::fromArray()`, another
 * system's insert, a custom detector — since `File::create()` validates a media
 * type only as non-empty.
 *
 * `DeliveryOptions::fromFile()` already does this for the sibling download name.
 *
 * Called at each boundary rather than once and threaded through: it is
 * idempotent, and {@see FileResponseFactory} is public API that gets called
 * with a `DeliveryOptions` this package never saw. A factory that trusted its
 * caller to have normalized would put the hole back, one layer out. What must
 * never exist is a *second* normalizer, or a media-type argument beside the
 * one already inside the options.
 *
 * @internal
 */
final readonly class MediaType
{
    /**
     * Strips what must never reach a header value.
     *
     * On a lax PSR-7 implementation an interior CRLF splits the header; on a
     * strict one it is a 500 where the contract promises a download.
     *
     * @return non-empty-string
     */
    public static function headerSafe(string $mediaType): string
    {
        $clean = str_replace(["\r", "\n", "\0"], '', $mediaType);

        return $clean === '' ? 'application/octet-stream' : $clean;
    }
}
