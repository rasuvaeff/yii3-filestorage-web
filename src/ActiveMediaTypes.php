<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageWeb;

/**
 * Media types a browser will execute if you let it render them.
 *
 * An uploaded HTML or SVG file served inline from your own origin is stored
 * XSS: it runs with your cookies, your CSP and your session. So these are
 * forced to `attachment` regardless of what a group's delivery policy says —
 * the policy chooses between "download" and "display", not between "safe" and
 * "unsafe", and a group configured for inline images should not become an XSS
 * vector the day somebody uploads an SVG to it.
 *
 * SVG is the one people forget. It is an image everywhere else in a codebase
 * and a scriptable document here.
 *
 * The list is a constructor argument because new scriptable formats keep
 * arriving, and an application should be able to add one without waiting for a
 * release.
 *
 * @api
 */
final readonly class ActiveMediaTypes
{
    public const array DEFAULT = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'application/xml',
        'text/xml',
        'application/xslt+xml',
        'text/xsl',
        'application/mathml+xml',
        // Not scriptable in a browser, but handed to a plugin or downloaded and
        // opened locally they are the same problem.
        'application/pdf',
        'application/x-shockwave-flash',
    ];

    /** @var list<lowercase-string> */
    private array $types;

    /**
     * @param list<string> $types
     */
    public function __construct(array $types = self::DEFAULT)
    {
        $this->types = array_map(strtolower(...), $types);
    }

    /**
     * @param list<string> $types Added to the defaults rather than replacing them.
     */
    public static function withExtra(array $types): self
    {
        return new self([...self::DEFAULT, ...$types]);
    }

    public function contains(string $mediaType): bool
    {
        // Parameters are not part of the identity: `text/html; charset=utf-8`
        // is every bit as executable as `text/html`.
        $type = strtolower(trim(explode(';', $mediaType, 2)[0]));

        return \in_array($type, $this->types, strict: true);
    }
}
