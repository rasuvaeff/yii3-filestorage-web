<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Http;

use Rasuvaeff\Yii3FilestorageWeb\Http\MediaType;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(MediaType::class)]
final class MediaTypeTest
{
    #[DataProvider('storedProvider')]
    public function normalizesWhatAStoredTypeMayContain(string $stored, string $expected): void
    {
        Assert::same(MediaType::headerSafe($stored), $expected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function storedProvider(): iterable
    {
        yield 'an ordinary type is untouched' => ['text/plain', 'text/plain'];
        yield 'parameters survive' => ['text/html; charset=utf-8', 'text/html; charset=utf-8'];
        yield 'a carriage return goes' => ["text/ht\rml", 'text/html'];
        yield 'a line feed goes' => ["text/ht\nml", 'text/html'];
        yield 'a NUL goes' => ["text/pl\0ain", 'text/plain'];
        yield 'an injected header folds into the value' => ["text/plain\r\nX-Evil: 1", 'text/plainX-Evil: 1'];
        yield 'a value that was nothing but separators' => ["\r\n\0", 'application/octet-stream'];
        yield 'an empty value' => ['', 'application/octet-stream'];
    }
}
