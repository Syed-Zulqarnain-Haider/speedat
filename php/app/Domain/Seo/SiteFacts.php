<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

use Speedat\Domain\Pricing\Engine;
use Speedat\Domain\Pricing\Format;
use Speedat\Domain\Pricing\Piece;
use Speedat\Domain\SiteDoc\Company;
use Speedat\Domain\SiteDoc\Content;
use Speedat\Domain\SiteDoc\SiteData;

/**
 * The published document, read the way the SEO layer needs to read it.
 *
 * ONE RULE GOVERNS THIS WHOLE FILE: nothing here may say something the visible
 * page does not. So every sentence is either a content field exactly as the
 * owner typed it, or a sentence generated from the rate document by the same
 * code path the page uses - `Engine::lines()`/`Engine::parts()` for the FAQ and
 * the service list, `Engine::priceAll()` for the price range, the same country
 * sentence the home headline is built from. Nothing is written into this file
 * that is not in the document, and a field that is blank falls back to another
 * field, never to an invention.
 *
 * It is a projection, not a copy: it holds the document and answers questions
 * about it. The document itself (`SiteData`) belongs to `Domain\SiteDoc`.
 */
final class SiteFacts
{
    public function __construct(
        public readonly SiteData $site,
        /**
         * The published version number and its timestamp. They drive
         * `lastmod` in the sitemap and `dateModified` in the JSON-LD, so a
         * publish is what moves those dates and nothing else does. Version 0
         * (the sample document, nothing published yet) carries no timestamp -
         * see `lastModified()`.
         */
        public readonly int $version = 0,
        /** ISO-8601 as the database stores it; "" when nothing has been published. */
        public readonly string $publishedAt = '',
        /**
         * False while the owner is holding prices. The hold takes every price
         * off the page a customer reads; the structured data is the copy a
         * search engine reads, so it has to go quiet too - otherwise the one
         * number the hold exists to withdraw is still published, and indexed.
         * Only `priceRange()` depends on this: the country list, the generated
         * description and everything else the hold does NOT intend to hide are
         * unaffected.
         */
        public readonly bool $pricesVisible = true,
    ) {
    }

    public function company(): Company
    {
        return $this->site->company;
    }

    public function content(): Content
    {
        return $this->site->content;
    }

    /** The company name, trimmed; the one string appended to every title. */
    public function companyName(): string
    {
        return trim($this->company()->name);
    }

    /**
     * Pickup cities, split the way src/lib/site/text.ts `originCities()` splits
     * them - on commas, semicolons and newlines - so the SEO sentences name the
     * same cities the About and Contact pages print.
     *
     * @return list<string>
     */
    public function originCities(): array
    {
        $out = [];

        foreach (preg_split('/[,;\n]+/', $this->company()->originCities) ?: [] as $city) {
            $trimmed = trim($city);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return $out;
    }

    /** The city the site ships from, for a sentence: the first pickup city, or the country. */
    public function originPlace(): string
    {
        return $this->originCities()[0] ?? trim($this->company()->origin);
    }

    /**
     * Active destinations, in the admin's order - the order the country tiles
     * and the rates board use.
     *
     * @return list<string>
     */
    public function countryNames(): array
    {
        $out = [];

        foreach ($this->site->card->destinations as $destination) {
            if ($destination->active && trim($destination->name) !== '') {
                $out[] = trim($destination->name);
            }
        }

        return $out;
    }

    /**
     * "Canada" / "Canada and Germany" / "Australia, Canada, France and Germany"
     * / "A, B, C and 4 more countries". Port of `countryList()` in
     * src/lib/site/copy.ts, so `areaServed` and the share card read the way the
     * headline reads.
     *
     * @param list<string> $names
     */
    public static function countryList(array $names): string
    {
        $n = count($names);

        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $names[0];
        }
        if ($n <= 4) {
            return implode(', ', array_slice($names, 0, -1)) . ' and ' . $names[$n - 1];
        }

        $rest = $n - 3;

        return implode(', ', array_slice($names, 0, 3)) . ' and ' . $rest . ' more ' . ($rest === 1 ? 'country' : 'countries');
    }

