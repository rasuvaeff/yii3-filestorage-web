<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\Exception\StoreException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;

/**
 * A resolver whose backing store is down, or whose row its mapper cannot read.
 *
 * @internal
 */
final class ThrowingResolver implements ScopedFileResolverInterface
{
    #[Override]
    public function findInScope(string $id, ?string $scopeId): ?File
    {
        throw new StoreException('the row cannot be read');
    }
}
