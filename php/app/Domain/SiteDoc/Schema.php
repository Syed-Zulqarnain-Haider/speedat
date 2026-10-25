<?php

declare(strict_types=1);

namespace Speedat\Domain\SiteDoc;

/**
 * The shape and size check for a site document as the admin UI sends it.
 * Port of the Zod schema in src/lib/site/schema.ts.
 *
 * SHAPES AND SIZES ONLY. Business rules - positive prices, one visible
 * destination, a WhatsApp number that could be dialled - live in `Validate`,
 * because the draft may legitimately be mid-edit and still has to save.
 *
 * Why the caps matter more than they look: every one of them is at or below
 * what the database column will take. A 20 000-character FAQ pasted out of a
 * Word document is refused here, politely, naming the field - not by MySQL's
 * STRICT_TRANS_TABLES halfway through a transaction, where the owner would see
 * "Something went wrong" and lose the edit. A long value is a polite
 * rejection, never a 500.
 *
 * THE MESSAGES ARE ZOD 4's OWN, WORD FOR WORD. They are what the admin screen
 * prints and what `publishVersion` puts in its refusal, and the same document
 * is validated by the Next.js app against zod 4.6.5, so the two halves of one
 * product must not disagree about why an edit was refused. They were checked
 * against the installed zod by running it, not from memory: zod 4 rewrote every
 * one of these strings ("Too big: expected string to have <=200 characters",
 * where zod 3 said "String must contain at most 200 character(s)").
 *
 * LENGTHS ARE COUNTED IN CODE POINTS, which is what zod 4 counts - it measures
 * a string with `[...s].length`, not `s.length`, so one emoji is one character
 * and not two. `mb_strlen` counts the same thing. (The price hold's own cap is
 * NOT this: it is a plain JavaScript `slice`, and `HoldState` counts UTF-16
 * code units there for exactly that reason.)
 */
final class Schema
{
    /** The largest money value a rate may carry, from `money` in schema.ts. */
    public const MONEY_MAX = 100000000;

    public const MAX_SERVICES     = 6;
    public const MAX_DESTINATIONS = 500;
    public const MAX_PROFILES     = 20;
    public const MAX_REVIEWS      = 50;

    /** `Number.MAX_SAFE_INTEGER`: zod's ceiling for `z.number().int()`. */
    private const MAX_SAFE_INT = 9007199254740991;

    /** The id pattern, printed into the message the way zod prints a regex. */
    private const SLUG = '/^[a-z0-9-]+$/';

    /** The grid key pattern: whole kilograms, 1 to 999. */
    private const GRID_KEY = '/^\d{1,3}$/';

    /** Text caps, by group. @var array<string,array<string,int>> */
    private const CAPS = [
        'company' => [
            'name' => 120, 'tagline' => 120, 'origin' => 80, 'whatsapp' => 20, 'phone' => 40,
            'email' => 120, 'includes' => 300, 'originCities' => 300, 'notes' => 3000,
        ],
        'content' => [
            'heroTitle' => 200, 'heroSub' => 400, 'stats' => 1000, 'promise' => 240,
            'routesTitle' => 120, 'routesNote' => 300, 'stepsTitle' => 120, 'steps' => 2000,
            'servicesTitle' => 120, 'servicesLede' => 400, 'services' => 6000,
            'ctaTitle' => 120, 'ctaSub' => 300, 'contactLede' => 400, 'faqLede' => 300,
            'story' => 6000, 'mission' => 600, 'vision' => 600, 'values' => 3000,
            'address' => 300, 'hours' => 120, 'mapUrl' => 500, 'phone2' => 80, 'faq' => 12000,
        ],
        'settings' => [
            'currency' => 8, 'workingDays' => 120, 'holidays' => 4000, 'addons' => 2000, 'disclaimer' => 2000,
        ],
        'promo' => [
            'title' => 120, 'message' => 300, 'ctaLabel' => 60, 'ctaHref' => 300,
        ],
        'review' => [
            'author' => 120, 'text' => 1000, 'date' => 60,
        ],
    ];

