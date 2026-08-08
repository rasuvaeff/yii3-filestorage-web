<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Store\RangeReadableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Stream\LimitedStream;
use Yiisoft\Http\ContentDispositionHeader;
use Yiisoft\Http\Header;
use Yiisoft\Http\Status;

/**
 * Turns a stored file into a download response, with ranges where they work.
 *
 * The interesting decision is when to advertise `Accept-Ranges`, and it is a
 * question about the *store*, not about the body in hand. Three cases:
 *
 * - The store implements {@see RangeReadableStoreInterface}: it has a real
 *   range primitive, so a range costs one ranged read.
 * - It does not, but the body is seekable and knows its length — a local file:
 *   windowing it with {@see LimitedStream} is a seek, which is just as cheap.
 * - Neither, which is every object store: `readStream()` gives a forward-only
 *   body and honouring a range would mean downloading and discarding the
 *   prefix. So no `Accept-Ranges`, and a full `200`. That is the honest answer,
 *   and for S3 the right fix is a presigned URL, which S3 ranges natively.
 *
 * The first case is why this is written rather than delegated: a response
 * builder handed only a stream can serve the second case and never the first.
 *
 * @api
 */
final readonly class FileResponseFactory
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    /**
     * @param string|null $rangeHeader The request's `Range`, when it had one
     *        and any `If-Range` precondition passed.
     */
    public function create(
        File $file,
        StoreInterface $store,
        StreamInterface $stream,
        DeliveryOptions $options,
        bool $inline,
        ?string $rangeHeader = null,
    ): ResponseInterface {
        $rangeable = $store instanceof RangeReadableStoreInterface
            || ($stream->isSeekable() && $stream->getSize() !== null);

        $response = $this->responses->createResponse()
            ->withHeader(Header::CONTENT_TYPE, self::headerSafe($options->responseMediaType))
            ->withHeader(
                ContentDispositionHeader::name(),
                ContentDispositionHeader::value(
                    $inline ? ContentDispositionHeader::INLINE : ContentDispositionHeader::ATTACHMENT,
                    $options->downloadName,
                ),
            );

        if (!$rangeable) {
            return $response->withBody($stream);
        }

        // The persisted size is the one the client is told about, so a range is
        // computed against the same number `Content-Length` reports.
        $size = max(0, $stream->getSize() ?? $file->size);

        $range = $rangeHeader === null || $rangeHeader === ''
            ? false
            : ByteRange::parse($rangeHeader, $size);

        if ($range === null) {
            $stream->close();

            // Without the file's own type and disposition. A browser handed
            // `attachment; filename="report.pdf"` with `application/pdf` and
            // an empty body saves a zero-byte report.pdf that looks like a
            // download that worked.
            return $response
                ->withStatus(Status::RANGE_UNSATISFIABLE)
                ->withoutHeader(Header::CONTENT_TYPE)
                ->withoutHeader(Header::CONTENT_DISPOSITION)
                ->withHeader(Header::ACCEPT_RANGES, 'bytes')
                ->withHeader(Header::CONTENT_RANGE, "bytes */{$size}")
                ->withHeader(Header::CONTENT_LENGTH, '0')
                ->withBody($this->streams->createStream());
        }

        if ($range === false) {
            return $response
                ->withHeader(Header::ACCEPT_RANGES, 'bytes')
                ->withHeader(Header::CONTENT_LENGTH, (string) $size)
                ->withBody($stream);
        }

        if ($store instanceof RangeReadableStoreInterface) {
            $ranged = $store->streamRange($file, $range->first, $range->length());
            if ($ranged instanceof StreamInterface) {
                $stream->close();

                return $response
                    ->withStatus(Status::PARTIAL_CONTENT)
                    ->withHeader(Header::ACCEPT_RANGES, 'bytes')
                    ->withHeader(Header::CONTENT_RANGE, $range->contentRange($size))
                    ->withHeader(Header::CONTENT_LENGTH, (string) $range->length())
                    ->withBody($ranged);
            }

            // The object disappeared between stream() and streamRange(). A
            // forward-only source cannot safely fulfil the requested window,
            // so fall back to an ordinary representation instead of labeling
            // its first bytes as a later range.
            if (!$stream->isSeekable()) {
                return $response
                    ->withHeader(Header::CONTENT_LENGTH, (string) $size)
                    ->withBody($stream);
            }
        }

        return $response
            ->withStatus(Status::PARTIAL_CONTENT)
            ->withHeader(Header::ACCEPT_RANGES, 'bytes')
            ->withHeader(Header::CONTENT_RANGE, $range->contentRange($size))
            ->withHeader(Header::CONTENT_LENGTH, (string) $range->length())
            ->withBody(new LimitedStream($stream, $range->first, $range->length()));
    }

    /**
     * Strips what must never reach a header value.
     *
     * `DeliveryOptions::fromFile()` already does this for the sibling download
     * name; the media type arrived unchecked, and `File::create()` validates it
     * only as non-empty. The shipped upload path cannot produce a bad one —
     * finfo sniffs server-side — but `File::fromArray()`, a row written by
     * another system, or a custom detector all can. The realistic outcome is a
     * 500 from a strict PSR-7 implementation and a split header on a lax one,
     * and an interior CRLF is also the one string that slips past
     * `ActiveMediaTypes::contains()` and gets served inline.
     *
     * @return non-empty-string
     */
    private static function headerSafe(string $mediaType): string
    {
        $clean = str_replace(["\r", "\n", "\0"], '', $mediaType);

        return $clean === '' ? 'application/octet-stream' : $clean;
    }
}
