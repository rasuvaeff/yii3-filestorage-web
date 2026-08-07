<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Action;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Url\UrlSignerInterface;
use Rasuvaeff\Yii3FilestorageWeb\ActiveMediaTypes;
use Rasuvaeff\Yii3FilestorageWeb\Http\FileResponseFactory;
use Yiisoft\Http\Header;
use Yiisoft\Http\Status;

/**
 * Serves a stored file over a signed URL.
 *
 * Response construction — `Content-Disposition`, single byte ranges, `206`,
 * `416`, `Accept-Ranges` — is {@see FileResponseFactory}'s. What this action
 * owns is everything that needs to know about *this* file: who may read it,
 * whether the client already has it, and how it is allowed to be delivered.
 *
 * Four rules it enforces, in order:
 *
 * 1. **Everything that fails is a 404.** A bad signature, an expired token, a
 *    file in another tenant's scope, a row whose bytes are gone. A 403 would
 *    confirm the id exists, which is the one thing an opaque token is for.
 * 2. **The scope comes from the token, never from the request.** A signed URL
 *    is served without a session on purpose, so the tenant travels inside the
 *    HMAC and is matched as a second predicate.
 * 3. **Conditional requests are answered before a body is built.** A `304`
 *    never opens the store.
 * 4. **Active content is an attachment, whatever the policy says.** An HTML or
 *    SVG file served inline from your own origin is stored XSS; `nosniff` stops
 *    a browser from finding one where the media type says there is none.
 *
 * @api
 */
final readonly class FileDownloadAction implements RequestHandlerInterface
{
    /**
     * RFC 9110's IMF-fixdate, spelled out rather than taken from
     * `DATE_RFC7231`: that constant is deprecated in PHP 8.5 for ignoring the
     * timezone it is given, and this package supports 8.5.
     */
    private const string HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    /**
     * @param string $tokenAttribute The request attribute the router puts the
     *        token in — `Route::get('/files/{token}')` gives `token`.
     */
    public function __construct(
        private UrlSignerInterface $signer,
        private ScopedFileResolverInterface $files,
        private StoreRegistry $stores,
        private DeliveryPolicyRegistry $deliveryPolicies,
        private FileResponseFactory $downloads,
        private ResponseFactoryInterface $responses,
        private ActiveMediaTypes $activeMediaTypes = new ActiveMediaTypes(),
        private string $cacheControl = 'private, max-age=3600',
        private string $tokenAttribute = 'token',
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Through the attribute array rather than `getAttribute()`: an array
        // offset is something psalm narrows across accesses, where a method
        // call's `mixed` would need a `@var` tag that rector deletes as
        // redundant.
        $attributes = $request->getAttributes();
        $payload = isset($attributes[$this->tokenAttribute])
            && \is_string($attributes[$this->tokenAttribute])
                ? $this->signer->verify($attributes[$this->tokenAttribute])
                : null;

        // A variant is a rendition the images package produces. Until it
        // exists, a token asking for one must not quietly get the original —
        // the variant is inside the signature precisely so it cannot.
        if (!$payload instanceof \Rasuvaeff\Yii3Filestorage\Url\SignedPayload || $payload->variant !== null) {
            return $this->notFound();
        }

        $file = $this->files->findInScope($payload->fileId, $payload->scopeId);
        if (!$file instanceof \Rasuvaeff\Yii3Filestorage\File) {
            return $this->notFound();
        }

        $etag = $this->etag($file);
        if ($this->isCurrent($request, $etag, $file->updatedAt)) {
            return $this->validators($this->responses->createResponse(Status::NOT_MODIFIED), $etag, $file);
        }

        $store = $this->stores->get($file->storeName);
        $stream = $store->stream($file);
        if (!$stream instanceof \Psr\Http\Message\StreamInterface) {
            // A row pointing at bytes that are gone. Nothing to serve, and
            // nothing the client can do about it either.
            return $this->notFound();
        }

        $options = DeliveryOptions::fromFile($file, $this->deliveryPolicies->for($file->groupName));
        $inline = !$options->forceDownload && !$this->activeMediaTypes->contains($options->responseMediaType);

        $response = $this->downloads->create(
            file: $file,
            store: $store,
            stream: $stream,
            options: $options,
            inline: $inline,
            rangeHeader: $this->rangeHeader($request, $etag),
        );

        return $this->validators($response, $etag, $file)
            // Without this a browser is free to sniff a text/plain body, decide
            // it is HTML and execute it — which undoes the attachment above.
            ->withHeader(Header::X_CONTENT_TYPE_OPTIONS, 'nosniff');
    }

    /**
     * A validator that changes whenever the bytes could have.
     *
     * The content hash is the honest answer when there is one. Without it the
     * fallback is the triple that changes on any rewrite this package performs:
     * id, size and `updatedAt`. Weak, in the HTTP sense, because two files with
     * the same three are not provably byte-identical — and a weak validator is
     * still enough for `If-None-Match`, which is what it is used for.
     *
     * @return non-empty-string
     */
    private function etag(File $file): string
    {
        $source = $file->contentHash ?? ($file->id . '|' . $file->size . '|' . $file->updatedAt->format('U.u'));

        return '"' . hash('xxh128', $source) . '"';
    }

    private function isCurrent(ServerRequestInterface $request, string $etag, DateTimeImmutable $updatedAt): bool
    {
        $ifNoneMatch = trim($request->getHeaderLine(Header::IF_NONE_MATCH));
        if ($ifNoneMatch !== '') {
            // `If-None-Match` wins outright when present: RFC 9110 says a
            // recipient must ignore If-Modified-Since if the request has one.
            if ($ifNoneMatch === '*') {
                return true;
            }

            foreach (explode(',', $ifNoneMatch) as $candidate) {
                // W/ prefixes compare weakly, which is the right comparison for
                // a conditional GET.
                if (ltrim(trim($candidate), 'W/') === $etag) {
                    return true;
                }
            }

            return false;
        }

        $ifModifiedSince = trim($request->getHeaderLine(Header::IF_MODIFIED_SINCE));
        if ($ifModifiedSince === '') {
            return false;
        }

        $since = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $ifModifiedSince, new DateTimeZone('GMT'));

        // HTTP dates have second precision, so a file modified within the same
        // second as the client's copy compares equal and is not resent.
        return $since !== false && $updatedAt->getTimestamp() <= $since->getTimestamp();
    }

    /**
     * The `Range` to honour, or null to send the whole thing.
     *
     * `If-Range` is the reason this is not just `getHeaderLine('Range')`: a
     * client resuming a download sends the validator it started with, and if
     * the file has changed since, continuing from byte 40000 would splice two
     * different files together. A mismatch means send the whole current one.
     */
    private function rangeHeader(ServerRequestInterface $request, string $etag): ?string
    {
        $range = trim($request->getHeaderLine(Header::RANGE));
        if ($range === '') {
            return null;
        }

        $ifRange = trim($request->getHeaderLine(Header::IF_RANGE));

        return $ifRange === '' || $ifRange === $etag ? $range : null;
    }

    private function validators(ResponseInterface $response, string $etag, File $file): ResponseInterface
    {
        return $response
            ->withHeader(Header::ETAG, $etag)
            ->withHeader(
                Header::LAST_MODIFIED,
                $file->updatedAt->setTimezone(new DateTimeZone('GMT'))->format(self::HTTP_DATE),
            )
            ->withHeader(Header::CACHE_CONTROL, $this->cacheControl);
    }

    private function notFound(): ResponseInterface
    {
        return $this->responses->createResponse(Status::NOT_FOUND);
    }
}