    /**
     * Settings that must be finite numbers.
     *
     * `docMaxKg` is NOT here any more (the document rate left the product on
     * 2026-09-27), and that is what lets every already-stored version keep
     * loading: an unlisted key is not checked, so a v1 document carrying
     * `docMaxKg` and per-rate `doc` prices still passes the schema. Migrate
     * drops both.
     *
     * @var list<string>
     */
    private const SETTING_NUMBERS = [
        'volumetricDivisor', 'firstKg', 'stepKg', 'taxPct', 'roundTo', 'maxKg',
    ];

    /**
     * Every problem with the document, in the order zod reports them.
     *
     * @param  array<array-key,mixed> $doc
     * @return list<SchemaIssue>
     */
    public static function check(array $doc): array
    {
        $issues = [];

        self::boolean($issues, 'live', $doc, 'live');
        self::group($issues, 'company', $doc, self::CAPS['company']);
        self::settings($issues, $doc);
        self::services($issues, $doc);
        self::destinations($issues, $doc);
        self::group($issues, 'content', $doc, self::CAPS['content']);
        self::promo($issues, $doc);
        self::reviews($issues, $doc);
        self::profiles($issues, $doc);

        return $issues;
    }

    /** True when the document may be stored. @param array<array-key,mixed> $doc */
    public static function accepts(array $doc): bool
    {
        return self::check($doc) === [];
    }

    /** The first problem, or null. @param array<array-key,mixed> $doc */
    public static function firstIssue(array $doc): ?SchemaIssue
    {
        return self::check($doc)[0] ?? null;
    }

    /* ------------------------------------------------------------- groups */

    /**
     * @param list<SchemaIssue>      $issues
     * @param array<array-key,mixed> $doc
     * @param array<string,int>      $caps
     */
    private static function group(array &$issues, string $path, array $doc, array $caps): void
    {
        $value = self::object($issues, $path, $doc, $path);
        if ($value === null) {
            return;
        }

        foreach ($caps as $field => $max) {
            self::text($issues, $path . '.' . $field, $value, $field, $max);
        }
    }

    /**
     * @param list<SchemaIssue>      $issues
     * @param array<array-key,mixed> $doc
     */
    private static function settings(array &$issues, array $doc): void
    {
        $value = self::object($issues, 'settings', $doc, 'settings');
        if ($value === null) {
            return;
        }

        // `z.enum` says the same thing whether the value is wrong or absent: it
        // never names what it received.
        $mode = $value['pricingMode'] ?? null;
        if ($mode !== 'slab' && $mode !== 'grid') {
            $issues[] = new SchemaIssue('settings.pricingMode', 'Invalid option: expected one of "slab"|"grid"');
        }

        self::text($issues, 'settings.currency', $value, 'currency', self::CAPS['settings']['currency']);

        foreach (self::SETTING_NUMBERS as $field) {
            self::number($issues, 'settings.' . $field, $value, $field);
        }

        self::boolean($issues, 'settings.showEta', $value, 'showEta');

        // `num.nullable()`: the key must be there, but null is a legitimate
        // "no cutoff" - it is how the owner says pickups have no deadline.
        if (!array_key_exists('cutoffHour', $value)) {
            $issues[] = new SchemaIssue('settings.cutoffHour', self::expected('number', self::missing()));
        } elseif ($value['cutoffHour'] !== null) {
            self::number($issues, 'settings.cutoffHour', $value, 'cutoffHour');
        }

        foreach (['workingDays', 'holidays', 'addons', 'disclaimer'] as $field) {
            self::text($issues, 'settings.' . $field, $value, $field, self::CAPS['settings'][$field]);
        }
    }

    /**
     * @param list<SchemaIssue>      $issues
     * @param array<array-key,mixed> $doc
     */
    private static function services(array &$issues, array $doc): void
    {
        $value = self::listOf($issues, 'services', $doc, 'services');
        if ($value === null) {
            return;
        }

        foreach ($value as $i => $service) {
            $at = 'services.' . $i;

            if (!self::isObject($service)) {
                $issues[] = new SchemaIssue($at, self::expected('object', $service));
                continue;
            }

            self::text($issues, $at . '.id', $service, 'id', 40, self::SLUG);
            self::text($issues, $at . '.name', $service, 'name', 60);
            self::text($issues, $at . '.note', $service, 'note', 200);
        }

        // Zod reports the elements first and the array's own ceiling last.
        self::tooMany($issues, 'services', $value, self::MAX_SERVICES);
    }

