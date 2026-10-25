<?php

declare(strict_types=1);

/**
 * Re-derive php/tests/vectors/pricing.json for PLAIN DASHES in customer text.
 *
 * OWNER DECISION, 2026-10-11: no em dash anywhere the customer or the office
 * reads. "—" became " - " (a spaced hyphen) in every sentence the PHP and the
 * browser engines write, and a bare "—" (a blank shown as a dash) became "-".
 *
 * WHY A THIRD DERIVATION SCRIPT AND NOT A REGENERATION, for the same reason as
 * its two siblings: the expected answers come from CALLING the TypeScript
 * engine in /d/speedat/src, which is reference-only and still writes "—", so
 * regenerating alone reproduces the old punctuation. The pipeline is now:
 *
 *     cd /d/speedat
 *     TZ=Asia/Karachi node_modules/.bin/tsx php/tools/make-vectors.ts
 *     cd php
 *     php tests/vectors/strip-document-rate.php tests/vectors/pricing.json
 *     php tests/vectors/apply-skippable-weights.php tests/vectors/pricing.json
 *     php tests/vectors/apply-plain-dashes.php tests/vectors/pricing.json
 *     php bin/test.php
 *
 * THIS SCRIPT NEVER TOUCHES AN INPUT and never touches a number. It rewrites
 * strings inside `expected` only, by two rules applied in order:
 *
 *   D1  " — " (spaced em dash) becomes " - ".
 *   D2  any remaining "—" becomes "-".
 *
 * Inputs keep their em dashes on purpose: the parsers (parseDays, toNumLoose)
 * must still accept a "—" pasted in by the office, and those cases are what
 * prove it. The en dash "–" in ranges such as "4–25 kg" is not an em dash and
 * is left alone. Idempotent: after one run no expected string contains "—".
 */

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "usage: php tests/vectors/apply-plain-dashes.php <pricing.json>\n");
    exit(1);
}

$raw = file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, "cannot read $path\n");
    exit(1);
}

/** @var array<string,mixed> $data */
$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

$changed = [];

$plain = static function (mixed $value) use (&$plain): mixed {
    if (is_string($value)) {
        return str_replace('—', '-', str_replace(' — ', ' - ', $value));
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $value[$k] = $plain($v);
        }
    }

    return $value;
};

foreach ($data['groups'] as $gi => $group) {
    foreach ($group['cases'] as $ci => $case) {
        if (!array_key_exists('expected', $case)) {
            continue;
        }

        $after = $plain($case['expected']);
        if ($after !== $case['expected']) {
            $data['groups'][$gi]['cases'][$ci]['expected'] = $after;
            $changed[] = $group['fn'] . ' / ' . $case['name'];
        }
    }
}

/* -------------------------------------------------------------------- head */

$data['note'] = 'Generated from the TypeScript engine by php/tools/make-vectors.ts, then had its '
    . 'document-rate cases removed (owner, 2026-09-27), its next-priced-kilogram fallback removed '
    . '(owner, 2026-09-28) and the em dashes in its expected text made plain (owner, 2026-10-11) by '
    . 'the rules in docs/engine-parity.md. Do not hand-edit: regenerate and re-apply all three '
    . 'derivations, in that order.';

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

fwrite(STDOUT, count($changed) . " expected answer(s) given plain dashes\n");
foreach ($changed as $n) {
    fwrite(STDOUT, "    - $n\n");
}
