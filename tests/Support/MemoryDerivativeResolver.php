<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Support;

use Override;
use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Exception\FilestorageException;
use Rasuvaeff\Yii3Filestorage\Exception\UnresolvableVariantException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeDescriptor;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeResolverInterface;

/**
 * A resolver standing in for what `-images`' `Derivatives` provides.
 *
 * Configured through the constructor so every test states its own setup: the
 * preset whitelist, what each rendition's bytes are, and whether generating
 * fails.
 *
 * @internal
 */
final class MemoryDerivativeResolver implements DerivativeResolverInterface
{
    /**
     * @param array<string, array{extension: non-empty-string, mediaType: non-empty-string}> $presets
     * @param array<string, non-empty-string> $bodies Keyed `"fileId/variant"`.
     */
    public function __construct(
        private readonly array $presets = [],
        private readonly array $bodies = [],
        private readonly ?FilestorageException $streamFailure = null,
    ) {}

    #[Override]
    public function stream(File $file, string $presetName): ?StreamInterface
    {
        if ($this->streamFailure !== null) {
            throw $this->streamFailure;
        }

        $body = $this->bodies["{$file->id}/{$presetName}"] ?? null;
        if ($body === null) {
            return null;
        }

        return Fixtures::factory()->createStream($body);
    }

    #[Override]
    public function descriptor(string $presetName): DerivativeDescriptor
    {
        $preset = $this->presets[$presetName] ?? null;
        if ($preset === null) {
            throw new UnresolvableVariantException("Unknown preset \"{$presetName}\"");
        }

        return new DerivativeDescriptor(
            name: $presetName,
            extension: $preset['extension'],
            mediaType: $preset['mediaType'],
        );
    }
}