    /**
     * @param list<SchemaIssue>      $issues
     * @param array<array-key,mixed> $doc
     */
    private static function destinations(array &$issues, array $doc): void
    {
        $value = self::listOf($issues, 'destinations', $doc, 'destinations');
        if ($value === null) {
            return;
        }

        foreach ($value as $i => $dest) {
            $at = 'destinations.' . $i;

            if (!self::isObject($dest)) {
                $issues[] = new SchemaIssue($at, self::expected('object', $dest));
                continue;
            }

            self::text($issues, $at . '.id', $dest, 'id', 60, self::SLUG);
            self::text($issues, $at . '.name', $dest, 'name', 120);
            self::boolean($issues, $at . '.active', $dest, 'active');

            $rates = self::record($issues, $at . '.rates', $dest, 'rates');
            if ($rates === null) {
                continue;
            }

            // A record is reported entry by entry, and an entry whose key is
            // refused has its value left unread.
            foreach (self::jsKeyOrder($rates) as $serviceId => $rate) {
                $key = (string) $serviceId;

                if (mb_strlen($key, 'UTF-8') > 40) {
                    $issues[] = new SchemaIssue($at . '.rates.' . $key, 'Invalid key in record');
                    continue;
                }

                self::rate($issues, $at . '.rates.' . $key, $rate);
            }
        }

        self::tooMany($issues, 'destinations', $value, self::MAX_DESTINATIONS);
    }

    /** @param list<SchemaIssue> $issues */
    private static function rate(array &$issues, string $at, mixed $rate): void
    {
        if (!self::isObject($rate)) {
            // Optional means absent, not null: `{"express": null}` is refused.
            $issues[] = new SchemaIssue($at, self::expected('object', $rate));

            return;
        }

        foreach (['first', 'addl'] as $field) {
            self::money($issues, $at . '.' . $field, $rate, $field);
        }

        if (array_key_exists('days', $rate)) {
            self::text($issues, $at . '.days', $rate, 'days', 40);
        }

        // A stored `doc` price is deliberately NOT checked: the document rate
        // left the product on 2026-09-27, and an old version that still carries
        // one has to keep loading. Migrate drops the key.

        if (!array_key_exists('grid', $rate)) {
            return;
        }

        $grid = $rate['grid'];
        if (!self::isObject($grid)) {
            $issues[] = new SchemaIssue($at . '.grid', self::expected('record', $grid));

            return;
        }

        foreach (self::jsKeyOrder($grid) as $kg => $price) {
            $key = (string) $kg;

            // The key pattern is the contract with the engine: whole kilograms,
            // 1 to 999. A "2.5" box would never be found by gridPrice and the
            // customer would be quoted the next heavier kilogram instead.
            if (preg_match(self::GRID_KEY, $key) !== 1) {
                $issues[] = new SchemaIssue($at . '.grid.' . $key, 'Invalid key in record');
                continue;
            }

            if (!self::isNumber($price) || !is_finite((float) $price)) {
                $issues[] = new SchemaIssue($at . '.grid.' . $key, self::expected('number', $price));
                continue;
            }

            self::range($issues, $at . '.grid.' . $key, $price);
        }
    }

    /**
     * The promo popup: a boolean switch and four capped text fields.
     *
     * OPTIONAL, unlike company/settings/content, and deliberately so. The block
     * was added after the first documents were published, so a stored version
     * from before it carries no `promo` key at all - and an absent promo is "no
     * popup", not a fault. Only a promo that IS present is checked, the same way
     * an unlisted `docMaxKg` is simply ignored rather than refused. A fresh
     * document does carry it (the seed sets it, disabled), so the common case is
     * still validated end to end.
     *
     * @param list<SchemaIssue>      $issues
     * @param array<array-key,mixed> $doc
     */
    private static function promo(array &$issues, array $doc): void
    {
        if (!array_key_exists('promo', $doc)) {
            return;
        }

        $value = self::object($issues, 'promo', $doc, 'promo');
        if ($value === null) {
            return;
        }

        self::boolean($issues, 'promo.enabled', $value, 'enabled');

        foreach (self::CAPS['promo'] as $field => $max) {
            self::text($issues, 'promo.' . $field, $value, $field, $max);
        }
    }