    /**
     * The headline sentence: what the owner typed, or the generated
     * "Lahore to Australia, Canada, France and Germany." that `heroTitleAuto()`
     * in src/lib/site/copy.ts generates and the home page prints as its h1.
     *
     * Used for the share card and for `og:image:alt`. With no destinations
     * there is no honest country sentence, so it falls back to the tagline and
     * then to the company name - never to a claim about coverage we cannot
     * back with a rate.
     */
    public function headline(): string
    {
        $typed = trim($this->content()->heroTitle);
        if ($typed !== '') {
            return $typed;
        }

        $list = self::countryList($this->countryNames());
        $from = $this->originPlace();

        if ($list !== '' && $from !== '') {
            return $from . ' to ' . $list . '.';
        }

        $tagline = trim($this->company()->tagline);

        return $tagline !== '' ? $tagline : $this->companyName();
    }

    /**
     * The site-wide description, generated exactly as the Next.js root layout
     * generated it: the service names, the origin, and the channel the business
     * actually books on.
     */
    public function siteDescription(): string
    {
        $names = [];
        foreach ($this->site->card->services as $service) {
            $name = trim($service->name);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        // Lead with the city, not the country. "from Lahore" is what a local
        // customer searches; `originPlace()` is the first pickup city (Lahore),
        // falling back to the country only if no city is set.
        $where = trim($this->originPlace());
        $lead  = $names !== [] ? implode(' and ', $names) . ' courier prices' : 'Courier prices';
        $from  = $where !== '' ? ' from ' . $where : '';

        // The local pickup line carries the neighbourhood names a search is
        // matched on, which is the whole point of a local description. It is
        // true (the owner picks up across Lahore) and matches the visible
        // "Pickup across Lahore" section - the SEO layer's one rule.
        return $lead . $from . ' by country and weight. Free pickup across Lahore '
            . '- DHA, Bahria Town, Model Town and Johar Town. Book on WhatsApp.';
    }

    /**
     * The FAQ, parsed from `content.faq` with the same two calls the FAQ page
     * makes - `Engine::lines()` then `Engine::parts($line, 2)`. Sharing the
     * parser is the whole reason the JSON-LD cannot drift from the rendered
     * answers: there is one parse, not two.
     *
     * Pairs whose question or answer is empty are dropped, because a `FAQPage`
     * entry with an empty `acceptedAnswer` is an invalid entry and a visitor
     * would see nothing there either.
     *
     * @return list<array{question:string,answer:string}>
     */
    public function faqItems(): array
    {
        $out = [];

        foreach (Engine::lines($this->content()->faq) as $line) {
            [$question, $answer] = Engine::parts($line, 2);
            if ($question !== '' && $answer !== '') {
                $out[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $out;
    }

    /**
     * The service list from `content.services`, one entry per line in the
     * `icon | Title | text` form the Services page renders. The icon token is
     * parsed and ignored here exactly as it is there (brief v3 prints no
     * icons).
     *
     * @return list<array{name:string,description:string}>
     */
    public function serviceItems(): array
    {
        $out = [];

        foreach (Engine::lines($this->content()->services) as $line) {
            [, $name, $text] = Engine::parts($line, 3);
            if ($name !== '') {
                $out[] = ['name' => $name, 'description' => $text];
            }
        }

        return $out;
    }

    /**
     * The cheapest 1 kg parcel to each active destination, in the admin's
     * order (the order `areaServed` and the country tiles use).
     *
     * It is computed through `Engine::priceAll()` with the same call the
     * country tiles and the rates board make (1 kg, one piece, cheapest priced
     * service) plus the add-ons that are ticked by default, so each `amount`
     * here IS the "from PKR ..." that destination's tile prints. This is the
     * single source both `priceRange()` (its min and max) and the JSON-LD
     * offer per country read from, so neither can drift from the board.
     *
     * A destination whose every service leaves the 1 kg box blank (the
     * skippable-weight case the board handles by printing only the days) has no
     * priceable 1 kg parcel and is left out - never floored to a guess.
     *
     * Empty when nothing can be priced (an empty rate card, every destination
     * hidden) OR while prices are held: the held page publishes no number, and
     * the structured data must go quiet with it.
     *
     * @return list<array{country:string,amount:float}>
     */
    public function priceFloors(): array
    {
        // Held prices publish nothing. An empty list here means the range and
        // every offer are simply not emitted, exactly as for an empty card.
        if (!$this->pricesVisible) {
            return [];
        }

        $card = $this->site->card;

        $addons = 0.0;
        foreach (Engine::addonsList($card->settings) as $addon) {
            if ($addon->on) {
                $addons += $addon->amount;
            }
        }

        $out = [];

        foreach ($card->destinations as $destination) {
            if (!$destination->active) {
                continue;
            }

            $result = Engine::priceAll($card, $destination->id, [new Piece(kg: 1.0, qty: 1.0)]);
            if (!$result->ok) {
                continue;
            }

            $best = null;
            foreach ($card->services as $service) {
                $price = $result->price($service->id);
                if ($price !== null && ($best === null || $price->total < $best)) {
                    $best = $price->total;
                }
            }

            if ($best === null) {
                continue;
            }

            $out[] = ['country' => trim($destination->name), 'amount' => $best + $addons];
        }

        return $out;
    }

    /**
     * `priceRange` for the LocalBusiness: the cheapest and the dearest 1 kg
     * parcel on the site, in the site's currency - the min and max of
     * `priceFloors()`, so the low end IS the "1 kg from PKR 5,000" the home
     * page prints. A number derived any other way would eventually contradict
     * the page, which is the one thing structured data must never do.
     *
     * Null when nothing can be priced - an empty rate card, every destination
     * hidden, prices held - and the property is then simply not emitted.
     */
    public function priceRange(): ?string
    {
        $amounts = array_column($this->priceFloors(), 'amount');
        if ($amounts === []) {
            return null;
        }

        $currency = $this->site->card->settings->currency;
        $low      = min($amounts);
        $high     = max($amounts);

        return $low === $high
            ? Format::fmtMoney($low, $currency)
            : Format::fmtMoney($low, $currency) . '-' . Format::fmtMoney($high, $currency);
    }

    /** The WhatsApp number as stored: digits only, with the country code. */
    public function whatsappNumber(): string
    {
        return preg_replace('/\D+/', '', $this->company()->whatsapp) ?? '';
    }

    /** The wa.me link the site's own Book buttons use, or "" when no number is set. */
    public function whatsappUrl(): string
    {
        $digits = $this->whatsappNumber();

        return $digits === '' ? '' : 'https://wa.me/' . $digits;
    }

    /** The landline in E.164-ish form for `telephone`, or the WhatsApp number when there is no landline. */
    public function telephone(): string
    {
        $phone = trim($this->company()->phone);
        if ($phone !== '') {
            return $phone;
        }

        $digits = $this->whatsappNumber();

        return $digits === '' ? '' : '+' . $digits;
    }

    /**
     * When the document was last published, as a W3C datetime.
     *
     * Null before the first publish. The sitemap then omits `lastmod` rather
     * than claiming today: "this page changed today" is a claim, and a sitemap
     * that makes it every day is a sitemap crawlers learn to disbelieve.
     */
    public function lastModified(): ?string
    {
        if ($this->version <= 0 || trim($this->publishedAt) === '') {
            return null;
        }

        try {
            $when = new \DateTimeImmutable($this->publishedAt, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $when->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
