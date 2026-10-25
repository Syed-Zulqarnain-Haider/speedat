<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * Every editable sentence on the public site. Port of the `Content` interface
 * in src/lib/site/types.ts as the v3 redesign left it.
 *
 * Three fields are "blank = automatic": heroTitle, heroSub and routesNote are
 * generated from the rate document when the owner leaves them empty, so an
 * empty string here is a decision, not a gap.
 *
 * No key is ever removed. `stepsTitle`, `steps` and `servicesTitle` render
 * nowhere since v3 and are hidden in the admin form, but they stay on the
 * document so a version published before v3 still loads - that is the whole
 * promise of the version history.
 *
 * FIELD ORDER IS PART OF THE BEHAVIOUR. `diffSite` walks the group with
 * `Object.keys()`, and every document reaches it through `migrate()`, which
 * spreads the seed first - so the order below is the SEED literal's order, not
 * the interface's. They differ (the seed puts `services` after `faqLede`), and
 * the seed is the one the owner's review follows.
 */
final class Content
{
    public function __construct(
        /** The home headline; blank = generated from the rate document. */
        public readonly string $heroTitle = '',
        /** The line under it; blank = generated. */
        public readonly string $heroSub = '',
        /** Optional points under the headline, one per line: `Label | Value`. */
        public readonly string $stats = '',
        /** One plain line under the calculator; blank hides it. */
        public readonly string $promise = '',
        /** The rates board caption (home + /services). */
        public readonly string $routesTitle = '',
        /** Second line of the caption; blank = generated. */
        public readonly string $routesNote = '',
        /** Rendered nowhere since v3; hidden in the admin form. */
        public readonly string $stepsTitle = '',
        /** Rendered nowhere since v3; hidden in the admin form. */
        public readonly string $steps = '',
        /** Rendered nowhere since v3; hidden in the admin form. */
        public readonly string $servicesTitle = '',
        /** /services line under the h1. */
        public readonly string $servicesLede = '',
        /** Contact line heading on inner pages, when set. */
        public readonly string $ctaTitle = '',
        /** Contact line sentence on inner pages, when set. */
        public readonly string $ctaSub = '',
        /** /contact intro line. */
        public readonly string $contactLede = '',
        /** /faq intro line. */
        public readonly string $faqLede = '',
        /** One per line: `icon | Title | text` (the icon token is parsed and ignored). */
        public readonly string $services = '',
        /** One paragraph per line. */
        public readonly string $story = '',
        public readonly string $mission = '',
        public readonly string $vision = '',
        /** One per line: `Value | explanation`. */
        public readonly string $values = '',
        public readonly string $address = '',
        public readonly string $hours = '',
        public readonly string $mapUrl = '',
        public readonly string $phone2 = '',
        /** One per line: `Question? | Answer`. */
        public readonly string $faq = '',
    ) {
    }

    /**
     * The keys in the order a migrated document carries them (the seed's order).
     *
     * @var list<string>
     */
    public const FIELDS = [
        'heroTitle', 'heroSub', 'stats', 'promise', 'routesTitle', 'routesNote',
        'stepsTitle', 'steps', 'servicesTitle', 'servicesLede',
        'ctaTitle', 'ctaSub', 'contactLede', 'faqLede',
        'services', 'story', 'mission', 'vision', 'values',
        'address', 'hours', 'mapUrl', 'phone2', 'faq',
    ];

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            heroTitle: Text::of($a['heroTitle'] ?? ''),
            heroSub: Text::of($a['heroSub'] ?? ''),
            stats: Text::of($a['stats'] ?? ''),
            promise: Text::of($a['promise'] ?? ''),
            routesTitle: Text::of($a['routesTitle'] ?? ''),
            routesNote: Text::of($a['routesNote'] ?? ''),
            stepsTitle: Text::of($a['stepsTitle'] ?? ''),
            steps: Text::of($a['steps'] ?? ''),
            servicesTitle: Text::of($a['servicesTitle'] ?? ''),
            servicesLede: Text::of($a['servicesLede'] ?? ''),
            ctaTitle: Text::of($a['ctaTitle'] ?? ''),
            ctaSub: Text::of($a['ctaSub'] ?? ''),
            contactLede: Text::of($a['contactLede'] ?? ''),
            faqLede: Text::of($a['faqLede'] ?? ''),
            services: Text::of($a['services'] ?? ''),
            story: Text::of($a['story'] ?? ''),
            mission: Text::of($a['mission'] ?? ''),
            vision: Text::of($a['vision'] ?? ''),
            values: Text::of($a['values'] ?? ''),
            address: Text::of($a['address'] ?? ''),
            hours: Text::of($a['hours'] ?? ''),
            mapUrl: Text::of($a['mapUrl'] ?? ''),
            phone2: Text::of($a['phone2'] ?? ''),
            faq: Text::of($a['faq'] ?? ''),
        );
    }

    /** @return array<string,string> in FIELDS order */
    public function toArray(): array
    {
        return [
            'heroTitle'     => $this->heroTitle,
            'heroSub'       => $this->heroSub,
            'stats'         => $this->stats,
            'promise'       => $this->promise,
            'routesTitle'   => $this->routesTitle,
            'routesNote'    => $this->routesNote,
            'stepsTitle'    => $this->stepsTitle,
            'steps'         => $this->steps,
            'servicesTitle' => $this->servicesTitle,
            'servicesLede'  => $this->servicesLede,
            'ctaTitle'      => $this->ctaTitle,
            'ctaSub'        => $this->ctaSub,
            'contactLede'   => $this->contactLede,
            'faqLede'       => $this->faqLede,
            'services'      => $this->services,
            'story'         => $this->story,
            'mission'       => $this->mission,
            'vision'        => $this->vision,
            'values'        => $this->values,
            'address'       => $this->address,
            'hours'         => $this->hours,
            'mapUrl'        => $this->mapUrl,
            'phone2'        => $this->phone2,
            'faq'           => $this->faq,
        ];
    }

    /** One field by name; an unknown name is "" rather than a crash, as a missing JSON key would be. */
    public function get(string $field): string
    {
        return $this->toArray()[$field] ?? '';
    }

    /** A copy with one field replaced. @param string $value */
    public function with(string $field, string $value): self
    {
        $a         = $this->toArray();
        $a[$field] = $value;

        return self::fromArray($a);
    }
}
