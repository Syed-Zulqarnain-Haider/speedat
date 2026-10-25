<?php

declare(strict_types=1);

namespace Speedat\Tests\View;

use Speedat\Core\Paths;
use Speedat\Core\View as ViewEngine;
use Speedat\Tests\TestCase;
use Speedat\View\Doc;
use Speedat\View\PageView;

/**
 * The base for every site-template test: a real `View`, a real published
 * document, and one deliberately hostile one.
 *
 * The templates are rendered for real - the same engine, the same layout, the
 * same partials the front controller uses - because the bugs worth catching
 * here (an unescaped value, a price that survives the hold, a second h1) live
 * in the markup and not in the view models.
 *
 * Nothing here touches the database.
 */
abstract class ViewTestCase extends TestCase
{
    /**
     * A string that gets out of every context it could be printed in if it is
     * not escaped: it closes a tag, closes an attribute in both quote styles,
     * and would end a <script> block.
     */
    public const HOSTILE = '<script>alert(1)</script>" and \' and </script> & <img src=x onerror=alert(2)>';

    protected function view(): ViewEngine
    {
        $view = new ViewEngine(Paths::root() . '/app/Views', 'test-nonce-value');
        $view->share(['basePath' => '']);

        return $view;
    }

    protected function pageView(Doc $doc, string $path = '/', string $title = 'Get a price', array $extra = []): PageView
    {
        return new PageView(
            doc: $doc,
            path: $path,
            title: $title,
            description: (string) ($extra['description'] ?? 'International courier from Lahore.'),
            theme: (string) ($extra['theme'] ?? PageView::THEME_LIGHT),
            headHtml: (string) ($extra['headHtml'] ?? ''),
            year: 2026,
            scripts: is_array($extra['scripts'] ?? null) ? $extra['scripts'] : ['js/ui.js'],
        );
    }

    /**
     * A believable published document: two services, three countries, grid
     * prices, one default-on add-on, a document rate.
     *
     * @param  array<string,mixed> $overrides merged over the top level
     * @param  array<string,mixed> $hold      `{on, message}`
     */
    protected function doc(array $overrides = [], array $hold = []): Doc
    {
        return Doc::fromArray(array_replace_recursive(self::seed(), $overrides), $hold);
    }

    /**
     * The same document with every string replaced by HOSTILE. The numbers stay
     * numbers, so the page still prices and the escaping test still exercises
     * the parts of the markup that only exist when there IS a price.
     */
    protected function hostileDoc(array $hold = []): Doc
    {
        return Doc::fromArray(self::poison(self::seed()), $hold);
    }

    /** @return array<string,mixed> */
    protected static function seed(): array
    {
        return [
            'version'     => 12,
            'publishedAt' => '2026-09-22T09:14:00.000Z',
            'live'        => false,
            'company'     => [
                'name'         => 'Speedat International Courier',
                'tagline'      => 'Door to door from Lahore',
                'origin'       => 'Pakistan',
                'whatsapp'     => '923157667076',
                'phone'        => '+92 42 3577 1234',
                'email'        => 'hello@speedat.test',
                'includes'     => 'Door-to-door · Pickup on request',
                'originCities' => 'Lahore',
                'notes'        => "Customs duty is paid by the receiver.\nNo liquids or batteries.",
            ],
            'content'     => [
                'heroTitle'    => '',
                'heroSub'      => '',
                'stats'        => "countries | 3\nkg minimum | 0.5",
                'promise'      => 'We confirm every price on WhatsApp before we collect.',
                'routesTitle'  => '',
                'routesNote'   => '',
                'servicesLede' => 'Four ways to send, one number to call.',
                'services'     => "plane | Express | Fastest, tracked door to door\nEconomy | Cheaper, a few days longer",
                'ctaTitle'     => 'Talk to a person',
                'ctaSub'       => 'We answer on WhatsApp within the hour.',
                'contactLede'  => 'The fastest way is WhatsApp.',
                'faqLede'      => 'The things people ask us most.',
                'story'        => "We started in 2014 with one van.\nWe still answer our own phone.",
                'mission'      => 'Get a parcel out of Lahore the same week it is packed.',
                'vision'       => 'The courier a family recommends to another family.',
                'values'       => "Honesty | We quote the price we charge.\nSpeed | We book the same day.",
                'address'      => '12 Main Boulevard, Gulberg III, Lahore',
                'hours'        => 'Mon to Sat, 10 am to 7 pm',
                'mapUrl'       => '',
                'phone2'       => 'Mobile: +92 333 766 7076 · +92 334 77 33 328',
                'faq'          => "How long does it take? | Three to ten working days.\nDo you collect? | Yes, pickup in Lahore is included.",
            ],
            'services'    => [
                ['id' => 'exp', 'name' => 'Express', 'note' => 'Fastest, tracked door to door'],
                ['id' => 'eco', 'name' => 'Economy', 'note' => 'Cheaper, a few days longer'],
            ],
            'settings'    => [
                'pricingMode'       => 'grid',
                'currency'          => 'PKR',
                'volumetricDivisor' => 5000,
                'firstKg'           => 0.5,
                'stepKg'            => 0.5,
                'docMaxKg'          => 0.5,
                'taxPct'            => 0,
                'roundTo'           => 10,
                'maxKg'             => 25,
                'showEta'           => true,
                'cutoffHour'        => 15,
                'workingDays'       => 'Mon, Tue, Wed, Thu, Fri, Sat',
                'holidays'          => '',
                'addons'            => 'Pickup and service charges | 500 | on',
                'disclaimer'        => 'Prices exclude customs duty at the destination.',
            ],
            'destinations' => [
                [
                    'id'     => 'ca',
                    'name'   => 'Canada',
                    'active' => true,
                    'rates'  => [
                        'exp' => ['days' => '4-6', 'doc' => 4100, 'grid' => ['1' => 5220, '2' => 6700, '3' => 8100, '5' => 11000, '10' => 19000, '15' => 27000, '20' => 35000, '25' => 43000]],
                        'eco' => ['days' => '8-12', 'doc' => 3200, 'grid' => ['1' => 4100, '2' => 5400, '3' => 6600, '5' => 9000, '10' => 15000, '15' => 21000, '20' => 27000, '25' => 33000]],
                    ],
                ],
                [
                    'id'     => 'au',
                    'name'   => 'Australia',
                    'active' => true,
                    'rates'  => [
                        'exp' => ['days' => '3-5', 'doc' => 3900, 'grid' => ['1' => 5000, '2' => 6400, '3' => 7700, '5' => 10400, '10' => 18000, '15' => 25500, '20' => 33000, '25' => 40500]],
                        'eco' => ['days' => '7-10', 'doc' => 3000, 'grid' => ['1' => 3900, '2' => 5100, '3' => 6200, '5' => 8500, '10' => 14000, '15' => 20000, '20' => 26000, '25' => 31500]],
                    ],
                ],
                [
                    'id'     => 'zz',
                    'name'   => 'Narnia',
                    'active' => true,
                    'rates'  => [
                        'exp' => ['days' => '6-9', 'grid' => ['1' => 7000, '2' => 9000, '5' => 15000, '10' => 25000]],
                    ],
                ],
                [
                    'id'     => 'de',
                    'name'   => 'Germany',
                    'active' => false,
                    'rates'  => ['exp' => ['days' => '4-6', 'grid' => ['1' => 4800]]],
                ],
            ],
        ];
    }

