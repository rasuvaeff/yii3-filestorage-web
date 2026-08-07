<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;

/**
 * Resolves by (id, scope) and by nothing else, like the real one.
 *
 * @internal
 */
final class InMemoryResolver implements ScopedFileResolverInterface
{
    /** @var array<string, array{File, string|null}> */
    private array $files = [];

    public function add(File $file, ?string $scopeId = null): void
    {
        $this->files[$file->id] = [$file, $scopeId];
    }

    #[Override]
    public function findInScope(string $id, ?string $scopeId): ?File
    {
        [$file, $owner] = $this->files[$id] ?? [null, null];

        return $file instanceof \Rasuvaeff\Yii3Filestorage\File && $owner === $scopeId ? $file : null;
    }
}
