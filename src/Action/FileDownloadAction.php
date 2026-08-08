<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Action;

use DateTimeImmutable;
use DateTimeZone;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Filestorage\Exception\FilestorageException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Url\SignedPayload;
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
        try {
            return $this->serve($request);
        } catch (FilestorageException|\InvalidArgumentException) {
            // Golden rule 3 says everything that fails answers 404, and until
            // now that held only for collaborators returning null. Three throw
            // instead: StoreRegistry::get() for a storeName configuration no
            // longer has — old rows keep the old name — the resolver for a row
            // its mapper cannot read, and withHeader() for a media type a
            // strict PSR-7 implementation refuses. Each was a 500 where the
            // contract promises 404, and a 500 on a download route is also a
            // fingerprint: it distinguishes "this id exists but something is
            // wrong with it" from "no such id", which the opaque token exists
            // to withhold.
            return $this->notFound();
        }
    }

    private function serve(ServerRequestInterface $request): ResponseInterface
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
        if (!$payload instanceof SignedPayload || $payload->variant !== null) {
            return $this->notFound();
        }

        $file = $this->files->findInScope($payload->fileId, $payload->scopeId);
        if (!$file instanceof File) {
            return $this->notFound();
        }

        // The delivery decision is made *before* the validator, because the
        // validator has to depend on it. Bytes alone are not what a cache
        // stores: it stores the response, disposition included.
        $options = DeliveryOptions::fromFile($file, $this->deliveryPolicies->for($file->groupName));
        $inline = !$options->forceDownload && !$this->activeMediaTypes->contains($options->responseMediaType);

        $etag = $this->etag($file, $options, $inline);
        if ($this->isCurrent($request, $etag, $file->updatedAt)) {
            return $this->validators($this->responses->createResponse(Status::NOT_MODIFIED), $etag, $file);
        }

        $store = $this->stores->get($file->storeName);
        $stream = $store->stream($file);
        if (!$stream instanceof StreamInterface) {
            // A row pointing at bytes that are gone. Nothing to serve, and
            // nothing the client can do about it either.
            return $this->notFound();
        }

        $response = $this->downloads->create(
            file: $file,
            store: $store,
            stream: $stream,
            options: $options,
            inline: $inline,
            rangeHeader: $this->rangeHeader($request, $etag, $file->updatedAt),
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
     * The disposition is part of it, and that is not decoration. A client can
     * hold a cached 200 carrying `Content-Disposition: inline` for an SVG; the
     * operator then closes the hole by adding the type to
     * `extraActiveMediaTypes` or flipping the group to `forceDownload`. The
     * bytes did not change, so a validator built from bytes alone still
     * matches, the revalidation returns 304 — and RFC 9111 has the cache update
     * its stored headers from that 304, which carries no `Content-Disposition`
     * at all (RFC 9110 forbids `Content-*` there). The stored `inline` would
     * survive indefinitely: the stored XSS this class exists to prevent, one
     * day late. Folding the decision into the validator is what breaks that.
     *
     * @return non-empty-string
     */
    private function etag(File $file, DeliveryOptions $options, bool $inline): string
    {
        $strong = $file->contentHash !== null;
        $source = $file->contentHash ?? ($file->id . '|' . $file->size . '|' . $file->updatedAt->format('U.u'));
        $source .= '|' . ($inline ? 'inline' : 'attachment') . '|' . $options->responseMediaType;

        // Marked weak when it is weak. The fallback triple does not prove two
        // responses are byte-identical — an in-place rewrite that preserves
        // updatedAt leaves it unchanged — and RFC 9110 allows a weak validator
        // for If-None-Match but not for If-Range. Emitting it unmarked claimed
        // a strength it does not have, and a resuming client would then splice
        // an old prefix onto a new suffix.
        return ($strong ? '' : 'W/') . '"' . hash('xxh128', $source) . '"';
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

            // Weak comparison, which is the right one for a conditional GET —
            // and it has to strip *both* sides now that this class marks its
            // own fallback validator weak. Stripping only the client's turned
            // every 304 into a 200 the moment ours grew a `W/`.
            $ours = self::withoutWeakness($etag);
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                if (self::withoutWeakness(trim($candidate)) === $ours) {
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
    /**
     * `If-Range` is answered only by a strong validator, and understands both
     * forms the specification allows.
     *
     * A weak ETag — the fallback triple — cannot answer it: two responses
     * sharing that triple are not provably the same bytes, and splicing a
     * resumed suffix onto a stale prefix is the corruption the header exists to
     * prevent. The date form matters in practice: download managers and
     * curl/wget resume flows send it, and treating it as a non-match turned
     * every resume into a silent full re-download.
     */
    private function rangeHeader(
        ServerRequestInterface $request,
        string $etag,
        DateTimeImmutable $updatedAt,
    ): ?string {
        $range = trim($request->getHeaderLine(Header::RANGE));
        if ($range === '') {
            return null;
        }

        $ifRange = trim($request->getHeaderLine(Header::IF_RANGE));
        if ($ifRange === '') {
            return $range;
        }

        if (str_starts_with($ifRange, '"')) {
            // Strong comparison, and a weak ETag of ours can never match it.
            return $ifRange === $etag && !str_starts_with($etag, 'W/') ? $range : null;
        }

        $asDate = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $ifRange, new DateTimeZone('GMT'));

        return $asDate !== false && $asDate->getTimestamp() === $updatedAt->getTimestamp() ? $range : null;
    }

    /**
     * Drops a `W/` prefix, and only a prefix.
     *
     * `ltrim($value, 'W/')` is a character-class strip: it would eat every
     * leading `W` and `/` it found. Nothing this class emits starts with either
     * once the prefix is gone, but a client's header is not this class's to
     * assume things about.
     */
    private static function withoutWeakness(string $etag): string
    {
        return str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
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

    /**
     * Never cached. A 404 is heuristically cacheable, and two of the paths here
     * are transient — a store outage, a row a mapper could not read — so an
     * intermediary could pin "gone" onto a token URL that stays valid for the
     * rest of its life.
     */
    private function notFound(): ResponseInterface
    {
        return $this->responses
            ->createResponse(Status::NOT_FOUND)
            ->withHeader(Header::CACHE_CONTROL, 'no-store');
    }
}
