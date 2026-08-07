<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Http;

use Psr\Http\Message\StreamInterface;
use Rasuvaeff\Yii3Filestorage\Test\InMemoryStore;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageWeb\Http\FileResponseFactory;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\FixedPath;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\ForwardOnlyStream;
use Rasuvaeff\Yii3FilestorageWeb\Tests\Support\RangeReadableStore;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(FileResponseFactory::class)]
final class FileResponseFactoryTest
{
    private const string PATH = 'common/ab/cd/key/original.txt';

    private FileResponseFactory $factory;
    private InMemoryStore $store;

    #[BeforeTest]
    public function setUp(): void
    {
        $psr = Fixtures::factory();
        $this->factory = new FileResponseFactory($psr, $psr);
        $this->store = new InMemoryStore('memory', $psr, new StaticClock(Fixtures::now()));
        $this->store->write(
            Upload::fromStream($psr->createStream('hello world'), 'a.txt', $psr),
            'common',
            new FixedPath(self::PATH),
        );
    }

    public function aWholeFileIsA200WithTheDisposition(): void
    {
        $response = $this->create();

        Assert::same($response->getStatusCode(), 200);
        Assert::same((string) $response->getBody(), 'hello world');
        Assert::same($response->getHeaderLine('Content-Type'), 'text/plain');
        Assert::string($response->getHeaderLine('Content-Disposition'))->contains('attachment');
    }

    public function inlineDeliveryIsSpelledOut(): void
    {
        $response = $this->create(inline: true);

        Assert::string($response->getHeaderLine('Content-Disposition'))->contains('inline');
    }

    /**
     * A seekable body with a known length can be windowed with a seek, so
     * ranges are advertised and served.
     */
    public function aSeekableBodyGetsRanges(): void
    {
        $response = $this->create(range: 'bytes=0-4');

        Assert::same($response->getStatusCode(), 206);
        Assert::same((string) $response->getBody(), 'hello');
        Assert::same($response->getHeaderLine('Content-Range'), 'bytes 0-4/11');
        Assert::same($response->getHeaderLine('Content-Length'), '5');
        Assert::same($response->getHeaderLine('Accept-Ranges'), 'bytes');
    }

    public function aSuffixRangeReadsFromTheEnd(): void
    {
        $response = $this->create(range: 'bytes=-5');

        Assert::same($response->getStatusCode(), 206);
        Assert::same((string) $response->getBody(), 'world');
        Assert::same($response->getHeaderLine('Content-Range'), 'bytes 6-10/11');
    }

    public function anUnsatisfiableRangeIs416WithAnEmptyBody(): void
    {
        $response = $this->create(range: 'bytes=99-200');

        Assert::same($response->getStatusCode(), 416);
        Assert::same((string) $response->getBody(), '');
        Assert::same($response->getHeaderLine('Content-Range'), 'bytes */11');
        Assert::same($response->getHeaderLine('Content-Length'), '0');
    }

    /**
     * A multi-range request is answered with the whole representation rather
     * than a multipart body — allowed, and the honest alternative to
     * pretending.
     */
    public function aRangeThisDoesNotServeGetsTheWholeFile(): void
    {
        $response = $this->create(range: 'bytes=0-1,5-6');

        Assert::same($response->getStatusCode(), 200);
        Assert::same((string) $response->getBody(), 'hello world');
        Assert::same($response->getHeaderLine('Content-Length'), '11');
    }

    /**
     * The object-store case: a forward-only body cannot be windowed without
     * downloading and discarding the prefix, so no `Accept-Ranges` is offered
     * and a `Range` request is answered in full.
     */
    public function aForwardOnlyBodyAdvertisesNoRanges(): void
    {
        $stream = new ForwardOnlyStream('hello world');

        $response = $this->factory->create(
            file: Fixtures::file(),
            store: $this->store,
            stream: $stream,
            options: Fixtures::deliveryOptions(),
            inline: false,
            rangeHeader: 'bytes=0-4',
        );

        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getHeaderLine('Accept-Ranges'), '');
        Assert::same((string) $response->getBody(), 'hello world');
    }

    /**
     * A store with a native range primitive gets to use it, even though the
     * body it hands out is forward-only. This is the case a response builder
     * that only sees the stream can never serve — and the reason this class
     * exists rather than delegating.
     */
    public function aStoreWithARangePrimitiveIsAskedForTheWindow(): void
    {
        $store = new RangeReadableStore($this->store, Fixtures::factory());

        $response = $this->factory->create(
            file: Fixtures::file(),
            store: $store,
            stream: new ForwardOnlyStream('hello world'),
            options: Fixtures::deliveryOptions(),
            inline: false,
            rangeHeader: 'bytes=6-10',
        );

        Assert::same($response->getStatusCode(), 206);
        Assert::same((string) $response->getBody(), 'world');
        Assert::same($store->rangeCalls, 1);
    }

    /**
     * The capability was advertised and the object vanished mid-request. A
     * forward-only body cannot be windowed, so the range is dropped rather
     * than the wrong bytes being sent under a 206 — the caller sees the whole
     * representation.
     */
    public function aStoreThatDeclinesTheWindowFallsBack(): void
    {
        $store = new RangeReadableStore($this->store, Fixtures::factory(), declines: true);

        $response = $this->factory->create(
            file: Fixtures::file(),
            store: $store,
            stream: new ForwardOnlyStream('hello world'),
            options: Fixtures::deliveryOptions(),
            inline: false,
            rangeHeader: 'bytes=6-10',
        );

        Assert::same((string) $response->getBody(), 'hello world');
    }

    private function create(bool $inline = false, ?string $range = null): \Psr\Http\Message\ResponseInterface
    {
        return $this->factory->create(
            file: Fixtures::file(),
            store: $this->store,
            stream: $this->stream(),
            options: Fixtures::deliveryOptions(),
            inline: $inline,
            rangeHeader: $range,
        );
    }

    private function stream(): StreamInterface
    {
        $stream = $this->store->stream(Fixtures::file());
        \assert($stream instanceof StreamInterface);

        return $stream;
    }
}
