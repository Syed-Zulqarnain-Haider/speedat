<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Core\Money;
use Speedat\Domain\Pricing\Settings;

/**
 * The Settings section of the editor, as a list of fields. Port of
 * SettingsForm.tsx - same labels, same hints, same order, because the owner
 * has learnt where each box is and a reordered form is a form he has to read
 * again.
 *
 * Everything posts inside the one autosaving editor form, under
 * `company[...]` and `settings[...]`, so a settings change and a price change
 * are one save and one version.
 */
final class SettingsFields
{
    /**
     * @return list<array{heading:string,rows:list<array{type:string,fields:list<Field>}>}>
     */
    public static function groups(Site $site, bool $readOnly = false): array
    {
        return [
            ['heading' => 'Company', 'rows' => [['type' => 'grid2', 'fields' => self::company($site, $readOnly)]]],
            ['heading' => 'Pricing', 'rows' => [['type' => 'grid2', 'fields' => self::pricing($site->settings(), $readOnly)]]],
            ['heading' => 'Delivery estimates', 'rows' => self::delivery($site->settings(), $readOnly)],
            ['heading' => 'Under the prices', 'rows' => self::underPrices($site, $readOnly)],
        ];
    }

    /** @return list<Field> */
    private static function company(Site $site, bool $readOnly): array
    {
        $c = static fn (string $key): string => $site->company($key);

        return [
            Field::text('company[name]', 'Company name', $c('name'), '', '', 120, $readOnly),
            Field::text('company[tagline]', 'Tagline', $c('tagline'), '', '', 160, $readOnly),
            Field::tel('company[whatsapp]', 'WhatsApp number (country code, digits only)', $c('whatsapp'), '923001234567', '', $readOnly),
            Field::text('company[phone]', 'Phone shown on site', $c('phone'), '', '', 40, $readOnly),
            Field::email('company[email]', 'Email shown on site', $c('email'), '', $readOnly),
            Field::text('company[origin]', 'Shipping from', $c('origin'), '', '', 120, $readOnly),
            Field::text('company[includes]', 'What is included (shown under prices)', $c('includes'), "Door-to-door \u{b7} Pickup \u{b7} Tracking", '', 200, $readOnly),
            Field::text('company[originCities]', "Pickup cities, comma-separated (shown as \u{201c}From\u{201d})", $c('originCities'), 'Lahore', '', 200, $readOnly),
        ];
    }

    /** @return list<Field> */
    private static function pricing(Settings $st, bool $readOnly): array
    {
        return [
            Field::select(
                'settings[pricingMode]',
                'Pricing model',
                $st->pricingMode,
                [
                    'grid' => "Price per kilogram (1 kg \u{2026} cargo threshold)",
                    'slab' => 'First slab + each additional step',
                ],
                'Per-kilogram: every country gets a price box for each whole kg; parcels are charged at the next whole kg up.',
                $readOnly,
            ),
            Field::text('settings[currency]', 'Currency shown with prices (e.g. PKR or Rs.)', $st->currency, '', '', 12, $readOnly),
            Field::number('settings[volumetricDivisor]', "Volumetric divisor (L\u{d7}W\u{d7}H \u{f7} this = kg)", self::n($st->volumetricDivisor), 'any', '', '', $readOnly),
            Field::number('settings[firstKg]', 'First weight slab (kg)', self::n($st->firstKg), '0.1', '', '', $readOnly),
            Field::number('settings[stepKg]', 'Additional weight step (kg)', self::n($st->stepKg), '0.1', '', '', $readOnly),
            Field::number('settings[taxPct]', 'Tax added on top (%)', self::n($st->taxPct), '0.5', '', '', $readOnly),
            Field::number('settings[roundTo]', 'Round final price to nearest', self::n($st->roundTo), '1', '', '', $readOnly),
            Field::number('settings[maxKg]', "Cargo threshold (kg) - above this, customers are asked to message you (0 = no limit)", self::n($st->maxKg), '1', '', '', $readOnly),
            // There is no "Document rate applies up to (kg)" box. The owner
            // removed the flat document rate on 2026-09-27: everything is
            // priced by weight, so the limit had nothing left to limit.
        ];
    }

    /** @return list<array{type:string,fields:list<Field>}> */
    private static function delivery(Settings $st, bool $readOnly): array
    {
        return [
            ['type' => 'grid2', 'fields' => [
                Field::number(
                    'settings[cutoffHour]',
                    "Same-day pickup cutoff hour, 0\u{2013}23 (blank = none)",
                    $st->cutoffHour === null ? '' : self::n($st->cutoffHour),
                    '1',
                    '15',
                    '',
                    $readOnly,
                ),
                Field::text('settings[workingDays]', 'Working days counted for delivery estimates', $st->workingDays, 'Mon, Tue, Wed, Thu, Fri, Sat', '', 120, $readOnly),
            ]],
            ['type' => 'field', 'fields' => [
                Field::area(
                    'settings[holidays]',
                    'Holidays skipped by delivery estimates (one per line: YYYY-MM-DD | name)',
                    $st->holidays,
                    "2026-12-25 | Quaid-e-Azam Day\n2027-03-23 | Pakistan Day",
                    '',
                    false,
                    4000,
                    $readOnly,
                ),
            ]],
            ['type' => 'check', 'fields' => [
                Field::check(
                    'settings[showEta]',
                    'Show estimated delivery dates on quotes (transit days counted on the working days above, from the pickup date)',
                    $st->showEta,
                    '1',
                    $readOnly,
                ),
            ]],
        ];
    }

    /** @return list<array{type:string,fields:list<Field>}> */
    private static function underPrices(Site $site, bool $readOnly): array
    {
        $st = $site->settings();

        return [
            ['type' => 'field', 'fields' => [
                Field::area(
                    'settings[addons]',
                    'Delivery charge and other add-ons, one per line: Label | Amount | on or off. A line set to on is added to every quote (this is the delivery charge, shown as a separate line); off lets the customer tick it.',
                    $st->addons,
                    'Delivery | 1000 | on',
                    '',
                    false,
                    4000,
                    $readOnly,
                ),
            ]],
            ['type' => 'field', 'fields' => [
                Field::area('settings[disclaimer]', 'Small print under the prices', $st->disclaimer, '', '', false, 4000, $readOnly),
            ]],
            ['type' => 'field', 'fields' => [
                Field::area('company[notes]', 'Good to know before you book (one point per line, shown under the prices)', $site->company('notes'), '', '', false, 4000, $readOnly),
            ]],
        ];
    }

    /** A number in a box the way JavaScript would print it: 0.5, not 0.50000. */
    private static function n(float $v): string
    {
        return Money::numberToString($v);
    }
}
