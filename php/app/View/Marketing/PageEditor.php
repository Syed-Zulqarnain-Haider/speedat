<?php

declare(strict_types=1);

namespace Speedat\View\Marketing;

use Speedat\Domain\Pricing\Format;
use Speedat\Domain\SiteDoc\PublishedVersion;
use Speedat\Domain\SiteDoc\VersionMeta;
use Speedat\View\Admin\Field;
use Speedat\View\Admin\Site;

/** One website page in the marketer's editor. */
final class PageEditor
{
    /**
     * @param array<string,string> $typed   what was typed, when a publish was refused
     * @param list<VersionMeta>    $recent  the newest website-text publishes
     */
    public function __construct(
        public readonly PublishedVersion $live,
        public readonly string $tab,
        public readonly string $viewerEmail,
        /** What search engines get while the boxes are blank. */
        public readonly string $autoTitle = '',
        public readonly string $autoDescription = '',
        /** "speedat.co > services", as a search result prints it. */
        public readonly string $address = '',
        public readonly array $typed = [],
        public readonly int $base = 0,
        public readonly array $recent = [],
        public readonly string $error = '',
        public readonly string $notice = '',
    ) {
    }

    private function site(): Site
    {
        $doc            = $this->live->data->toArray();
        $doc['content'] = array_merge($doc['content'], $this->typed);

        return Site::fromArray($doc);
    }

    /** @return list<Field> */
    public function seo(): array
    {
        return Tabs::seo($this->tab, $this->site());
    }

    /** @return list<Field> */
    public function words(): array
    {
        return Tabs::words($this->tab, $this->site());
    }

    public function hasSearchResult(): bool
    {
        return Tabs::route($this->tab) !== '';
    }

    public function shownTitle(): string
    {
        $fields = $this->seo();

        return trim($fields[0]->value ?? '') !== '' ? trim($fields[0]->value) : $this->autoTitle;
    }

    public function shownDescription(): string
    {
        $fields = $this->seo();

        return trim($fields[1]->value ?? '') !== '' ? trim($fields[1]->value) : $this->autoDescription;
    }

    public function ready(): bool
    {
        return $this->live->version > 0;
    }

    /** @return list<array{when:string,who:string,what:string}> */
    public function recentRows(): array
    {
        $out = [];
        foreach ($this->recent as $meta) {
            $out[] = [
                'when' => Format::fmtDateTime($meta->publishedAt),
                // Their own, or not. Whose it was otherwise is the admin's to know.
                'who'  => $meta->publishedBy === $this->viewerEmail ? 'you' : 'someone else',
                'what' => $meta->changeCount . ' change' . ($meta->changeCount === 1 ? '' : 's'),
            ];
        }

        return $out;
    }
}
