<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests;

use Rasuvaeff\Yii3FilestorageWeb\ActiveMediaTypes;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ActiveMediaTypes::class)]
final class ActiveMediaTypesTest
{
    #[DataProvider('activeProvider')]
    public function recognisesWhatABrowserWouldExecute(string $mediaType): void
    {
        Assert::true((new ActiveMediaTypes())->contains($mediaType));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function activeProvider(): iterable
    {
        yield 'html' => ['text/html'];
        yield 'xhtml' => ['application/xhtml+xml'];
        // The one people forget: an image everywhere else, a scriptable
        // document when a browser renders it from your origin.
        yield 'svg' => ['image/svg+xml'];
        yield 'xml' => ['application/xml'];
        yield 'text xml' => ['text/xml'];
        yield 'xslt' => ['application/xslt+xml'];
        yield 'pdf' => ['application/pdf'];
        yield 'uppercase' => ['TEXT/HTML'];
        // Parameters are not part of the identity.
        yield 'with a charset' => ['text/html; charset=utf-8'];
        yield 'with surrounding space' => ['  text/html  '];
    }

    #[DataProvider('inertProvider')]
    public function leavesHarmlessTypesAlone(string $mediaType): void
    {
        Assert::false((new ActiveMediaTypes())->contains($mediaType));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inertProvider(): iterable
    {
        yield 'png' => ['image/png'];
        yield 'plain text' => ['text/plain'];
        yield 'json' => ['application/json'];
        yield 'octet stream' => ['application/octet-stream'];
        yield 'empty' => [''];
        // Not a prefix match: `text/html-ish` is not `text/html`.
        yield 'a longer type sharing a prefix' => ['text/html-template'];
    }

    public function anApplicationCanAddToTheList(): void
    {
        // More than one, because a list that kept only the first extra type
        // would pass every single-item test and quietly drop the rest.
        $types = ActiveMediaTypes::withExtra([
            'application/x-custom-script',
            'application/x-second-script',
        ]);

        Assert::true($types->contains('application/x-custom-script'));
        Assert::true($types->contains('application/x-second-script'));
        // added to the defaults, not replacing them
        Assert::true($types->contains('image/svg+xml'));
    }

    /**
     * The configured list is lowercased too, not just the value being checked —
     * otherwise an application writing `Application/PDF` in its params would
     * configure something that never matches.
     */
    public function aConfiguredTypeIsMatchedCaseInsensitively(): void
    {
        $types = new ActiveMediaTypes(['Application/X-Custom']);

        Assert::true($types->contains('application/x-custom'));
        Assert::true($types->contains('APPLICATION/X-CUSTOM'));
    }

    public function anExplicitListReplacesTheDefaults(): void
    {
        $types = new ActiveMediaTypes(['application/x-only-this']);

        Assert::true($types->contains('application/x-only-this'));
        Assert::false($types->contains('text/html'));
    }
}
