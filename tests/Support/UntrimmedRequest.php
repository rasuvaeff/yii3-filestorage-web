<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Tests\Support;

use Nyholm\Psr7\ServerRequest;
use Override;

/**
 * A request that hands back header values exactly as the client sent them.
 *
 * Nyholm trims on the way in, which hides the action's own trimming: with a
 * conforming implementation underneath, removing it changes nothing. A server
 * that does not trim is not hypothetical — the field value's surrounding
 * whitespace is optional whitespace RFC 9110 says a recipient must be able to
 * cope with, not something the message layer is required to remove.
 *
 * @internal
 */
final class UntrimmedRequest extends ServerRequest
{
    /** @var array<string, string> */
    private array $raw = [];

    public function withUntrimmedHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->raw[strtolower($name)] = $value;

        return $clone;
    }

    #[Override]
    public function getHeaderLine($header): string
    {
        return $this->raw[strtolower($header)] ?? parent::getHeaderLine($header);
    }
}
