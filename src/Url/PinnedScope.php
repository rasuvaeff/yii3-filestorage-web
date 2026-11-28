<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Url;

use Override;
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;

/**
 * The scope {@see HmacProxyUrlGenerator::forScope()} was told, whatever the
 * request says.
 *
 * @internal
 */
final readonly class PinnedScope implements FileScopeProviderInterface
{
    /**
     * @param non-empty-string|null $scopeId
     */
    public function __construct(
        private ?string $scopeId,
    ) {}

    #[Override]
    public function currentScopeId(): ?string
    {
        return $this->scopeId;
    }
}
