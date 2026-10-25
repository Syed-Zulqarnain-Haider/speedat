<?php

declare(strict_types=1);

namespace Speedat\Domain\Quotes;

/**
 * A quote the server will not log, and why.
 *
 * The codes are the ones src/app/api/quotes/route.ts returns, so the HTTP layer
 * can map them straight into the project's error envelope,
 * `{"error": {"code": "...", "message": "..."}}`, without inventing new
 * vocabulary.
 *
 * ==========================================================================
 * ONE ANSWER FOR EVERY BAD FIELD - AND THAT INCLUDES THE ID
 * ==========================================================================
 *
 * This class used to say that its messages give "no hint about which of
 * several checks failed", and then contradict itself three lines down. There
 * was a `not_found` code with its own 404 and its own sentence, so a prober
 * posting bodies at `/api/quotes` learned exactly which field it had got
 * wrong:
 *
 *     malformed id      -> 400 "That is not a quote reference."
 *     unknown/inactive  -> 404 "Unknown destination or service"
 *     any other field   -> 400 "Invalid quote"
 *
 * Three answers is an oracle even when every value it speaks to is one the
 * caller supplied itself, because the NEXT field added is the one where that
 * stops being true. `not_found` is gone: a reference this site did not issue,
 * a destination it does not sell to, a destination it has hidden, a service
 * that is not offered and a weight that is not a number are all one thing -
 * "this is not a quote this site could have produced" - and they all answer
 * `bad_request` / 400 / "Invalid quote".
 *
 * The three codes that remain are not oracles, they are product messages:
 * `held` means the prices are off the site and every visitor is being told so
 * on the page; `over_max` tells the customer to ask for a cargo rate, which is
 * the same sentence the calculator prints; `unavailable` is our fault, not the
 * caller's. None of them depends on which field was sent.
 */
final class QuoteRejected extends \DomainException
{
    public const BAD_REQUEST = 'bad_request';
    public const HELD        = 'held';
    public const OVER_MAX    = 'over_max';
    public const UNAVAILABLE = 'unavailable';

    /**
     * The one sentence every refused field gets. A constant rather than a
     * default argument, so "make them uniform" is something the tests can
     * assert about the class instead of about each call site.
     */
    public const INVALID = 'Invalid quote';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Anything about the body this site will not accept, whichever field it
     * was. The message takes no argument on purpose: an argument is how the
     * distinctions crept back in last time.
     */
    public static function badRequest(): self
    {
        return new self(self::BAD_REQUEST, self::INVALID);
    }

    public static function held(): self
    {
        return new self(self::HELD, 'Prices are being updated');
    }

    public static function overMax(): self
    {
        return new self(self::OVER_MAX, 'That weight needs a cargo rate');
    }

    public static function unavailable(?\Throwable $previous = null): self
    {
        return new self(self::UNAVAILABLE, 'Could not save the quote', $previous);
    }

    /** What the route should answer with. */
    public function httpStatus(): int
    {
        return match ($this->errorCode) {
            self::BAD_REQUEST => 400,
            self::HELD        => 409,
            self::OVER_MAX    => 409,
            default           => 503,
        };
    }
}
