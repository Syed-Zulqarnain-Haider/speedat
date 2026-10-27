<?php

declare(strict_types=1);

namespace Speedat\Domain\Seo;

use Speedat\Domain\Pricing\Format;
use Speedat\Domain\SiteDoc\Review;

/**
 * The JSON-LD block, built from the published document and nothing else.
 *
 * WHAT GOES IN, PER PAGE (docs/quality-bar.md §1)
 *   every page  LocalBusiness - name, telephone, email, address, opening
 *               hours, areaServed from the live destinations, the logo, the
 *               WhatsApp number as a contactPoint, priceRange.
 *   /           the same LocalBusiness plus aggregateRating and Review, from
 *               the reviews the owner switched on - the home page is the only
 *               page that prints them, so it is the only page that marks
 *               them up.
 *   inner pages BreadcrumbList - Home, then this page.
 *   /faq        FAQPage, built from `content.faq` through the same
 *               `SiteFacts::faqItems()` the page renders from.
 *   /services   one Service per line of `content.services`, which is the same
 *               list the page prints.
 *
 * THE TWO RULES THIS FILE EXISTS TO KEEP
 *
 * 1. IT NEVER CONTRADICTS THE PAGE. Structured data that says something the
 *    visitor cannot see is a manual-action risk and, worse, a lie with a
 *    schema around it. So the FAQ entries and the service entries come out of
 *    the same parse the templates use, the countries are the active
 *    destinations, and `priceRange` is computed by the pricing engine from the
 *    same 1 kg call the country tiles make. A page that does not render a FAQ
 *    does not emit a FAQPage.
 *
 * 2. IT CANNOT BREAK OUT OF THE SCRIPT TAG. `encode()` uses the same flag set
 *    as the `ejson()` template helper - JSON_HEX_TAG | JSON_HEX_AMP |
 *    JSON_HEX_APOS | JSON_HEX_QUOT - so a `</script>` typed into an FAQ answer
 *    is emitted as `</script>` and closes nothing. That is why the
 *    partial may print the result raw, and it is the only raw print in the
 *    head.
 *
 * Empty values are dropped rather than emitted blank: `"telephone": ""` is
 * worse than no telephone, because a validator reads it as a claim.
 */
final class JsonLd
{
    public function __construct(
        private readonly SiteFacts $facts,
        private readonly Url $url,
        private readonly ShareCard $card,
    ) {
    }

    /** The stable identifier every other node points at. */
    public function businessId(): string
    {
        return $this->url->absolute('/') . '#business';
    }

    /**
     * The whole graph for a page.
     *
     * @return array<string,mixed>
     */
    public function graph(Route $route): array
    {
        $business = $this->localBusiness();

        // Reviews in markup must be visible on the page that carries them, and
        // only home.php renders the reviews strip. /faq and /services used to
        // emit the rating and the Review nodes too, describing reviews a
        // visitor to those pages cannot see (found 2026-10-25).
        if ($route->isHome()) {
            $business = $this->withReviews($business);
        }

        $nodes = [$business];

        if (!$route->isHome()) {
            $nodes[] = $this->breadcrumbs($route);
        }

        if ($route->key === 'faq') {
            $faq = $this->faqPage($route);
            if ($faq !== null) {
                $nodes[] = $faq;
            }
        }

        if ($route->key === 'services') {
            foreach ($this->services($route) as $service) {
                $nodes[] = $service;
            }
        }

        return ['@context' => 'https://schema.org', '@graph' => $nodes];
    }

    /** The graph, encoded for a `<script type="application/ld+json">` body. */
    public function render(Route $route): string
    {
        return self::encode($this->graph($route));
    }

    /**
     * JSON that cannot close the script element that holds it.
     *
     * Identical flags to `ejson()` in app/Core/helpers.php, on purpose: one
     * escaping policy for everything this application embeds in a script tag.
     * Slashes stay readable because `<` is already escaped, so `</script>`
     * cannot be formed.
     */
    public static function encode(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
            | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $json === false ? '{}' : $json;
    }

