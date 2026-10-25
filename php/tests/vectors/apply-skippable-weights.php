<?php

declare(strict_types=1);

/**
 * Re-derive php/tests/vectors/pricing.json for SKIPPABLE WEIGHT PRICES.
 *
 * OWNER DECISION, 2026-09-28: "if I don't want to add a price for some weight
 * it should say rate not available, contact on WhatsApp". A blank grid box used
 * to be charged at the next HEAVIER priced kilogram, so leaving 3 kg empty
 * quietly charged the 5 kg price for a 3 kg parcel. A blank box is now not a
 * price at all: the service is simply not offered at that weight.
 *
 * WHY A SECOND DERIVATION SCRIPT AND NOT A REGENERATION, again. The expected
 * answers in this file come from CALLING the TypeScript engine in
 * /d/speedat/src, which still walks upward in `gridPrice()`, and that tree is
 * reference-only - not ours to edit. Regenerating alone therefore reproduces
 * the old fallback exactly as it was. So the checked-in file stays DERIVED, and
 * the pipeline gains a third step:
 *
 *     cd /d/speedat
 *     TZ=Asia/Karachi node_modules/.bin/tsx php/tools/make-vectors.ts
 *     cd php
 *     php tests/vectors/strip-document-rate.php tests/vectors/pricing.json
 *     php tests/vectors/apply-skippable-weights.php tests/vectors/pricing.json
 *     php bin/test.php
 *
 * THIS SCRIPT NEVER COMPUTES A PRICE. Like its sibling it only ever removes -
 * here by turning an answer into `null`. It decides purely from what the
 * generator already recorded: every grid answer carries the kilogram whose box
 * was read (`kg` on a gridPrice answer, `gridKg` on a price), so an answer that
 * fell upward is the one whose recorded kilogram is not the kilogram that was
 * asked for. Nothing else is touched, and NO SURVIVING NUMBER MOVES - every
 * price left in the file is still the TypeScript engine's own answer.
 *
 * The three rules:
 *
 *   S1  gridPrice:    expected becomes null when expected.kg is not
 *                     max(1, ceil(input.kg)) - i.e. the walk found a heavier
 *                     box. (A zero-priced box is skipped by the same test: the
 *                     generator's answer for it names a heavier kilogram.)
 *   S2  priceService: expected becomes null when expected.gridKg is set and is
 *                     not max(1, ceil(input.billableG / 1000)).
 *   S3  priceAll:     within a priced answer, a service's price becomes null on
 *                     the same test, against the answer's own
 *                     expected.weights.billableG.
 *
 * Slab-mode answers carry `gridKg: null` and are never touched. Cases that were
 * already null stay null. The script is idempotent: a second run changes
 * nothing, because after the first run no surviving answer names a kilogram
 * other than the one asked for.
 *
 * docs/engine-parity.md, "Skippable weight prices", is the prose version of
 * this list and carries the counts.
 */

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "usage: php tests/vectors/apply-skippable-weights.php <pricing.json>\n");
    exit(1);
}

$raw = file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, "cannot read $path\n");
    exit(1);
}

/** @var array<string,mixed> $data */
$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

/**
 * The kilogram box the engine reads for a weight in kilograms. Grid mode bills
 * whole kilograms from 1 kg up, so 0.4 kg and 1 kg both read box 1 and 1.2 kg
 * reads box 2. That rounding is NOT the fallback and is unchanged; it is
 * reproduced here only so "which box was asked for" can be compared with
 * "which box the generator's answer came from".
 */
$boxFor = static fn (float $kg): int => max(1, (int) ceil($kg));

$report = [
    'gridPrice'    => [],
    'priceService' => [],
    'priceAll'     => [],
];

foreach ($data['groups'] as $gi => $group) {
    $fn = $group['fn'];

    if ($fn !== 'gridPrice' && $fn !== 'priceService' && $fn !== 'priceAll') {
        continue;
    }

    foreach ($group['cases'] as $ci => $case) {
        $expected = $case['expected'] ?? null;

        /* ------------------------------------------------------------- S1 */
        if ($fn === 'gridPrice') {
            if (!is_array($expected) || !isset($expected['kg'])) {
                continue;
            }

            $asked = $boxFor((float) $case['input']['kg']);
            if ((int) $expected['kg'] === $asked) {
                continue;
            }

            $data['groups'][$gi]['cases'][$ci]['expected'] = null;
            $report['gridPrice'][] = $case['name'] . ' (asked ' . $asked . ' kg, answered ' . $expected['kg'] . ' kg)';

            continue;
        }

        /* ------------------------------------------------------------- S2 */
        if ($fn === 'priceService') {
            if (!is_array($expected) || ($expected['gridKg'] ?? null) === null) {
                continue;
            }

            $asked = $boxFor(((float) $case['input']['billableG']) / 1000);
            if ((int) $expected['gridKg'] === $asked) {
                continue;
            }

            $data['groups'][$gi]['cases'][$ci]['expected'] = null;
            $report['priceService'][] = $case['name'] . ' (asked ' . $asked . ' kg, answered ' . $expected['gridKg'] . ' kg)';

            continue;
        }

        /* ------------------------------------------------------------- S3 */
        if (!is_array($expected) || ($expected['ok'] ?? false) !== true || !isset($expected['weights']['billableG'])) {
            continue;
        }

        $asked = $boxFor(((float) $expected['weights']['billableG']) / 1000);

        foreach (($expected['prices'] ?? []) as $serviceId => $price) {
            if (!is_array($price) || ($price['gridKg'] ?? null) === null) {
                continue;
            }
            if ((int) $price['gridKg'] === $asked) {
                continue;
            }

            $data['groups'][$gi]['cases'][$ci]['expected']['prices'][$serviceId] = null;
            $report['priceAll'][] = $case['name'] . ' / ' . $serviceId
                . ' (asked ' . $asked . ' kg, answered ' . $price['gridKg'] . ' kg)';
        }
    }
}

/* -------------------------------------------------------------------- head */

$data['note'] = 'Generated from the TypeScript engine by php/tools/make-vectors.ts, then had its '
    . 'document-rate cases removed (owner, 2026-09-27) and its next-priced-kilogram fallback removed '
    . '(owner, 2026-09-28) by the rules in docs/engine-parity.md. Do not hand-edit: regenerate and '
    . 're-apply both derivations, in that order.';

$json = json_encode(
    $data,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);

// make-vectors.ts writes one-space indentation and PHP writes four, so a diff
// of the vector file shows content changes rather than a reformat.
$json = preg_replace_callback(
    '/^ +/m',
    static fn (array $m): string => str_repeat(' ', (int) (strlen($m[0]) / 4)),
    $json
) ?? $json;

file_put_contents($path, $json . "\n");

foreach ($report as $fn => $names) {
    fwrite(STDOUT, "$fn: " . count($names) . " answer(s) made unavailable\n");
    foreach ($names as $n) {
        fwrite(STDOUT, "    - $n\n");
    }
}
