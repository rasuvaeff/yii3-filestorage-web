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

        // `str_contains` above guarantees this, but the property carries the
        // narrow type so `url()` can promise a non-empty result.
        \assert($route !== '');
        $this->route = $route;
    }

    #[Override]
    public function url(File $file, DateTimeImmutable $expiresAt): string
    {
        $token = $this->signer->sign(
            payload: new SignedPayload(fileId: $file->id, scopeId: $this->scopes?->currentScopeId()),
            expiresAt: $expiresAt,
        );

        // The token is base64url plus dots by construction, so nothing here
        // needs escaping — but going through rawurlencode() anyway would break
        // it, since the dots are separators the verifier splits on.
        $url = str_replace(self::TOKEN_PLACEHOLDER, $token, $this->route);
        \assert($url !== '');

        return $url;
    }
}
