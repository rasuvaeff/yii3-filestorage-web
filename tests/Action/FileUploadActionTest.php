<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Action;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Filestorage\Exception\ContentTooLargeException;
use Rasuvaeff\Yii3Filestorage\Exception\PolicyViolationException;
use Rasuvaeff\Yii3Filestorage\Exception\UnsupportedMediaTypeException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadSizeException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3FilestorageWeb\Action\FileUploadAction;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\Fixtures;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(FileUploadAction::class)]
final class FileUploadActionTest
{
    public function storesTheUploadAndAnswers201(): void
    {
        $action = $this->action();

        $response = $action->handle($this->request($this->upload('hello world')));

        Assert::same($response->getStatusCode(), 201);
        Assert::same($response->getHeaderLine('Content-Type'), 'application/json');
        // Every field is asserted on its own: a missing one must fail the
        // test, not slip through a whole-object comparison.
        Assert::json((string) $response->getBody())
            ->isObject()
            ->hasKeys('id', 'originalName', 'size', 'mimeType')
            ->assertPath('$.id', fn(mixed $id): bool => $id === 'file-1')
            ->assertPath('$.originalName', fn(mixed $n): bool => $n === 'report.txt')
            ->assertPath('$.size', fn(mixed $s): bool => $s === 11)
            ->assertPath('$.mimeType', fn(mixed $t): bool => $t === 'text/plain');
    }

    /**
     * A slash in the stored name, unescaped in the raw body — the JSON flags
     * are part of the response's shape, and a regression to escaped slashes
     * changes what byte-for-byte comparisons against this body see.
     */
    public function theBodyIsCompactJson(): void
    {
        $factory = new Psr17Factory();
        $storage = Understudy::for(StorageInterface::class);
        when(static fn(): File => $storage->add(Arg::any(), Arg::any()))->answers(
            static fn(): File => Fixtures::file(originalName: 'dir/name.txt'),
        );
        $action = new FileUploadAction($storage, $factory, $factory);

        $body = (string) $action->handle($this->request($this->upload('x')))->getBody();

        Assert::string($body)->contains('dir/name.txt');
        Assert::false((bool) str_contains($body, '\\/'));
    }

    /** The group is the action's configuration, not the client's choice. */
    public function theGroupAndTheUploadTravelTogether(): void
    {
        $factory = new Psr17Factory();
        $storage = Understudy::for(StorageInterface::class);
        when(static fn(): File => $storage->add(Arg::any(), Arg::any()))->answers(
            static fn(): File => Fixtures::file(),
        );
        $action = new FileUploadAction($storage, $factory, $factory, groupName: 'avatars');

        $action->handle($this->request($this->upload('x')));

        $call = Understudy::lastCall(static fn(): File => $storage->add(Arg::any(), Arg::any()));
        Assert::same($call?->args[1] ?? null, 'avatars');
    }

    public function aMissingKeyAnswers400(): void
    {
        $response = $this->action()->handle(new ServerRequest('POST', '/upload'));

        Assert::same($response->getStatusCode(), 400);
        Assert::json((string) $response->getBody())->isObject()->hasKeys('error');
    }

    /** A nested array is a multi-upload; this action refuses rather than half-processes. */
    public function aMultiUploadAnswers400(): void
    {
        $request = (new ServerRequest('POST', '/upload'))->withUploadedFiles([
            'file' => [$this->upload('a'), $this->upload('b')],
        ]);

        $response = $this->action()->handle($request);

        Assert::same($response->getStatusCode(), 400);
    }

