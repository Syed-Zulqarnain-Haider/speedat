<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * A roll-back the repository will not perform, and why.
 *
 * One exception with a reason rather than three exceptions, because the screen
 * has one catch and three sentences. `reasons` carries the validator's own
 * lines for the `INVALID` case - those are sentences written for the owner
 * ("Canada is shown on the site but has no prices."), so they are the ones to
 * print; the exception message is for the log.
 *
 * The discriminator is called `reason`, not `code`: `\Exception` already has a
 * non-readonly `$code` and PHP refuses to redeclare it as readonly.
 *
 * Nothing is ever written before one of these is thrown: a refused roll-back
 * leaves the live version, the draft and the price hold exactly as they were.
 */
final class RollbackRefused extends \RuntimeException
{
    /** No version with that number was ever published. */
    public const NO_SUCH_VERSION = 'no_such_version';

    /** It exists, but today's rules reject it - see `reasons`. */
    public const INVALID = 'invalid';

    /** It is already the live version; there is nothing to roll back. */
    public const NO_CHANGE = 'no_change';

    /** @param list<string> $reasons */
    private function __construct(
        public readonly string $reason,
        public readonly int $version,
        public readonly array $reasons,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function noSuchVersion(int $version): self
    {
        return new self(self::NO_SUCH_VERSION, $version, [], 'No version ' . $version . ' was ever published');
    }

    /**
     * The stored document no longer passes the rules a publish is held to.
     *
     * This is not a theoretical case. A field can leave the product between
     * the publish and the roll-back - the document rate did, on 2026-09-27 -
     * and a version whose only priced service was that one now prices nothing
     * at all. Putting it back on the site would take every price off half the
     * destinations, silently.
     *
     * @param list<string> $reasons
     */
    public static function invalid(int $version, array $reasons): self
    {
        return new self(
            self::INVALID,
            $version,
            $reasons,
            'Version ' . $version . ' no longer passes validation (' . count($reasons) . ' problem'
            . (count($reasons) === 1 ? '' : 's') . ')'
        );
    }

    public static function noChange(int $version): self
    {
        return new self(
            self::NO_CHANGE,
            $version,
            [],
            'Version ' . $version . ' is already live; a roll-back would change nothing'
        );
    }
}
