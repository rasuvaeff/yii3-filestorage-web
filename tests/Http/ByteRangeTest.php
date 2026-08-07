<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Http;

use Rasuvaeff\Yii3FilestorageWeb\Http\ByteRange;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ByteRange::class)]
final class ByteRangeTest
{
    /**
     * @param array{int, int} $expected
     */
    #[DataProvider('satisfiableProvider')]
    public function parsesASatisfiableRange(string $header, int $size, array $expected): void
    {
        $range = ByteRange::parse($header, $size);

        Assert::instanceOf($range, ByteRange::class);
        Assert::same([($range ?? $this->any())->first, ($range ?? $this->any())->last], $expected);
    }

    /**
     * @return iterable<string, array{string, int, array{int, int}}>
     */
    public static function satisfiableProvider(): iterable
    {
        yield 'a closed range' => ['bytes=0-4', 100, [0, 4]];
        yield 'an open-ended range' => ['bytes=90-', 100, [90, 99]];
        yield 'a suffix range' => ['bytes=-10', 100, [90, 99]];
        yield 'a suffix longer than the file' => ['bytes=-500', 100, [0, 99]];
        yield 'the whole thing' => ['bytes=0-99', 100, [0, 99]];
        yield 'one byte' => ['bytes=5-5', 100, [5, 5]];
        yield 'an end past the file is clamped' => ['bytes=95-1000', 100, [95, 99]];
        yield 'case does not matter' => ['BYTES=0-4', 100, [0, 4]];
        yield 'surrounding whitespace' => ['  bytes=0-4  ', 100, [0, 4]];
    }

    /**
     * `false` means "this is not something we serve as a range" — send the
     * whole representation, which RFC 9110 explicitly allows.
     */
    #[DataProvider('ignoredProvider')]
    public function ignoresWhatItDoesNotServe(string $header): void
    {
        Assert::false(ByteRange::parse($header, 100));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ignoredProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'another unit' => ['items=0-4'];
        yield 'multiple ranges' => ['bytes=0-4,10-14'];
        yield 'no numbers at all' => ['bytes=-'];
        yield 'nonsense' => ['bytes'];
        yield 'not a number' => ['bytes=a-b'];
        // the pattern is anchored at both ends: a prefixed unit is not `bytes`
        yield 'a prefixed unit' => ['xbytes=0-4'];
        yield 'a suffixed header' => ['bytes=0-4 extra'];
    }

    /**
     * `null` means 416: the client asked for bytes that do not exist, and
     * quietly sending something else would corrupt a resumed download.
     */
    #[DataProvider('unsatisfiableProvider')]
    public function reportsAnUnsatisfiableRange(string $header, int $size): void
    {
        Assert::null(ByteRange::parse($header, $size));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function unsatisfiableProvider(): iterable
    {
        yield 'starts past the end' => ['bytes=100-200', 100];
        yield 'starts exactly at the end' => ['bytes=100-', 100];
        yield 'a zero-length suffix' => ['bytes=-0', 100];
        yield 'anything against an empty file' => ['bytes=0-0', 0];
        yield 'a suffix against an empty file' => ['bytes=-1', 0];
    }

    public function lengthIsInclusiveOfBothEnds(): void
    {
        Assert::same((ByteRange::parse('bytes=0-0', 100) ?: $this->any())->length(), 1);
        Assert::same((ByteRange::parse('bytes=0-9', 100) ?: $this->any())->length(), 10);
        Assert::same((ByteRange::parse('bytes=90-', 100) ?: $this->any())->length(), 10);
    }

    public function theContentRangeHeaderNamesTheWholeSize(): void
    {
        Assert::same((ByteRange::parse('bytes=0-4', 100) ?: $this->any())->contentRange(100), 'bytes 0-4/100');
    }

    private function any(): ByteRange
    {
        $range = ByteRange::parse('bytes=0-0', 1);
        \assert($range instanceof ByteRange);

        return $range;
    }
}
