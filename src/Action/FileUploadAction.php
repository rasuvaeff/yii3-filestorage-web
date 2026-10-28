<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Action;

use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Yii3Filestorage\Exception\ContentTooLargeException;
use Rasuvaeff\Yii3Filestorage\Exception\UnsupportedMediaTypeException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadSizeException;
use Rasuvaeff\Yii3Filestorage\Exception\UploadTooLargeException;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Upload;
use Yiisoft\Http\Header;
use Yiisoft\Http\Status;

/**
 * Accepts one uploaded file per request and stores it through the facade.
 *
 * One file per request on purpose: core has no `addMany()` because it cannot
 * be atomic, and an action that half-succeeds a multi-upload hands the client
 * a lie about what happened. Multi-file intake is several requests — or a
 * consumer's own loop, where the partial-failure policy is theirs to write.
 *
 * The status codes are the honest ones: 413 for a size the policy (or PHP
 * itself) refuses, 415 for a type the group does not accept, 400 for anything
 * else the client sent wrong, and 500 for the two PHP upload errors no client
 * can fix. A stored file answers 201 with the row's identity as JSON.
 *
 * @api
 */
final readonly class FileUploadAction implements RequestHandlerInterface
{
    /**
     * @param string $groupName The storage group every upload of
     *        this action lands in — the action's configuration, never the
     *        client's choice.
     * @param string $filesKey The PSR-7 uploaded-files key the
     *        action reads.
     */
    /** @var non-empty-string */
    private string $groupName;

    public function __construct(
        private StorageInterface $storage,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        string $groupName = 'common',
        private string $filesKey = 'file',
    ) {
        if ($groupName === '') {
            throw new \InvalidArgumentException('Group name must not be empty');
        }
        if ($filesKey === '') {
            throw new \InvalidArgumentException('Files key must not be empty');
        }

        $this->groupName = $groupName;
    }

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $uploaded = $request->getUploadedFiles()[$this->filesKey] ?? null;
        if (!$uploaded instanceof UploadedFileInterface) {
            // Absent, or a nested array — the latter is a multi-upload, which
            // this action refuses rather than half-processes.
            return $this->error(
                Status::BAD_REQUEST,
                "Expected exactly one uploaded file under \"{$this->filesKey}\"",
            );
        }

        // PHP's own upload verdict is checked before anything of ours runs:
        // these codes say the bytes never arrived, and the sizes ones say so
        // before `post_max_size` could even let the request in.
        $error = $uploaded->getError();
        if ($error !== \UPLOAD_ERR_OK) {
            return match ($error) {
                \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => $this->error(
                    Status::PAYLOAD_TOO_LARGE,
                    'The upload exceeds the configured size limit',
                ),
                // The two errors no client can fix: the server's tmp dir is
                // missing or unwritable. A 400 here would send the client
                // looking for a bug it does not have.
                \UPLOAD_ERR_NO_TMP_DIR, \UPLOAD_ERR_CANT_WRITE => $this->error(
                    Status::INTERNAL_SERVER_ERROR,
                    "Upload failed with PHP error code {$error}",
                ),
                default => $this->error(Status::BAD_REQUEST, "Upload failed with PHP error code {$error}"),
            };
        }

        try {
            $upload = Upload::fromUploadedFile($uploaded, $this->streams);
            $file = $this->storage->add(upload: $upload, groupName: $this->groupName);
        } catch (UploadTooLargeException|UploadSizeException|ContentTooLargeException) {
            return $this->error(Status::PAYLOAD_TOO_LARGE, 'The upload exceeds the group\'s size limit');
        } catch (UnsupportedMediaTypeException $e) {
            return $this->error(Status::UNSUPPORTED_MEDIA_TYPE, $e->getMessage());
        } catch (\Rasuvaeff\Yii3Filestorage\Exception\PolicyViolationException $e) {
            // Pixel caps and any future accept rule: the client's file is
            // wrong, not the server.
            return $this->error(Status::BAD_REQUEST, $e->getMessage());
        }

        return $this->created($file);
    }

    private function error(int $status, string $message): ResponseInterface
    {
        $body = json_encode(['error' => $message], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->responses
            ->createResponse($status)
            ->withHeader(Header::CONTENT_TYPE, 'application/json')
            ->withBody($this->streams->createStream($body));
    }

    private function created(File $file): ResponseInterface
    {
        $body = json_encode([
            'id' => $file->id,
            'originalName' => $file->originalName,
            'size' => $file->size,
            'mimeType' => $file->mimeType,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->responses
            ->createResponse(Status::CREATED)
            ->withHeader(Header::CONTENT_TYPE, 'application/json')
            ->withBody($this->streams->createStream($body));
    }
}