    /** @return array<string,mixed> */
    public function localBusiness(): array
    {
        $facts   = $this->facts;
        $company = $facts->company();
        $content = $facts->content();

        $node = [
            '@type'       => 'LocalBusiness',
            '@id'         => $this->businessId(),
            'name'        => $facts->companyName(),
            'url'         => $this->url->absolute('/'),
            'description' => $facts->siteDescription(),
        ];

        $node = self::put($node, 'slogan', trim($company->tagline));
        $node = self::put($node, 'telephone', $facts->telephone());
        $node = self::put($node, 'email', trim($company->email));

        $address = Address::postal($content->address, $facts->originCities(), $company->origin);
        if ($address !== []) {
            $node['address'] = $address;
        }

        $hours = OpeningHours::parse($content->hours);
        if ($hours !== []) {
            $spec = [];
            foreach ($hours as $line) {
                $spec[] = [
                    '@type'     => 'OpeningHoursSpecification',
                    'dayOfWeek' => $line['dayOfWeek'],
                    'opens'     => $line['opens'],
                    'closes'    => $line['closes'],
                ];
            }
            $node['openingHoursSpecification'] = $spec;
        }

        // The service area has two halves and both belong on a courier: the
        // LOCAL pickup area (Lahore and its neighbourhoods - what a "courier in
        // DHA Lahore" search is about) and the destination countries it ships
        // to. The local area comes first because the local pack is what this
        // business competes in; the countries follow so the international reach
        // is still stated. The per-country Offers below carry the destinations
        // too, so nothing is lost by leading with Lahore here.
        $areas = array_merge($this->localAreasServed(), $this->areaServed());
        if ($areas !== []) {
            $node['areaServed'] = $areas;
        }

        $node['logo'] = [
            '@type'  => 'ImageObject',
            'url'    => $this->card->logoAbsoluteUrl(),
            'width'  => $this->card->logoSize(),
            'height' => $this->card->logoSize(),
        ];
        $node['image'] = $this->card->absoluteUrl();

        $whatsapp = $facts->whatsappUrl();
        if ($whatsapp !== '') {
            $node['contactPoint'] = [[
                '@type'       => 'ContactPoint',
                'contactType' => 'customer service',
                'telephone'   => '+' . $facts->whatsappNumber(),
                'url'         => $whatsapp,
            ]];
            // The public profile the business actually answers on.
            $node['sameAs'] = [$whatsapp];
        }

        $node = self::put($node, 'priceRange', $facts->priceRange() ?? '');
        $node = self::put($node, 'currenciesAccepted', trim($facts->site->card->settings->currency));
        $node = self::put($node, 'hasMap', trim($content->mapUrl));

        $offers = $this->offers();
        if ($offers !== []) {
            $node['makesOffer'] = $offers;
        }

        return $node;
    }

    /**
     * The LocalBusiness node with the customer reviews added - for the home
     * page only, see `graph()`.
     *
     * The customer reviews, and nothing else. Google requires that reviews
     * and an aggregate rating in markup be GENUINE and VISIBLE on the page,
     * so these are built only from the reviews the owner has switched on -
     * the exact list the home-page strip renders (`Reviews::shown()`, which
     * also drops the seed's placeholders) - and a site with none switched on
     * emits neither node rather than an empty or invented one.
     *
     * @param  array<string,mixed> $node
     * @return array<string,mixed>
     */
    public function withReviews(array $node): array
    {
        $shown = $this->facts->site->reviews->shown();
        if ($shown === []) {
            return $node;
        }

        $node['aggregateRating'] = $this->aggregateRating($shown);

        $reviews = $this->reviewNodes($shown);
        if ($reviews !== []) {
            $node['review'] = $reviews;
        }

        return $node;
    }

    /**
     * The aggregate rating over the shown reviews: the mean star count, stated
     * once, with the count it was taken over. `ratingValue` is a whole number
     * when the mean is whole (the common all-five-star case) and one decimal
     * place otherwise, so it never reads as a false precision.
     *
     * @param  list<Review> $reviews a non-empty list
     * @return array<string,mixed>
     */
    public function aggregateRating(array $reviews): array
    {
        $sum = 0;
        foreach ($reviews as $review) {
            $sum += $review->rating;
        }

        $mean = $sum / count($reviews);

        return [
            '@type'       => 'AggregateRating',
            'ratingValue' => self::rating($mean),
            'reviewCount' => count($reviews),
            'bestRating'  => Review::RATING_MAX,
            'worstRating' => Review::RATING_MIN,
        ];
    }

