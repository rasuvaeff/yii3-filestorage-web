<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Validation;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;
use Rasuvaeff\Yii3FilestorageWeb\Validation\FileRule;
use Rasuvaeff\Yii3FilestorageWeb\Validation\FileRuleHandler;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;
use Yiisoft\Validator\ValidationContext;

#[Test]
#[Covers(FileRuleHandler::class)]
final class FileRuleHandlerTest
{
    public function acceptsACleanUpload(): void
    {
        Assert::true($this->validate($this->upload('hello'))->isValid());
    }

    public function anythingThatIsNotAnUploadedFileIsRejected(): void
    {
        $result = $this->validate('report.txt');

        Assert::false($result->isValid());
        Assert::same($result->getErrors()[0]->getMessage(), 'The value must be an uploaded file');
        Assert::same($result->getErrors()[0]->getParameters(), ['property' => 'value', 'type' => 'string']);
    }

    /**
     * PHP's own size verdicts surface as the size message — the client gets
     * one answer for "too large", not two.
     */
    #[DataProvider('phpSizeErrorProvider')]
    public function phpSizeErrorsSayTooLarge(int $code): void
    {
        $rule = new FileRule();

        $result = $this->handler()->validate($this->upload('x', error: $code), $rule, $this->context());

        Assert::false($result->isValid());
        Assert::same($result->getErrors()[0]->getMessage(), 'The file is too large');
        Assert::same($result->getErrors()[0]->getParameters(), ['property' => 'value']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function phpSizeErrorProvider(): iterable
    {
        yield 'php ini size' => [\UPLOAD_ERR_INI_SIZE];
        yield 'form max file size' => [\UPLOAD_ERR_FORM_SIZE];
    }

    public function otherPhpErrorsSayUploadFailed(): void
    {
        $rule = new FileRule();

        $result = $this->handler()->validate($this->upload('x', error: \UPLOAD_ERR_PARTIAL), $rule, $this->context());

        Assert::false($result->isValid());
        Assert::same($result->getErrors()[0]->getMessage(), 'The upload failed');
        Assert::same($result->getErrors()[0]->getParameters(), ['property' => 'value', 'code' => \UPLOAD_ERR_PARTIAL]);
    }

    public function aSizeOverTheLimitIsRejectedBeforeBytesAreRead(): void
    {
        $rule = new FileRule(maxBytes: 4);

        $result = $this->handler()->validate($this->upload('xxxxx'), $rule, $this->context());

        Assert::false($result->isValid());
        Assert::same($result->getErrors()[0]->getMessage(), 'The file is too large');
        Assert::same($result->getErrors()[0]->getParameters(), ['property' => 'value']);
    }

    public function theSizeLimitIsInclusive(): void
    {
        $rule = new FileRule(maxBytes: 5);

        Assert::true($this->handler()->validate($this->upload('xxxxx'), $rule, $this->context())->isValid());
    }

    public function aZeroLimitDisablesTheCheck(): void
    {
        $rule = new FileRule(maxBytes: 0);

        Assert::true(
            $this->handler()->validate($this->upload(str_repeat('x', 100)), $rule, $this->context())->isValid(),
        );
    }

    /**
     * The client's `Content-Type` is a hint and is treated as one — but a
     * whitelist that does not include it still fails the form early, which is
     * better UX than failing after the whole body has been uploaded.
     */
    public function aClientTypeOutsideTheWhitelistIsRejected(): void
    {
        $rule = new FileRule(allowedMimeTypes: ['image/png', 'image/jpeg']);

        $result = $this->handler()->validate($this->upload('x', clientType: 'text/plain'), $rule, $this->context());

        Assert::false($result->isValid());
        Assert::same($result->getErrors()[0]->getMessage(), 'The file type is not accepted');
        Assert::same($result->getErrors()[0]->getParameters(), ['property' => 'value']);
    }

    public function aMissingClientTypeIsRejectedUnderAWhitelist(): void
    {
        $rule = new FileRule(allowedMimeTypes: ['image/png']);

        $result = $this->handler()->validate($this->upload('x', clientType: null), $rule, $this->context());

        Assert::false($result->isValid());
    }

    public function anEmptyWhitelistAcceptsAnyType(): void
    {
        $rule = new FileRule();

        Assert::true(
            $this->handler()->validate($this->upload('x', clientType: 'application/x-any'), $rule, $this->context())->isValid(),
        );
    }

    private function handler(): FileRuleHandler
    {
        return new FileRuleHandler();
    }

    private function context(): ValidationContext
    {
        return new ValidationContext();
    }

    private function validate(mixed $value): \Yiisoft\Validator\Result
    {
        return $this->handler()->validate($value, new FileRule(), $this->context());
    }

    private function upload(
        string $body,
        int $error = \UPLOAD_ERR_OK,
        ?string $clientType = 'text/plain',
    ): UploadedFileInterface {
        $factory = new Psr17Factory();

        return new UploadedFile(
            $factory->createStream($body),
            strlen($body),
            $error,
            'report.txt',
            $clientType,
        );
    }
}