    /**
     * The customer reviews: a list of small objects, each a name, a star count,
     * a line of text, a free-text date and a show switch.
     *
     * OPTIONAL, exactly like the promo block and for the same reason: reviews
     * were added after the first documents were published, so a stored version
     * from before them carries no `reviews` key at all, and an absent list is
     * "no reviews", not a fault. A fresh document does carry it (the seed sets a
     * few samples), so the common case is validated end to end. The star count
     * is checked only for being a whole number here; {@see Review} clamps it to
     * 1 - 5 on the way in, so the shape check does not also police the range.
     *
     * @param list<SchemaIssue>      $issues
     * @param array<array-key,mixed> $doc
     */
    private static function reviews(array &$issues, array $doc): void
    {
        if (!array_key_exists('reviews', $doc)) {
            return;
        }

        $value = self::listOf($issues, 'reviews', $doc, 'reviews');
        if ($value === null) {
            return;
        }

        foreach ($value as $i => $review) {
            $at = 'reviews.' . $i;

            if (!self::isObject($review)) {
                $issues[] = new SchemaIssue($at, self::expected('object', $review));
                continue;
            }

            self::text($issues, $at . '.author', $review, 'author', self::CAPS['review']['author']);
            self::integer($issues, $at . '.rating', $review['rating'] ?? self::missing());
            self::text($issues, $at . '.text', $review, 'text', self::CAPS['review']['text']);
            self::text($issues, $at . '.date', $review, 'date', self::CAPS['review']['date']);
            self::boolean($issues, $at . '.show', $review, 'show');
        }

        self::tooMany($issues, 'reviews', $value, self::MAX_REVIEWS);
    }

    /**
     * @param list<SchemaIssue>      $issues
     * @param array<array-key,mixed> $doc
     */
    private static function profiles(array &$issues, array $doc): void
    {
        $value = self::listOf($issues, 'importProfiles', $doc, 'importProfiles');
        if ($value === null) {
            return;
        }

        foreach ($value as $i => $profile) {
            $at = 'importProfiles.' . $i;

            if (!self::isObject($profile)) {
                $issues[] = new SchemaIssue($at, self::expected('object', $profile));
                continue;
            }

            self::text($issues, $at . '.signature', $profile, 'signature', 4000);
            self::text($issues, $at . '.name', $profile, 'name', 200);

            $map = self::record($issues, $at . '.map', $profile, 'map');
            if ($map !== null) {
                foreach (self::jsKeyOrder($map) as $key => $column) {
                    $name = (string) $key;

                    if (mb_strlen($name, 'UTF-8') > 60) {
                        $issues[] = new SchemaIssue($at . '.map.' . $name, 'Invalid key in record');
                        continue;
                    }

                    self::integer($issues, $at . '.map.' . $name, $column);
                }
            }

            self::text($issues, $at . '.savedAt', $profile, 'savedAt', 40);
            self::boolean($issues, $at . '.cost', $profile, 'cost');
            self::number($issues, $at . '.margin', $profile, 'margin');
            self::number($issues, $at . '.mround', $profile, 'mround');
        }

        self::tooMany($issues, 'importProfiles', $value, self::MAX_PROFILES);
    }

    /* ------------------------------------------------------------- fields */

    /**
     * @param  list<SchemaIssue>      $issues
     * @param  array<array-key,mixed> $c
     * @return array<array-key,mixed>|null
     */
    private static function object(array &$issues, string $at, array $c, string $key): ?array
    {
        return self::container($issues, $at, $c, $key, 'object');
    }