    /**
     * One `Review` node per shown review, in the order the page shows them.
     *
     * A review with no author is dropped from the markup: schema.org's Review
     * requires an author, and a nameless one is a validator error rather than a
     * richer result. It is still counted in the aggregate above and still shown
     * on the page - the markup simply lists the attributable ones. The body is
     * carried only when there is one.
     *
     * @param  list<Review> $reviews
     * @return list<array<string,mixed>>
     */
    public function reviewNodes(array $reviews): array
    {
        $out = [];

        foreach ($reviews as $review) {
            $author = trim($review->author);
            if ($author === '') {
                continue;
            }

            $node = [
                '@type'        => 'Review',
                'author'       => ['@type' => 'Person', 'name' => $author],
                'reviewRating' => [
                    '@type'       => 'Rating',
                    'ratingValue' => $review->rating,
                    'bestRating'  => Review::RATING_MAX,
                    'worstRating' => Review::RATING_MIN,
                ],
            ];

            $body = trim($review->text);
            if ($body !== '') {
                $node['reviewBody'] = $body;
            }

            $out[] = $node;
        }

        return $out;
    }

    /**
     * A star rating as a clean JSON number: an int when whole, otherwise rounded
     * to one decimal place. Returning a number rather than a string keeps it the
     * same shape a validator expects of `ratingValue`.
     */
    private static function rating(float $mean): int|float
    {
        $rounded = round($mean, 1);

        return $rounded === floor($rounded) ? (int) $rounded : $rounded;
    }

    /**
     * One `Offer` per active destination: the cheapest 1 kg parcel to that
     * country, priced in the site's currency exactly as the rates board prints
     * it. This is what lets a search engine or an assistant surface "courier to
     * the UK from PKR X" for a specific country rather than only the site-wide
     * range.
     *
     * The number comes from `SiteFacts::priceFloors()`, the same engine call
     * the country tile makes, so an offer's price cannot differ from the "from
     * PKR ..." a visitor reads - and the whole list disappears the moment the
     * owner holds prices or a card cannot be priced, because `priceFloors()`
     * returns nothing then. `price` is a bare number with no separators, which
     * is the only form schema.org accepts, and it is stated for a reference
     * quantity of exactly one kilogram so it is a fact ("1 kg costs X"), not a
     * dressed-up "from".
     *
     * Emitted only when there is a currency code to state it in: an `Offer`
     * without `priceCurrency` is not an offer a validator accepts.
     *
     * @return list<array<string,mixed>>
     */
    public function offers(): array
    {
        $currency = trim($this->facts->site->card->settings->currency);
        if ($currency === '') {
            return [];
        }

        $out = [];

        foreach ($this->facts->priceFloors() as $floor) {
            $country = $floor['country'];
            if ($country === '') {
                continue;
            }

            $amount = self::amount($floor['amount']);

            $out[] = [
                '@type'              => 'Offer',
                'name'               => 'Courier to ' . $country,
                'priceCurrency'      => $currency,
                'price'              => $amount,
                'availability'       => 'https://schema.org/InStock',
                'areaServed'         => ['@type' => 'Country', 'name' => $country],
                'priceSpecification' => [
                    '@type'             => 'UnitPriceSpecification',
                    'price'             => $amount,
                    'priceCurrency'     => $currency,
                    'referenceQuantity' => [
                        '@type'    => 'QuantitativeValue',
                        'value'    => 1,
                        'unitCode' => 'KGM',
                    ],
                ],
                'itemOffered'        => [
                    '@type'    => 'Service',
                    'name'     => 'International courier to ' . $country,
                    'provider' => ['@id' => $this->businessId()],
                ],
            ];
        }

        return $out;
    }

