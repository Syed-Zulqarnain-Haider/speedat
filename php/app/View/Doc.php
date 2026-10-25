<?php

declare(strict_types=1);

namespace Speedat\View;

use Speedat\Domain\Pricing\Destination;
use Speedat\Domain\Pricing\RateCard;
use Speedat\Domain\Pricing\Service;
use Speedat\Domain\Pricing\Settings;
use Speedat\Domain\SiteDoc\Company;
use Speedat\Domain\SiteDoc\Content;
use Speedat\Domain\SiteDoc\HoldState;
use Speedat\Domain\SiteDoc\PublishedVersion;
use Speedat\Domain\SiteDoc\SiteData;

/**
 * The published document and the price hold, as one thing the templates read.
 *
 * It owns nothing: `Speedat\Domain\SiteDoc` parses and validates the document,
 * `Speedat\Domain\Pricing` prices it, and this wraps the pair so a template
 * asks one object instead of reaching through `$version->data->company->name`
 * and `$hold->on` separately. The two questions every page asks - "may I print
 * a price?" and "which destinations are live?" - are answered here, once, so no
 * template decides either.
 *
 * `Live::site()` and `Live::hold()` are where a controller gets its arguments:
 *
 *     $doc = Doc::of(Live::site(), Live::hold());
 *
 * Nothing here is escaped. Escaping happens at the point of printing, and a
 * pre-escaped model would be escaped twice by e().
 */
final class Doc
{
    private function __construct(
        public readonly PublishedVersion $published,
        public readonly HoldState $hold,
    ) {
    }

    public static function of(PublishedVersion $published, ?HoldState $hold = null): self
    {
        return new self($published, $hold ?? HoldState::none());
    }

    /**
     * Build from the decoded document, for a test or a fixture. The migration
     * runs, so an older document loads exactly as the live site loads it.
     *
     * @param array<string,mixed> $published the `PublishedVersion` shape from types.ts
     * @param array<string,mixed> $hold      `{on, message}`
     */
    public static function fromArray(array $published, array $hold = []): self
    {
        $version     = isset($published['version']) && is_numeric($published['version']) ? (int) $published['version'] : 0;
        $publishedAt = isset($published['publishedAt']) && is_string($published['publishedAt']) ? $published['publishedAt'] : '';

        return new self(
            new PublishedVersion(SiteData::fromArray($published), $version, $publishedAt),
            ($hold['on'] ?? false)
                ? new HoldState(true, is_string($hold['message'] ?? null) ? $hold['message'] : '', '', null)
                : HoldState::none(),
        );
    }

    public function data(): SiteData
    {
        return $this->published->data;
    }

    public function card(): RateCard
    {
        return $this->published->data->card;
    }

    public function company(): Company
    {
        return $this->published->data->company;
    }

    public function content(): Content
    {
        return $this->published->data->content;
    }

    public function settings(): Settings
    {
        return $this->published->data->card->settings;
    }

    public function currency(): string
    {
        return $this->settings()->currency;
    }

    public function version(): int
    {
        return $this->published->version;
    }

    public function publishedAt(): string
    {
        return $this->published->publishedAt;
    }

    public function holdOn(): bool
    {
        return $this->hold->on;
    }

    public function holdMessage(): string
    {
        return $this->hold->message;
    }

    /** A content field with the surrounding whitespace gone - what "the owner set one" means. */
    public function trimmed(string $field): string
    {
        return trim($this->content()->get($field));
    }

    /** @return list<Service> */
    public function services(): array
    {
        return $this->card()->services;
    }

    /**
     * Active destinations in the admin's ORDER - the order the country tiles
     * and the rates board use.
     *
     * @return list<Destination>
     */
    public function active(): array
    {
        return array_values(array_filter(
            $this->card()->destinations,
            static fn (Destination $d): bool => $d->active
        ));
    }

    /**
     * Active destinations A-Z, for the selects.
     *
     * A plain byte comparison, not `strcoll`: the site is one language, the
     * locale on a shared host is not ours to rely on, and the only difference
     * is where an accented name sorts - which moves a row and changes no number.
     *
     * @return list<Destination>
     */
    public function activeAlpha(): array
    {
        $out = $this->active();
        usort($out, static fn (Destination $a, Destination $b): int => strcmp($a->name, $b->name));

        return $out;
    }

    /**
     * Whether a price may be printed AT ALL. Every price on every page is
     * behind this one question, so the hold cannot be half-honoured.
     */
    public function pricesVisible(): bool
    {
        return !$this->hold->on;
    }

    /** "Sample prices for now." renders under every printed price until the rates are live. */
    public function sampleNotice(): bool
    {
        return !$this->published->data->live && !$this->hold->on;
    }

    /** `https://wa.me/<digits>`; the digits are the document's, never a request's. */
    public function waHref(): string
    {
        return 'https://wa.me/' . (preg_replace('/\D/', '', $this->company()->whatsapp) ?? '');
    }

    /** `tel:` for the landline, or "" when the owner left it blank. */
    public function telHref(): string
    {
        $phone = $this->company()->phone;

        return $phone === '' ? '' : 'tel:' . (preg_replace('/[^0-9+]/', '', $phone) ?? '');
    }

    /**
     * The document as the calculator's data blob wants it: the published
     * document, rebuilt from the parsed objects, with the inactive destinations
     * dropped and every content field except `promise` left on the server.
     *
     * @return array<string,mixed>
     */
    public function toPublishedArray(): array
    {
        $destinations = [];
        foreach ($this->active() as $dest) {
            $destinations[] = $dest->toArray();
        }

        $services = [];
        foreach ($this->services() as $service) {
            $services[] = $service->toArray();
        }

        return [
            'version'      => $this->version(),
            'publishedAt'  => $this->publishedAt(),
            'live'         => $this->published->data->live,
            'company'      => $this->company()->toArray(),
            // The calculator reads one content field; the rest is page copy the
            // page has already printed, so it stays here.
            'content'      => ['promise' => $this->content()->promise],
            'services'     => $services,
            'settings'     => $this->settings()->toArray(),
            'destinations' => $destinations,
        ];
    }
}
