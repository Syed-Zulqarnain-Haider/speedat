<?php

declare(strict_types=1);

/**
 * Take the document rate out of a freshly generated php/tests/vectors/pricing.json.
 *
 * WHY THIS EXISTS INSTEAD OF A REGENERATION. php/tools/make-vectors.ts takes
 * every expected answer by CALLING the TypeScript engine in /d/speedat/src, and
 * that engine cannot express a document-free document: `Settings` carries
 * `docMaxKg`, `Rate` carries `doc`, `priceService()` takes a shipment type, and
 * `validateSite()` emits document messages. Regenerating alone therefore
 * reproduces the document cases exactly as they were, and /d/speedat/src is
 * reference-only - it is not ours to edit. So the checked-in vector file is
 * DERIVED: generate, then run this.
 *
 *     cd /d/speedat
 *     TZ=Asia/Karachi node_modules/.bin/tsx php/tools/make-vectors.ts
 *     cd php && php tests/vectors/strip-document-rate.php tests/vectors/pricing.json
 *     php bin/test.php
 *
 * Every rule below is mechanical and order-independent, so the pipeline is
 * reproducible: running it on 2026-09-28 against a fresh generation reproduced
 * the checked-in file byte for byte. docs/engine-parity.md, "The document rate",
 * is the prose version of this list and carries the counts.
 *
 *   R1  sites:        drop settings.docMaxKg and every rates.*.doc
 *   R2  priceAll:     drop a case whose input asserts type "doc"; from the rest
 *                     drop input.type, expected.type, and doc/docRate from each
 *                     expected price
 *   R3  priceService: drop a case whose input asserts type "doc"; from the rest
 *                     drop input.type and input.chargeG (neither is a parameter
 *                     any more) and doc/docRate from expected
 *   R4  quoteText:    drop a case whose quote asserts type "doc"; from the rest
 *                     drop quote.type and quote.docRate
 *   R5  validate/warn: remove the document messages the engines no longer
 *                     produce, matched in full, and drop a case whose site
 *                     fixture R7 removed
 *   R6  dedupe:       within a group, keep the first of any {input, expected}
 *                     that repeats - stripping `type` makes the explicit "pkg"
 *                     case identical to the untyped one beside it
 *   R7  fixtures:     drop any site fixture no surviving case names
 *
 * This script only ever REMOVES. It never computes a price, so it cannot move
 * one: every number that survives is still the TypeScript engine's own answer.
 */

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "usage: php tests/vectors/strip-document-rate.php <pricing.json>\n");
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
 * The document messages deleted from Validate.php and from pricing.js, matched
 * in full rather than by the word "document" - a future message that merely
 * mentions documents must not be swallowed by this filter.
 */
$isDocMessage = static function (string $m): bool {
    return $m === 'Document weight limit must be greater than 0.'
        || str_ends_with($m, ': document price must be greater than 0 or left blank.')
        || str_contains($m, ': has a document price but no kilogram prices')
        || str_contains($m, ': has a document price but no package prices')
        || (str_contains($m, ': the document price (') && str_contains($m, 'is higher than the lightest package price ('));
};

$report = [
    'sitesDocStripped'  => 0,
    'ratesDocStripped'  => 0,
    'casesDropped'      => [],
    'messagesDropped'   => 0,
    'duplicatesDropped' => 0,
    'fixturesDropped'   => [],
];

/* --------------------------------------------------------------------- R1 */

foreach ($data['sites'] as $name => $site) {
    if (isset($site['settings']) && array_key_exists('docMaxKg', $site['settings'])) {
        unset($site['settings']['docMaxKg']);
        $report['sitesDocStripped']++;
    }

    foreach (($site['destinations'] ?? []) as $i => $dest) {
        foreach (($dest['rates'] ?? []) as $serviceId => $rate) {
            if (is_array($rate) && array_key_exists('doc', $rate)) {
                unset($rate['doc']);
                $site['destinations'][$i]['rates'][$serviceId] = $rate;
                $report['ratesDocStripped']++;
            }
        }
    }

    $data['sites'][$name] = $site;
}

/* ------------------------------------------------------------ R2 - R6 */

