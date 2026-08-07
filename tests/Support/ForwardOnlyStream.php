<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Support;

use Override;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * A PSR-7 stream that cannot seek — what a chunked request body or a network
 * response actually looks like, and the input `Upload` has to spool.
 *
 * @internal
 */
class ForwardOnlyStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(private readonly string $body) {}

    #[Override]
    public function __toString(): string
    {
        return $this->getContents();
    }

    #[Override]
    public function close(): void {}

    #[Override]
    public function detach()
    {
        return null;
    }

    #[Override]
    public function getSize(): ?int
    {
        return \strlen($this->body);
    }

    #[Override]
    public function tell(): int
    {
        return $this->position;
    }

    #[Override]
    public function eof(): bool
    {
        return $this->position >= \strlen($this->body);
    }

    #[Override]
    public function isSeekable(): bool
    {
        return false;
    }

    #[Override]
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new RuntimeException('Stream is not seekable');
    }

    #[Override]
    public function rewind(): void
    {
        throw new RuntimeException('Stream is not seekable');
    }

    #[Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[Override]
    public function write(string $string): int
    {
        throw new RuntimeException('Stream is not writable');
    }

    #[Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[Override]
    public function read(int $length): string
    {
        $chunk = substr($this->body, $this->position, max(0, $length));
        $this->position += \strlen($chunk);

        return $chunk;
    }

    #[Override]
    public function getContents(): string
    {
        return $this->read(\strlen($this->body) - $this->position);
    }

    #[Override]
    public function getMetadata(?string $key = null)
    {
        return $key === null ? [] : null;
    }
}
