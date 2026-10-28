<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Validation;

use Override;
use Psr\Http\Message\UploadedFileInterface;
use Yiisoft\Validator\Exception\UnexpectedRuleException;
use Yiisoft\Validator\Result;
use Yiisoft\Validator\RuleHandlerInterface;
use Yiisoft\Validator\RuleInterface;
use Yiisoft\Validator\ValidationContext;

/**
 * A handler for {@see FileRule}.
 */
final class FileRuleHandler implements RuleHandlerInterface
{
    #[Override]
    public function validate(mixed $value, RuleInterface $rule, ValidationContext $context): Result
    {
        if (!$rule instanceof FileRule) {
            throw new UnexpectedRuleException(FileRule::class, $rule);
        }

        if (!$value instanceof UploadedFileInterface) {
            return (new Result())->addError($rule->getIncorrectInputMessage(), [
                'property' => $context->getTranslatedProperty(),
                'type' => get_debug_type($value),
            ]);
        }

        $error = $value->getError();
        if ($error !== \UPLOAD_ERR_OK) {
            // PHP already knows the size limits it enforces itself; the rule's
            // own limit would repeat the same verdict for the same bytes.
            return $error === \UPLOAD_ERR_INI_SIZE || $error === \UPLOAD_ERR_FORM_SIZE
                ? (new Result())->addError($rule->getTooLargeMessage(), [
                    'property' => $context->getTranslatedProperty(),
                ])
                : (new Result())->addError($rule->getUploadErrorMessage(), [
                    'property' => $context->getTranslatedProperty(),
                    'code' => $error,
                ]);
        }

        if ($rule->maxBytes > 0 && ($value->getSize() ?? 0) > $rule->maxBytes) {
            return (new Result())->addError($rule->getTooLargeMessage(), [
                'property' => $context->getTranslatedProperty(),
            ]);
        }

        if ($rule->allowedMimeTypes !== []) {
            $clientType = $value->getClientMediaType();
            if ($clientType === null || !in_array($clientType, $rule->allowedMimeTypes, strict: true)) {
                return (new Result())->addError($rule->getWrongTypeMessage(), [
                    'property' => $context->getTranslatedProperty(),
                ]);
            }
        }

        return new Result();
    }
}
