<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb\Http;

/**
 * One satisfiable byte range, parsed from a `Range` header.
 *
 * Single ranges only. A multipart response is a different content type, a
 * different body format and a boundary generator, and no client this serves —
 * a video element seeking, a download manager resuming — asks for more than
 * one. Advertising `bytes` and then ignoring a multi-range request is allowed:
 * RFC 9110 says a server may respond with the whole representation.
 *
 * @api
 */
final readonly class ByteRange
{
    /**
     * @param int<0, max> $first
     * @param int<0, max> $last Inclusive, as HTTP counts.
     */
    private function __construct(
        public int $first,
        public int $last,
    ) {}

    /**
     * @param int<0, max> $size
     *
     * @return self|false|null A range, `false` when the header asks for
     *         something this does not serve (so send the whole thing), or
     *         `null` when it is unsatisfiable (so send 416).
     */
    public static function parse(string $header, int $size): self|false|null
    {
        if (preg_match('/^bytes=(\d*)-(\d*)\z/i', trim($header), $matches) !== 1) {
            // Includes multi-range requests and any unit that is not bytes.
            return false;
        }

        [, $firstPart, $lastPart] = $matches;

        if ($firstPart === '' && $lastPart === '') {
            return false;
        }

        // A zero-length representation can satisfy nothing, and `bytes 0-0/0`
        // would be a lie about a byte that does not exist.
        if ($size === 0) {
            return null;
        }

        if ($firstPart === '') {
            // `bytes=-500`: the last 500 bytes, clamped to what exists.
            $length = (int) $lastPart;

            return $length === 0 ? null : new self(max(0, $size - $length), max(0, $size - 1));
        }

        $first = (int) $firstPart;
        if ($first >= $size) {
            return null;
        }

        // A last-byte-pos below first-byte-pos makes the spec *invalid*, not
        // unsatisfiable, and RFC 9110 says an invalid Range field is ignored —
        // the client gets the whole file. Answering 416 turned a malformed
        // header into a hard error over a file the client could have had.
        // Checked against the raw value, before the clamp to size-1 collapses
        // `bytes=5-3` and `bytes=5-99` on a 10-byte file into the same number.
        if ($lastPart !== '' && (int) $lastPart < $first) {
            return false;
        }

        $last = max(0, $lastPart === '' ? $size - 1 : min((int) $lastPart, $size - 1));

        return $last < $first ? null : new self(max(0, $first), $last);
    }

    /**
     * @return int<1, max>
     */
    public function length(): int
    {
        return max(1, $this->last - $this->first + 1);
    }

    /**
     * @param int<0, max> $size
     *
     * @return non-empty-string
     */
    public function contentRange(int $size): string
    {
        return "bytes {$this->first}-{$this->last}/{$size}";
    }
}
