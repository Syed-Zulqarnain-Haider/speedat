<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Domain\Pricing\Format;
use Speedat\Domain\SiteDoc\PublishedVersion;

/** The website-text screen: every field a marketer may change, and what is live now. */
final class PagesPage
{
    /** @param array<string,string> $typed what was typed, when a publish was refused */
    public function __construct(
        public readonly PublishedVersion $live,
        public readonly array $typed = [],
        public readonly int $base = 0,
        public readonly string $error = '',
        public readonly string $toast = '',
    ) {
    }

    /** @return list<array{heading:string,rows:list<array{type:string,fields:list<Field>}>}> */
    public function groups(): array
    {
        $site = $this->live->data->toArray();
        $site['content'] = array_merge($site['content'], $this->typed);

        return ContentFields::pageGroups(Site::fromArray($site));
    }

    /** "Last change: version 7, 23 Sept 2026, 14:05". */
    public function liveMeta(): string
    {
        $when = Format::fmtDateTime($this->live->publishedAt);

        return $this->live->version > 0
            ? 'Live now: version ' . $this->live->version . ($when !== '' ? " \u{b7} " . $when : '')
            : 'Nothing has been published yet.';
    }

    public function ready(): bool
    {
        return $this->live->version > 0;
    }
}