foreach ($data['groups'] as $gi => $group) {
    $fn   = $group['fn'];
    $out  = [];
    $seen = [];

    foreach ($group['cases'] as $case) {
        $in = $case['input'];

        if ($fn === 'priceAll') {
            if (($in['input']['type'] ?? 'pkg') === 'doc') {
                $report['casesDropped'][$fn][] = $case['name'];
                continue;
            }
            unset($in['input']['type'], $case['expected']['type']);
            foreach (($case['expected']['prices'] ?? []) as $sid => $price) {
                if (is_array($price)) {
                    unset($price['doc'], $price['docRate']);
                    $case['expected']['prices'][$sid] = $price;
                }
            }
        } elseif ($fn === 'priceService') {
            if (($in['type'] ?? 'pkg') === 'doc') {
                $report['casesDropped'][$fn][] = $case['name'];
                continue;
            }
            unset($in['type'], $in['chargeG']);
            if (is_array($case['expected'])) {
                unset($case['expected']['doc'], $case['expected']['docRate']);
            }
        } elseif ($fn === 'quoteText') {
            if (($in['quote']['type'] ?? 'pkg') === 'doc') {
                $report['casesDropped'][$fn][] = $case['name'];
                continue;
            }
            unset($in['quote']['type'], $in['quote']['docRate']);
        } elseif ($fn === 'validateSite' || $fn === 'warnSite') {
            $site = $in['site'] ?? '';
            if (!array_key_exists($site, $data['sites'])) {
                $report['casesDropped'][$fn][] = $case['name'];
                continue;
            }
            $keep = [];
            foreach (($case['expected'] ?? []) as $m) {
                if ($isDocMessage((string) $m)) {
                    $report['messagesDropped']++;
                    continue;
                }
                $keep[] = $m;
            }
            $case['expected'] = $keep;
        }

        $case['input'] = $in;

        // R6. The generator adds each weight three times - untyped, "doc" and
        // "pkg". Once `type` is not a parameter the untyped and the "pkg" case
        // are the same call with the same answer, and a duplicate proves
        // nothing twice.
        $key = json_encode([$case['input'], $case['expected']], JSON_THROW_ON_ERROR);
        if (isset($seen[$key])) {
            $report['duplicatesDropped']++;
            continue;
        }
        $seen[$key] = true;

        $out[] = $case;
    }

    $data['groups'][$gi]['cases'] = $out;
}

/* --------------------------------------------------------------------- R7 */

$used = [];
foreach ($data['groups'] as $group) {
    foreach ($group['cases'] as $case) {
        $s = is_array($case['input']) ? ($case['input']['site'] ?? null) : null;
        if (is_string($s)) {
            $used[$s] = true;
        }
    }
}
foreach (array_keys($data['sites']) as $name) {
    if (!isset($used[$name])) {
        unset($data['sites'][$name]);
        $report['fixturesDropped'][] = $name;
    }
}

/* -------------------------------------------------------------------- head */

$count = 0;
foreach ($data['groups'] as $group) {
    $count += count($group['cases']);
}

$data['count'] = $count;
$data['note']  = 'Generated from the TypeScript engine by php/tools/make-vectors.ts, then had its '
    . 'document-rate cases removed (owner, 2026-09-27) by the rules in docs/engine-parity.md '
    . '"The document rate". Do not hand-edit: regenerate and re-apply those rules.';

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

fwrite(STDOUT, "count: $count\n");
fwrite(STDOUT, 'settings stripped: ' . $report['sitesDocStripped'] . ', rates stripped: ' . $report['ratesDocStripped'] . "\n");
fwrite(STDOUT, 'messages dropped: ' . $report['messagesDropped'] . ', duplicates dropped: ' . $report['duplicatesDropped'] . "\n");
fwrite(STDOUT, 'fixtures dropped: ' . implode(', ', $report['fixturesDropped']) . "\n");
foreach ($report['casesDropped'] as $fn => $names) {
    fwrite(STDOUT, "$fn: " . count($names) . " cases dropped\n");
    foreach ($names as $n) {
        fwrite(STDOUT, "    - $n\n");
    }
}