    #[DataProvider('phpSizeErrorProvider')]
    public function phpSizeErrorsAnswer413(int $code): void
    {
        $response = $this->action()->handle($this->request($this->upload('x', error: $code)));

        Assert::same($response->getStatusCode(), 413);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function phpSizeErrorProvider(): iterable
    {
        yield 'php ini size' => [\UPLOAD_ERR_INI_SIZE];
        yield 'form max file size' => [\UPLOAD_ERR_FORM_SIZE];
    }

    #[DataProvider('phpClientErrorProvider')]
    public function phpClientErrorsAnswer400(int $code): void
    {
        $response = $this->action()->handle($this->request($this->upload('x', error: $code)));

        Assert::same($response->getStatusCode(), 400);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function phpClientErrorProvider(): iterable
    {
        yield 'no file' => [\UPLOAD_ERR_NO_FILE];
        yield 'partial upload' => [\UPLOAD_ERR_PARTIAL];
        yield 'extension refused it' => [\UPLOAD_ERR_EXTENSION];
    }

    /**
     * The two PHP errors no client can fix — a missing or unwritable tmp dir —
     * must not send the client hunting for a bug it does not have.
     */
    #[DataProvider('phpServerErrorProvider')]
    public function phpServerErrorsAnswer500(int $code): void
    {
        $response = $this->action()->handle($this->request($this->upload('x', error: $code)));

        Assert::same($response->getStatusCode(), 500);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function phpServerErrorProvider(): iterable
    {
        yield 'no tmp dir' => [\UPLOAD_ERR_NO_TMP_DIR];
        yield 'cannot write' => [\UPLOAD_ERR_CANT_WRITE];
    }

    public function aTypeRefusedByTheGroupAnswers415(): void
    {
        $action = $this->action(failingWith: new UnsupportedMediaTypeException(
            'Media type text/plain is not accepted here. Allowed: image/png',
        ));

        $response = $action->handle($this->request($this->upload('x')));

        Assert::same($response->getStatusCode(), 415);
        $body = (string) $response->getBody();
        Assert::json($body)->assertPath(
            '$.error',
            fn(mixed $m): bool => $m === 'Media type text/plain is not accepted here. Allowed: image/png',
        );
        // The media types carry slashes — unescaped in the raw body.
        Assert::string($body)->contains('image/png');
    }

    #[DataProvider('sizeRefusalProvider')]
    public function sizeRefusalsAnswer413(\RuntimeException $exception): void
    {
        $action = $this->action(failingWith: $exception);

        $response = $action->handle($this->request($this->upload('x')));

        Assert::same($response->getStatusCode(), 413);
    }

    /**
     * @return iterable<string, array{\RuntimeException}>
     */
    public static function sizeRefusalProvider(): iterable
    {
        yield 'policy size limit' => [new UploadSizeException('Upload is 5 bytes, over the 4 byte limit for this group')];
        yield 'spool cap' => [new UploadTooLargeException('Upload would spool over the cap')];
        yield 'write-time race over the cap' => [new ContentTooLargeException('Store refused the write')];
    }

    /** Pixel caps and future accept rules: the client's file is wrong, not the server. */
    public function otherPolicyViolationsAnswer400(): void
    {
        $action = $this->action(failingWith: new PolicyViolationException('Image is over the pixel limit'));

        $response = $action->handle($this->request($this->upload('x')));

        Assert::same($response->getStatusCode(), 400);
    }

    private function action(?\RuntimeException $failingWith = null): FileUploadAction
    {
        $factory = new Psr17Factory();
        $storage = Understudy::for(StorageInterface::class);

        if ($failingWith !== null) {
            when(static fn(): File => $storage->add(Arg::any(), Arg::any()))->throws($failingWith);
        } else {
            when(static fn(): File => $storage->add(Arg::any(), Arg::any()))->answers(
                static fn(): File => Fixtures::file(originalName: 'report.txt'),
            );
        }

        return new FileUploadAction(
            storage: $storage,
            responses: $factory,
            streams: $factory,
            groupName: 'avatars',
        );
    }

    private function request(UploadedFileInterface $upload): ServerRequest
    {
        return (new ServerRequest('POST', '/upload'))->withUploadedFiles(['file' => $upload]);
    }

    private function upload(string $body, int $error = \UPLOAD_ERR_OK): UploadedFileInterface
    {
        $factory = new Psr17Factory();

        return new UploadedFile(
            $factory->createStream($body),
            strlen($body),
            $error,
            'report.txt',
            'text/plain',
        );
    }
}