    /**
     * A price as schema.org wants it: a bare decimal string, no thousands
     * separators and no currency symbol - `5000`, not `PKR 5,000`. Whole
     * amounts (every PKR price on this site, rounded to the ten) print without
     * a decimal point; a fractional currency would keep two places.
     */
    private static function amount(float $value): string
    {
        if (fmod($value, 1.0) === 0.0 && abs($value) < (float) PHP_INT_MAX) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * The countries the site actually prices to, as `areaServed`.
     *
     * @return list<array<string,string>>
     */
    public function areaServed(): array
    {
        $out = [];
        foreach ($this->facts->countryNames() as $name) {
            $out[] = ['@type' => 'Country', 'name' => $name];
        }

        return $out;
    }

    /**
     * The LOCAL pickup area - the city and the Lahore neighbourhoods Speedat
     * serves, as `areaServed` Place entries. This is the half of the service
     * area a local search ("courier in Johar Town Lahore") is matched against,
     * and it reads from `LocalAreas` so it can never disagree with the visible
     * "Pickup across Lahore" section on the page.
     *
     * @return list<array<string,string>>
     */
    public function localAreasServed(): array
    {
        $out = [['@type' => 'City', 'name' => LocalAreas::CITY]];

        foreach (LocalAreas::NEIGHBOURHOODS as $area) {
            $out[] = ['@type' => 'Place', 'name' => $area];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function breadcrumbs(Route $route): array
    {
        $home  = Routes::home();
        $items = [[
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => $home->breadcrumb !== '' ? $home->breadcrumb : 'Home',
            'item'     => $this->url->absolute($home->path),
        ]];

        $items[] = [
            '@type'    => 'ListItem',
            'position' => 2,
            'name'     => $route->breadcrumb !== '' ? $route->breadcrumb : $route->title,
            'item'     => $this->url->absolute($route->path),
        ];

        return [
            '@type'           => 'BreadcrumbList',
            '@id'             => $this->url->absolute($route->path) . '#breadcrumbs',
            'itemListElement' => $items,
        ];
    }

    /**
     * The FAQ, one JSON-LD question per rendered question.
     *
     * Null when the owner has not written any, so an empty FAQ page emits no
     * FAQPage rather than an empty one.
     *
     * @return array<string,mixed>|null
     */
    public function faqPage(Route $route): ?array
    {
        $items = $this->facts->faqItems();
        if ($items === []) {
            return null;
        }

        $questions = [];
        foreach ($items as $item) {
            $questions[] = [
                '@type'          => 'Question',
                'name'           => $item['question'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
            ];
        }

        return [
            '@type'      => 'FAQPage',
            '@id'        => $this->url->absolute($route->path) . '#faq',
            'name'       => $route->title,
            'mainEntity' => $questions,
        ];
    }

    /**
     * One `Service` per line of `content.services` - Express, Normal,
     * Documents, Cargo and whatever else the owner has listed there.
     *
     * No `areaServed` on these. The lines are free text and include local
     * ones - "Pickup: we collect from your door in our pickup cities" - so
     * stamping the destination countries on every entry claimed a Lahore
     * doorstep pickup in the UK, the US and Canada (found 2026-10-25). Which
     * line is local is not something the text says reliably, and the
     * LocalBusiness every Service points at through `provider` already
     * carries both halves of the service area, so leaving it off says
     * nothing false and loses nothing.
     *
     * @return list<array<string,mixed>>
     */
    public function services(Route $route): array
    {
        $base = $this->url->absolute($route->path);
        $out  = [];

        foreach ($this->facts->serviceItems() as $item) {
            $slug = Format::slug($item['name']);
            $node = [
                '@type'       => 'Service',
                '@id'         => $base . '#service-' . ($slug !== '' ? $slug : (string) (count($out) + 1)),
                'name'        => $item['name'],
                'serviceType' => $item['name'],
                'provider'    => ['@id' => $this->businessId()],
            ];

            $out[] = self::put($node, 'description', $item['description']);
        }

        return $out;
    }

    /**
     * Set a key only when there is something to say.
     *
     * @param  array<string,mixed> $node
     * @return array<string,mixed>
     */
    private static function put(array $node, string $key, string $value): array
    {
        if ($value !== '') {
            $node[$key] = $value;
        }

        return $node;
    }
}
