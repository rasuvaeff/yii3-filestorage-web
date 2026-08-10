<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\Path\PathGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Upload;

/**
 * Puts the object exactly where the fixture `File` says it is.
 *
 * @internal
 */
final readonly class FixedPath implements PathGeneratorInterface
{
    public function __construct(private string $path) {}

    #[Override]
    public function generate(string $groupName, Upload $upload, ?string $mediaType): string
    {
        return $this->path;
    }
}
