<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Path\RandomPathGenerator;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\ScopedFileResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3Filestorage\Url\HmacUrlSigner;
use Rasuvaeff\Yii3Filestorage\Url\SigningKeyRing;
use Rasuvaeff\Yii3FilestorageWeb\Action\FileDownloadAction;
use Rasuvaeff\Yii3FilestorageWeb\Http\FileResponseFactory;
use Rasuvaeff\Yii3FilestorageWeb\Url\HmacProxyUrlGenerator;
use Yiisoft\Test\Support\Clock\StaticClock;

require __DIR__ . '/../vendor/autoload.php';

$factory = new Psr17Factory();
$clock = new StaticClock(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

// A signing key. In an application this comes from the environment; anything
// shorter than 32 bytes is refused as a configuration error.
$signer = new HmacUrlSigner(
    clock: $clock,
    keys: new SigningKeyRing('2026-08', ['2026-08' => bin2hex(random_bytes(32))]),
);

$store = new InMemoryStore('memory', $factory, $clock);
$result = $store->write(
    Upload::fromStream($factory->createStream('the quick brown fox'), 'notes.txt', $factory),
    'common',
    new RandomPathGenerator(),
);

$file = File::create(
    id: 'file-1',
    storeName: 'memory',
    groupName: 'common',
    relativePath: $result->relativePath,
    originalName: 'meeting notes.txt',
    size: $result->size,
    createdAt: $clock->now(),
    mimeType: 'text/plain',
);

// A one-file stand-in for what `-db` provides. Note the shape: id *and* scope,
// never id alone.
$files = new class ($file) implements ScopedFileResolverInterface {
    public function __construct(private readonly File $file) {}

    public function findInScope(string $id, ?string $scopeId): ?File
    {
        return $id === $this->file->id && $scopeId === null ? $this->file : null;
    }
};

$action = new FileDownloadAction(
    signer: $signer,
    files: $files,
    stores: new StoreRegistry([$store]),
    deliveryPolicies: new DeliveryPolicyRegistry(),
    downloads: new FileResponseFactory($factory, $factory),
    responses: $factory,
);

// 1. Mint a URL. This is what Storage::urlFor() returns once -web is installed.
$url = (new HmacProxyUrlGenerator($signer))->url($file, $clock->now()->modify('+1 hour'));
echo "signed URL:\n  {$url}\n\n";

$token = substr((string) $url, strlen('/files/'));
$request = static fn(array $headers = []): ServerRequest => array_reduce(
    array_keys($headers),
    static fn(ServerRequest $r, string $n): ServerRequest => $r->withHeader($n, $headers[$n]),
    (new ServerRequest('GET', (string) $url))->withAttribute('token', $token),
);
$show = static function (string $label, ResponseInterface $response): void {
    echo "{$label}: {$response->getStatusCode()}\n";
    foreach (['Content-Type', 'Content-Disposition', 'Content-Range', 'ETag', 'Accept-Ranges'] as $header) {
        $value = $response->getHeaderLine($header);
        $value === '' or print("  {$header}: {$value}\n");
    }
    $body = (string) $response->getBody();
    $body === '' or print("  body: {$body}\n");
};

// 2. The whole file.
$response = $action->handle($request());
$show('GET', $response);

// 3. The client already has it. No body, and the store is never opened.
$show("\nGET with If-None-Match", $action->handle($request(['If-None-Match' => $response->getHeaderLine('ETag')])));

// 4. A byte range. The store here is seekable, so the window costs a seek.
$show("\nGET with Range: bytes=4-8", $action->handle($request(['Range' => 'bytes=4-8'])));

// 5. Past the end.
$show("\nGET with Range: bytes=900-", $action->handle($request(['Range' => 'bytes=900-'])));

// 6. Refusals, which all look the same on purpose: a 403 would confirm the id
//    exists, and an opaque token exists so that it cannot.
$expired = $signer->sign(
    new Rasuvaeff\Yii3Filestorage\Url\SignedPayload(fileId: 'file-1'),
    $clock->now()->modify('-1 second'),
);
$show("\nGET with an expired token", $action->handle(
    (new ServerRequest('GET', '/files/' . $expired))->withAttribute('token', $expired),
));
$show("\nGET with a forged token", $action->handle(
    (new ServerRequest('GET', '/files/nope'))->withAttribute('token', 'nope'),
));
