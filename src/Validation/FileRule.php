<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Validation;

use Attribute;
use Closure;
use Override;
use Yiisoft\Validator\DumpedRuleInterface;
use Yiisoft\Validator\Rule\Trait\SkipOnEmptyTrait;
use Yiisoft\Validator\Rule\Trait\SkipOnErrorTrait;
use Yiisoft\Validator\Rule\Trait\WhenTrait;
use Yiisoft\Validator\SkipOnEmptyInterface;
use Yiisoft\Validator\SkipOnErrorInterface;
use Yiisoft\Validator\WhenInterface;

/**
 * Validates a PSR-7 uploaded file for a storage group: the PHP upload
 * verdict, the size limit, and the client-declared media type.
 *
 * A rule, not storage: it answers "would the storage group accept this
 * upload" before any byte is read, so a form fails fast and the error lands
 * next to the field. The authoritative checks still run in
 * `Storage::add()` — the client's `Content-Type` is a hint and is treated as
 * one here, documented rather than trusted.
 *
 * @see FileRuleHandler The handler performing the validation.
 *
 * @psalm-import-type SkipOnEmptyValue from SkipOnEmptyInterface
 * @psalm-import-type WhenType from WhenInterface
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
final class FileRule implements DumpedRuleInterface, SkipOnEmptyInterface, SkipOnErrorInterface, WhenInterface
{
    use SkipOnEmptyTrait;
    use SkipOnErrorTrait;
    use WhenTrait;

    /**
     * @param list<non-empty-string> $allowedMimeTypes Empty means "any" — the
     *        storage group's policy decides.
     * @param int $maxBytes Over this, the upload is refused before its bytes
     *        are read. Zero means no rule-level limit.
     * @param bool|callable|null $skipOnEmpty
     * @param string $incorrectInputMessage Error message for a value that is
     *        not an uploaded file at all.
     * @param string $tooLargeMessage Error message for the sizes — PHP's own
     *        limit or this rule's.
     * @param string $wrongTypeMessage Error message for a client-declared
     *        media type outside {@see $allowedMimeTypes}.
     * @param string $uploadErrorMessage Error message for the remaining PHP
     *        upload errors (partial file, missing tmp dir, …).
     * @psalm-param SkipOnEmptyValue $skipOnEmpty
     * @psalm-param WhenType|null $when
     */
    public function __construct(
        public readonly array $allowedMimeTypes = [],
        public readonly int $maxBytes = 0,
        bool|callable|null $skipOnEmpty = null,
        private readonly bool $skipOnError = false,
        private readonly ?Closure $when = null,
        private readonly string $incorrectInputMessage = 'The value must be an uploaded file',
        private readonly string $tooLargeMessage = 'The file is too large',
        private readonly string $wrongTypeMessage = 'The file type is not accepted',
        private readonly string $uploadErrorMessage = 'The upload failed',
    ) {
        $this->skipOnEmpty = $skipOnEmpty;
    }

    #[Override]
    public function getName(): string
    {
        return self::class;
    }

    public function getIncorrectInputMessage(): string
    {
        return $this->incorrectInputMessage;
    }

    public function getTooLargeMessage(): string
    {
        return $this->tooLargeMessage;
    }

    public function getWrongTypeMessage(): string
    {
        return $this->wrongTypeMessage;
    }

    public function getUploadErrorMessage(): string
    {
        return $this->uploadErrorMessage;
    }

    #[Override]
    public function getOptions(): array
    {
        return [
            'allowedMimeTypes' => $this->allowedMimeTypes,
            'maxBytes' => $this->maxBytes,
            'skipOnEmpty' => $this->skipOnEmpty,
            'skipOnError' => $this->skipOnError,
        ];
    }

    #[Override]
    public function getHandler(): string
    {
        return FileRuleHandler::class;
    }
}
