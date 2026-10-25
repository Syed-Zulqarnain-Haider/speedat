<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * "Put version 7 back on the website?" - the question the owner is asked
 * before a roll-back, and the reasons the answer may have to be no.
 *
 * ROLLING BACK IS TWO POSTS, AND THIS IS WHAT SITS BETWEEN THEM. The first
 * press writes nothing at all: it redirects back to the editor with
 * `?rollback=<n>` and this card is drawn. Only a post carrying `confirm=1`
 * performs it. One press should not be able to change every price on the
 * website, and a confirmation that lives in a `window.confirm()` is a
 * confirmation that is gone the moment JavaScript is off.
 *
 * WHY THE PROBLEMS ARE LISTED HERE RATHER THAN AFTER THE REFUSAL.
 * `Repo::rollbackTo()` raises `RollbackRefused` with the validator's own
 * sentences in `->reasons` - "Canada is shown on the site but has no
 * prices." - and those sentences are the useful half of the refusal. They
 * cannot travel back through a redirect: `Http\Admin\Notice` carries codes
 * and never text, on purpose, so that nothing a person typed can be put into
 * the owner's screen through a URL. So the same check is run HERE, while the
 * question is being asked and before anything is written, and the owner reads
 * the reasons at the moment he is deciding instead of after being refused.
 * The refusal itself stays where it is, as the floor under this.
 */
final class RollbackAsk
{
    /** @param list<string> $problems the validator's sentences, or [] */
    public function __construct(
        public readonly int $version,
        /** The whole question, from `Http\Admin\Notice::rollbackQuestion()`. */
        public readonly string $question,
        public readonly array $problems = [],
        /** Prices are off the website; a roll-back never puts them back on. */
        public readonly bool $holdOn = false,
        /** No such version was ever published. */
        public readonly bool $unknown = false,
    ) {
    }

    /** May the button be offered at all? */
    public function allowed(): bool
    {
        return !$this->unknown && $this->problems === [];
    }

    /** The heading: a question when it can be answered, a statement when it cannot. */
    public function heading(): string
    {
        return $this->allowed()
            ? 'Roll back to version ' . $this->version . '?'
            : 'Version ' . $this->version . ' cannot be put back';
    }

    /** Why it cannot, in one line, above the validator's own sentences. */
    public function refusal(): string
    {
        if ($this->allowed()) {
            return '';
        }

        if ($this->unknown) {
            return 'That version was never published, so there is nothing to go back to.';
        }

        return 'It no longer passes the checks a publish has to pass, so it would take prices off the website. '
            . 'Restore it into the editor, fix what it is missing, and publish it.';
    }

    /** The line that has to be on the screen whenever prices are held. */
    public function holdNote(): string
    {
        return $this->holdOn
            ? 'Prices are on hold, and rolling back does not put them back on the website. '
            . 'Customers will still see no prices until you resume them.'
            : '';
    }
}