    /**
     * Every string in the document replaced by HOSTILE, numbers and booleans
     * left alone. The rate `grid` keys are numeric strings the engine reads as
     * weights, so those maps are skipped whole.
     *
     * @param  array<mixed,mixed> $a
     * @return array<mixed,mixed>
     */
    private static function poison(array $a, string $key = ''): array
    {
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = self::poison($v, is_string($k) ? $k : $key);
                continue;
            }
            if (!is_string($v)) {
                continue;
            }
            // Keys the engine parses rather than prints: poisoning them would
            // only prove the parser rejects rubbish, which is its own test.
            if (in_array((string) $k, ['id', 'pricingMode', 'workingDays', 'holidays', 'addons', 'days', 'publishedAt'], true)) {
                continue;
            }
            if ($key === 'grid') {
                continue;
            }
            $a[$k] = self::HOSTILE;
        }

        return $a;
    }

    /**
     * Fail when the hostile string survived into the output as live markup.
     *
     * It checks for the OPENING TAG rather than the whole string: an escaped
     * value still contains the words "script" and "alert(1)", so searching for
     * those would pass on nothing. `<script>` and `<img ` cannot appear from an
     * escaped value at all, and the two the page legitimately prints
     * (`<script type="application/json">` and `<script type="application/ld+json">`)
     * are named, so this catches a bare one.
     *
     * The event handler is COUNTED rather than banned, because neither escaper
     * touches the `=` or the space - only the angle brackets around them:
     *
     *   `htmlspecialchars` leaves `&lt;img src=x onerror=alert(2)&gt;` as text;
     *   `JSON_HEX_TAG` leaves the same run with the brackets written as their
     *   `u003C` / `u003E` escapes inside the calculator's JSON block.
     *
     * Both are inert - the tag never opens. So the rule is that every
     * `onerror=` on the page must be one of those two; one that is not is a
     * handler a browser would actually run.
     */
    protected function assertNoInjection(string $html, string $where): void
    {
        $this->assertStringNotContains('<script>alert', $html, $where . ': a value was printed as a live <script>');
        $this->assertStringNotContains('<img src=x', $html, $where . ': a value was printed as a live <img>');
        // `<` the way JSON_HEX_TAG writes it, spelled with chr() so that nothing
        // between here and the file can turn the escape back into the character.
        $hexLt = chr(92) . 'u003C';
        $inert = substr_count($html, '&lt;img src=x onerror=')
            + substr_count($html, $hexLt . 'img src=x onerror=');
        $this->assertSame(
            $inert,
            substr_count($html, 'onerror='),
            $where . ': a value carried a live event handler into the markup'
        );
        $this->assertStringNotContains('</script>" and', $html, $where . ': a value closed a tag');
        // A value that broke out of an attribute would put its own quote next to
        // its own text. Neither quote style may.
        $this->assertStringNotContains('alert(1)</script>"', $html, $where . ': a value broke out of a double-quoted attribute');
        $this->assertStringNotContains("alert(1)</script>'", $html, $where . ': a value broke out of a single-quoted attribute');
    }

    /** Exactly one h1 per page - the quality bar's rule, checked rather than reviewed. */
    protected function assertOneH1(string $html, string $where): void
    {
        $this->assertSame(1, substr_count($html, '<h1'), $where . ': expected exactly one <h1>');
    }

    /**
     * The contents of a `<script type="application/json" id="...">` block, or
     * null when the page did not print one.
     */
    protected function jsonBlob(string $html, string $id): ?string
    {
        $pattern = '#<script type="application/json" id="' . preg_quote($id, '#') . '">(.*?)</script>#s';

        return preg_match($pattern, $html, $m) === 1 ? $m[1] : null;
    }
}
