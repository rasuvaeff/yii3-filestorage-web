<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Support;

use DateTimeImmutable;
use Override;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Store\RangeReadableStoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreResult;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * A store with a real range primitive and a forward-only body.
 *
 * Exactly the combination that separates asking the store from windowing the
 * stream: `InMemoryStore` hands out a seekable body, so wrapping it is the only
 * way to reach the branch that matters.
 *
 * @internal
 */
final class RangeReadableStore implements RangeReadableStoreInterface
{
    public int $rangeCalls = 0;

    public function __construct(
        private readonly InMemoryStore $inner,
        private readonly StreamFactoryInterface $streams,
        private readonly bool $declines = false,
    ) {}

    #[Override]
    public function streamRange(File $file, int $offset, int $length): ?StreamInterface
    {
        $this->rangeCalls++;
        if ($this->declines) {
            return null;
        }

        $bytes = (string) $this->inner->stream($file)?->getContents();

        return $this->streams->createStream(substr($bytes, $offset, $length));
    }

    #[Override]
    public function name(): string
    {
        return $this->inner->name();
    }

    #[Override]
    public function write(
        Upload $upload,
        string $groupName,
        PathGeneratorInterface $pathGenerator,
        ?string $mediaType = null,
        int $maxBytes = 0,
    ): StoreResult {
        return $this->inner->write($upload, $groupName, $pathGenerator, $mediaType, $maxBytes);
    }

    #[Override]
    public function delete(File $file): void
    {
        $this->inner->delete($file);
    }

    #[Override]
    public function exists(File $file): bool
    {
        return $this->inner->exists($file);
    }

    #[Override]
    public function size(File $file): ?int
    {
        return $this->inner->size($file);
    }

    #[Override]
    public function lastModified(File $file): ?DateTimeImmutable
    {
        return $this->inner->lastModified($file);
    }

    #[Override]
    public function stream(File $file): ?StreamInterface
    {
        return $this->inner->stream($file);
    }
}
