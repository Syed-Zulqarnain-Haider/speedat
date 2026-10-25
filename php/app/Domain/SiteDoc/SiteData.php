<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

use Speedat\Domain\Pricing\RateCard;
use Speedat\Domain\Pricing\Settings;

/**
 * Everything the public site renders from, as one published document: the rate
 * card plus the company details, the page copy and the remembered import
 * layouts. Port of `SiteData` in src/lib/site/types.ts.
 *
 * Publishing writes a new immutable version of this; the site always reads the
 * latest one. It is a snapshot on purpose - the calculator needs the whole card
 * at once, the admin edits it as a whole, and a restore is then a copy rather
 * than a replay.
 *
 * Immutable. Nothing edits a document in place; the admin builds a new one and
 * `Repo::publishVersion()` stores it.
 */
final class SiteData
{
    /** @param list<ImportProfile> $importProfiles */
    public function __construct(
        public readonly RateCard $card,
        public readonly Company $company,
        public readonly Content $content,
        /** Sample rates are shown with a notice until the admin ticks "rates are live". */
        public readonly bool $live = false,
        public readonly array $importProfiles = [],
        /** The optional promo popup; disabled by default, so an older document that carries none shows none. */
        public readonly Promo $promo = new Promo(),
        /** The owner-chosen customer reviews; empty by default, so an older document that carries none shows none. */
        public readonly Reviews $reviews = new Reviews(),
    ) {
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        $profiles = [];
        if (isset($a['importProfiles']) && is_array($a['importProfiles'])) {
            foreach ($a['importProfiles'] as $profile) {
                if (is_array($profile)) {
                    $profiles[] = ImportProfile::fromArray($profile);
                }
            }
        }

        return new self(
            card: RateCard::fromArray($a),
            company: Company::fromArray(is_array($a['company'] ?? null) ? $a['company'] : []),
            content: Content::fromArray(is_array($a['content'] ?? null) ? $a['content'] : []),
            live: ($a['live'] ?? false) === true,
            importProfiles: $profiles,
            promo: Promo::fromArray(is_array($a['promo'] ?? null) ? $a['promo'] : []),
            reviews: Reviews::fromArray(is_array($a['reviews'] ?? null) ? $a['reviews'] : []),
        );
    }

    /**
     * The document as it is stored and as the schema reads it.
     *
     * The key order is `migrate()`'s return order, so two documents that mean
     * the same thing serialise to the same JSON and a diff of the stored text
     * shows only real changes.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $services = [];
        foreach ($this->card->services as $service) {
            $services[] = $service->toArray();
        }

        $destinations = [];
        foreach ($this->card->destinations as $destination) {
            $destinations[] = $destination->toArray();
        }

        $profiles = [];
        foreach ($this->importProfiles as $profile) {
            $profiles[] = $profile->toArray();
        }

        return [
            'live'           => $this->live,
            'company'        => $this->company->toArray(),
            'settings'       => $this->card->settings->toArray(),
            'services'       => $services,
            'destinations'   => $destinations,
            'content'        => $this->content->toArray(),
            'promo'          => $this->promo->toArray(),
            'reviews'        => $this->reviews->toArray(),
            'importProfiles' => $profiles,
        ];
    }

    /** Shorthand the pricing code reads constantly. */
    public function settings(): Settings
    {
        return $this->card->settings;
    }

    /** A copy with a different rate card; everything else is carried over untouched. */
    public function withCard(RateCard $card): self
    {
        return new self($card, $this->company, $this->content, $this->live, $this->importProfiles, $this->promo, $this->reviews);
    }

    /** A copy with a different company block. */
    public function withCompany(Company $company): self
    {
        return new self($this->card, $company, $this->content, $this->live, $this->importProfiles, $this->promo, $this->reviews);
    }

    /** A copy with different page copy. */
    public function withContent(Content $content): self
    {
        return new self($this->card, $this->company, $content, $this->live, $this->importProfiles, $this->promo, $this->reviews);
    }

    /** A copy with the "rates are live" tick moved. */
    public function withLive(bool $live): self
    {
        return new self($this->card, $this->company, $this->content, $live, $this->importProfiles, $this->promo, $this->reviews);
    }

    /** A copy with a different set of remembered import layouts. @param list<ImportProfile> $profiles */
    public function withImportProfiles(array $profiles): self
    {
        return new self($this->card, $this->company, $this->content, $this->live, $profiles, $this->promo, $this->reviews);
    }

    /** A copy with a different promo popup block. */
    public function withPromo(Promo $promo): self
    {
        return new self($this->card, $this->company, $this->content, $this->live, $this->importProfiles, $promo, $this->reviews);
    }

    /** A copy with a different set of customer reviews. */
    public function withReviews(Reviews $reviews): self
    {
        return new self($this->card, $this->company, $this->content, $this->live, $this->importProfiles, $this->promo, $reviews);
    }
}