    /**
     * A `z.record`. Zod names it "record", not "object", when it refuses one.
     *
     * @param  list<SchemaIssue>      $issues
     * @param  array<array-key,mixed> $c
     * @return array<array-key,mixed>|null
     */
    private static function record(array &$issues, string $at, array $c, string $key): ?array
    {
        return self::container($issues, $at, $c, $key, 'record');
    }

    /**
     * @param  list<SchemaIssue>      $issues
     * @param  array<array-key,mixed> $c
     * @return array<array-key,mixed>|null
     */
    private static function container(array &$issues, string $at, array $c, string $key, string $want): ?array
    {
        $value = array_key_exists($key, $c) ? $c[$key] : self::missing();

        if (!self::isObject($value)) {
            $issues[] = new SchemaIssue($at, self::expected($want, $value));

            return null;
        }

        /** @var array<array-key,mixed> */
        return $value;
    }

    /**
     * @param  list<SchemaIssue>      $issues
     * @param  array<array-key,mixed> $c
     * @return list<mixed>|null
     */
    private static function listOf(array &$issues, string $at, array $c, string $key): ?array
    {
        $value = array_key_exists($key, $c) ? $c[$key] : self::missing();

        if (!is_array($value) || !array_is_list($value)) {
            $issues[] = new SchemaIssue($at, self::expected('array', $value));

            return null;
        }

        return $value;
    }

    /** @param list<SchemaIssue> $issues @param list<mixed> $value */
    private static function tooMany(array &$issues, string $at, array $value, int $max): void
    {
        if (count($value) > $max) {
            $issues[] = new SchemaIssue($at, 'Too big: expected array to have <=' . $max . ' items');
        }
    }

    /**
     * A record's entries in the order JavaScript would walk them, so the two
     * apps list the same problems with one document in the same order.
     *
     * JavaScript does not keep an object's keys in insertion order: keys that
     * are canonical array indices come first, in ascending numeric order, and
     * only the remaining string keys keep the order they were added. PHP keeps
     * everything in insertion order. A grid of `{"5": …, "1": …}` is therefore
     * walked 1, 5 there and 5, 1 here, and the owner would be shown the same two
     * mistakes in a different order depending on which app refused the document.
     *
     * The two languages agree on WHICH keys those are: PHP turns exactly the
     * canonical integer strings into int keys when it decodes JSON ("3" but not
     * "03" and not "2.5"), which is the same rule JavaScript uses for an array
     * index. So the int keys are the ones to sort.
     *
     * @param  array<array-key,mixed> $record
     * @return array<array-key,mixed>
     */
    private static function jsKeyOrder(array $record): array
    {
        $ints    = [];
        $strings = [];

        foreach ($record as $key => $value) {
            if (is_int($key) && $key >= 0) {
                $ints[$key] = $value;
            } else {
                $strings[$key] = $value;
            }
        }

        ksort($ints, SORT_NUMERIC);

        return $ints + $strings;
    }

    /**
     * @param list<SchemaIssue>      $issues
     * @param array<array-key,mixed> $c
     */
    private static function text(array &$issues, string $at, array $c, string $key, int $max, ?string $pattern = null): void
    {
        $value = array_key_exists($key, $c) ? $c[$key] : self::missing();

        if (!is_string($value)) {
            $issues[] = new SchemaIssue($at, self::expected('string', $value));

            return;
        }

        if (mb_strlen($value, 'UTF-8') > $max) {
            $issues[] = new SchemaIssue($at, 'Too big: expected string to have <=' . $max . ' characters');
        }

        if ($pattern !== null && preg_match($pattern, $value) !== 1) {
            $issues[] = new SchemaIssue($at, 'Invalid string: must match pattern ' . $pattern);
        }
    }

    /**
     * @param list<SchemaIssue>      $issues
     * @param array<array-key,mixed> $c
     */
    private static function boolean(array &$issues, string $at, array $c, string $key): void
    {
        $value = array_key_exists($key, $c) ? $c[$key] : self::missing();

        if (!is_bool($value)) {
            $issues[] = new SchemaIssue($at, self::expected('boolean', $value));
        }
    }

