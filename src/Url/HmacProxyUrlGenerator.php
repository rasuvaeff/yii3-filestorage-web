<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Url;

use DateTimeImmutable;
use InvalidArgumentException;
use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Url\SignedPayload;
use Rasuvaeff\Yii3Filestorage\Url\UrlSignerInterface;

/**
 * A download URL for a file the object store cannot hand out itself.
 *
 * This is the fallback `Storage::urlFor()` reaches when a store has no
 * presigned URL, or has one that cannot carry the group's delivery policy: a
 * route through the application, authenticated by an HMAC token rather than by
 * a session, so it works in an email, an `<img src>` or a background job.
 *
 * The scope is stamped in at minting time, from the request that is asking. A
 * token minted for one tenant therefore cannot resolve another's file even if
 * the id leaks — {@see \Rasuvaeff\Yii3FilestorageWeb\Action\FileDownloadAction}
 * matches both, and never looks a file up by id alone.
 *
 * Outside a request — a queued email, a report job — there is usually no
 * current tenant: the provider answers null, the token says "unscoped", and
 * the download then looks the tenant's file up under `scope IS NULL` and
 * answers 404. Nothing leaks, but the link is dead. Such code names the tenant
 * with {@see self::forScope()}, or enters the tenant's context so the provider
 * answers it; `File` does not carry its scope, so this class cannot take it
 * from the file.
 *
 * @api
 */
final readonly class HmacProxyUrlGenerator implements ProxyUrlGeneratorInterface
{
    private const string TOKEN_PLACEHOLDER = '{token}';

    /** @var non-empty-string Contains {@see TOKEN_PLACEHOLDER}. */
    private string $route;

    /**
     * @param string $route An absolute path or URL containing `{token}`, e.g.
     *        `/files/{token}` or `https://app.example.com/files/{token}`.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        private UrlSignerInterface $signer,
        string $route = '/files/' . self::TOKEN_PLACEHOLDER,
        private ?FileScopeProviderInterface $scopes = null,
    ) {
        if ($route === '' || !str_contains($route, self::TOKEN_PLACEHOLDER)) {
            throw new InvalidArgumentException(
                "Download route \"{$route}\" must contain the " . self::TOKEN_PLACEHOLDER
                . ' placeholder — that is where the signed token goes',
            );
        }

        // The early return above guarantees a non-empty route, so `url()`
        // can promise a non-empty result.
        $this->route = $route;
    }

    /**
     * A copy that stamps `$scopeId` into every token, ignoring the provider —
     * for links minted where no request names the tenant. `null` is an
     * explicit "unscoped": the link resolves only files stored without a
     * scope.
     */
    public function forScope(?string $scopeId): self
    {
        return new self(
            signer: $this->signer,
            route: $this->route,
            scopes: new PinnedScope($scopeId),
        );
    }

    #[Override]
    public function url(File $file, DateTimeImmutable $expiresAt): string
    {
        return $this->buildUrl(
            payload: new SignedPayload(fileId: $file->id, scopeId: $this->scopes?->currentScopeId()),
            expiresAt: $expiresAt,
        );
    }

    #[Override]
    public function variantUrl(File $file, string $variant, DateTimeImmutable $expiresAt): string
    {
        return $this->buildUrl(
            payload: new SignedPayload(
                fileId: $file->id,
                variant: $variant,
                scopeId: $this->scopes?->currentScopeId(),
            ),
            expiresAt: $expiresAt,
        );
    }

    /**
     * @return non-empty-string
     */
    private function buildUrl(SignedPayload $payload, DateTimeImmutable $expiresAt): string
    {
        $token = $this->signer->sign(payload: $payload, expiresAt: $expiresAt);

        // The token is base64url plus dots by construction, so nothing here
        // needs escaping — but going through rawurlencode() anyway would break
        // it, since the dots are separators the verifier splits on.
        $url = str_replace(self::TOKEN_PLACEHOLDER, $token, $this->route);
        \assert($url !== '');

        return $url;
    }
}