    /**
     * @param list<SchemaIssue>      $issues
     * @param array<array-key,mixed> $c
     */
    private static function number(array &$issues, string $at, array $c, string $key): void
    {
        $value = array_key_exists($key, $c) ? $c[$key] : self::missing();

        if (!self::isNumber($value) || !is_finite((float) $value)) {
            // `.finite()` is not a separate complaint in zod 4: a non-finite
            // number is simply "not a number", named as what it is.
            $issues[] = new SchemaIssue($at, self::expected('number', $value));
        }
    }

    /** `z.number().int()`. @param list<SchemaIssue> $issues */
    private static function integer(array &$issues, string $at, mixed $value): void
    {
        if (!self::isNumber($value) || !is_finite((float) $value)) {
            $issues[] = new SchemaIssue($at, self::expected('number', $value));

            return;
        }

        if ((float) $value !== floor((float) $value)) {
            // A number that is not whole is refused as "not an int", which is a
            // different sentence from "not a number".
            $issues[] = new SchemaIssue($at, 'Invalid input: expected int, received number');

            return;
        }

        if (abs((float) $value) > self::MAX_SAFE_INT) {
            $issues[] = new SchemaIssue($at, 'Too big: expected int to be <=' . self::MAX_SAFE_INT);
        }
    }

    /**
     * `money`: optional and nullable, between 0 and 100 000 000.
     *
     * @param list<SchemaIssue>      $issues
     * @param array<array-key,mixed> $c
     */
    private static function money(array &$issues, string $at, array $c, string $key): void
    {
        if (!array_key_exists($key, $c) || $c[$key] === null) {
            return;
        }

        $value = $c[$key];
        if (!self::isNumber($value) || !is_finite((float) $value)) {
            $issues[] = new SchemaIssue($at, self::expected('number', $value));

            return;
        }

        self::range($issues, $at, $value);
    }

    /** @param list<SchemaIssue> $issues */
    private static function range(array &$issues, string $at, int|float $value): void
    {
        if ($value < 0) {
            $issues[] = new SchemaIssue($at, 'Too small: expected number to be >=0');
        }
        if ($value > self::MONEY_MAX) {
            $issues[] = new SchemaIssue($at, 'Too big: expected number to be <=' . self::MONEY_MAX);
        }
    }

    /* ---------------------------------------------------------- internals */

    /**
     * The absence of a key, as a value. PHP has no `undefined`, and null is a
     * legitimate stored value here (a blank price, a blank cutoff), so the two
     * cannot share a representation: zod says "received undefined" for one and
     * "received null" for the other, and the owner is told which.
     *
     * It is an object, deliberately, and not a magic string: a string sentinel
     * passes `is_string()`, so a missing text field would have been read as a
     * present one and a document with a whole field missing would have been
     * accepted. An object is of a type no field may hold, so every check
     * refuses it and names it "undefined".
     */
    private static function missing(): object
    {
        static $missing = null;

        return $missing ??= new \stdClass();
    }

    private static function isNumber(mixed $v): bool
    {
        return is_int($v) || is_float($v);
    }

    /**
     * A JSON object: a PHP array that is not a non-empty list. `[]` is both an
     * empty array and an empty object in JSON, and the document uses it for
     * both, so it counts as either.
     */
    private static function isObject(mixed $v): bool
    {
        return is_array($v) && !(array_is_list($v) && $v !== []);
    }

    private static function expected(string $want, mixed $got): string
    {
        return 'Invalid input: expected ' . $want . ', received ' . self::typeOf($got);
    }

    /**
     * What zod prints after "received": JavaScript's `typeof`, with null, arrays
     * and the two numbers that are not numbers called out by name.
     */
    private static function typeOf(mixed $v): string
    {
        return match (true) {
            $v === self::missing()                      => 'undefined',
            $v === null                               => 'null',
            is_bool($v)                               => 'boolean',
            is_float($v) && is_nan($v)                => 'NaN',
            is_float($v) && is_infinite($v)           => $v > 0 ? 'Infinity' : '-Infinity',
            is_int($v), is_float($v)                  => 'number',
            is_string($v)                             => 'string',
            is_array($v)                              => array_is_list($v) && $v !== [] ? 'array' : 'object',
            default                                   => 'unknown',
        };
    }
}
