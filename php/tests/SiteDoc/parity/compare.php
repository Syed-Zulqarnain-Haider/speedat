<?php

declare(strict_types=1);

/**
 * Differential harness, PHP side. Reads the golden file the TypeScript spec
 * produced, feeds the SAME inputs through the PHP port, and compares the results
 * character for character.
 *
 * See README.md in this directory for how to run the pair. This is a DEVELOPER
 * tool and deliberately not part of bin/test.php: generating the golden file
 * needs node and the Next.js tree, and the Hostinger host has neither. What it
 * proves is pinned as ordinary tests in the suite next door - this is how those
 * expectations were obtained, and how to obtain them again when the TypeScript
 * changes.
 */

use Speedat\Core\Autoloader;
use Speedat\Core\Bootstrap;
use Speedat\Core\Money;
use Speedat\Domain\SiteDoc\Countries;
use Speedat\Domain\SiteDoc\Diff;
use Speedat\Domain\SiteDoc\DiffResult;
use Speedat\Domain\SiteDoc\HoldState;
use Speedat\Domain\SiteDoc\Migrate;
use Speedat\Domain\SiteDoc\Schema;
use Speedat\Domain\SiteDoc\Seed;
use Speedat\Domain\SiteDoc\SiteData;
use Speedat\Domain\SiteDoc\Validate;

// tests/SiteDoc/parity/compare.php -> the php/ root.
$root = dirname(__DIR__, 3);
require $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app');
Bootstrap::cli($root);

if (($argv[1] ?? '') === '') {
    fwrite(STDERR, "usage: php tests/SiteDoc/parity/compare.php <golden.json>\n");
    exit(2);
}

/** The identical canonical dump the TypeScript side writes. */
function dumpv(mixed $v): string
{
    if ($v === null) {
        return 'null';
    }
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (is_int($v) || is_float($v)) {
        return '#' . Money::numberToString((float) $v);
    }
    if (is_string($v)) {
        return "\u{0002}" . $v . "\u{0003}";
    }
    if (is_array($v)) {
        if ($v === [] || array_is_list($v)) {
            return '[' . implode(',', array_map('dumpv', $v)) . ']';
        }
        $parts = [];
        foreach ($v as $k => $x) {
            $parts[] = $k . ':' . dumpv($x);
        }

        return '{' . implode(',', $parts) . '}';
    }

    return '?';
}

function dumpDiff(DiffResult $d): string
{
    $lines = [];
    foreach ($d->lines as $l) {
        $lines[] = implode("\u{0001}", [
            $l->kind,
            $l->label,
            $l->old ?? "\u{0000}",
            $l->new ?? "\u{0000}",
            $l->flag ?? "\u{0000}",
        ]);
    }
    $dests = array_keys($d->byDest);
    sort($dests);

    return implode("\u{0004}", array_merge([(string) $d->count, implode(',', $dests)], $lines));
}

/** @param array<array-key,mixed> $a */
function site(array $a): SiteData
{
    return SiteData::fromArray($a);
}

$golden = json_decode((string) file_get_contents($argv[1]), true);
$cases  = $golden['cases'];

$pass = 0;
$fail = [];

foreach ($cases as $case) {
    $name  = $case['name'];
    $kind  = $case['kind'];
    $in    = $case['input'];
    $want  = $case['expected'];
    $got   = null;

    try {
        switch ($kind) {
            case 'doc':
                $got = dumpv($name === 'seed' ? Seed::data() : Seed::gridData());
                break;

            case 'migrate':
                $got = dumpv(Migrate::migrate($in)->toArray());
                break;

            case 'upgradeContent':
                $got = dumpv(Migrate::upgradeContent($in));
                break;

            case 'raw':
                $got = match ($name) {
                    'retiredDefaults' => dumpv(Migrate::retiredDefaults()),
                    'countryCodes'    => dumpv(Countries::codes()),
                    'holdConstants'   => dumpv([
                        'max'     => HoldState::MESSAGE_MAX,
                        'def'     => HoldState::DEFAULT_MESSAGE,
                        'none'    => HoldState::none()->toArray(),
                        'flagPct' => Diff::FLAG_PCT,
                    ]),
                    default => 'unknown raw case',
                };
                break;

            case 'diff':
                $d   = Diff::of(site($in['a']), site($in['b']));
                $got = dumpDiff($d) . "\u{0005}" . Diff::summarise($d);
                break;

            case 'fieldChange':
                $c   = Diff::fieldChange($in['a'], $in['b']);
                $got = implode("\u{0001}", [$c->where ?? "\u{0000}", $c->old, $c->new]);
                break;

            case 'validate':
                $got = implode("\u{0001}", Validate::errors(site($in)));
                break;

            case 'warn':
                $got = implode("\u{0001}", Validate::warnings(site($in)));
                break;

            case 'schema':
                $issues = Schema::check($in);
                $got    = $issues === []
                    ? 'OK'
                    : implode("\u{0001}", array_map(static fn ($i): string => (string) $i, $issues));
                break;

            case 'normalizeCountry':
                $got = Countries::normalize($in);
                break;

            case 'flagCode':
                $got = Countries::flagCode($in['name'], $in['id']) ?? "\u{0000}";
                break;

            case 'cleanHoldMessage':
                $got = HoldState::cleanMessage($in['raw']);
                break;

            default:
                $got = 'unknown kind ' . $kind;
        }
    } catch (\Throwable $e) {
        $got = 'THREW ' . $e::class . ': ' . $e->getMessage();
    }

    if ($got === $want) {
        $pass++;
        continue;
    }

    $fail[] = ['name' => $name, 'kind' => $kind, 'want' => $want, 'got' => $got];
}

$vis = static function (string $s): string {
    return str_replace(
        ["\u{0000}", "\u{0001}", "\u{0002}", "\u{0003}", "\u{0004}", "\u{0005}"],
        ['<null>', ' | ', '"', '"', "\n      ", "\n   SUMMARY "],
        $s
    );
};

echo "PHP " . PHP_VERSION . "  cases: " . count($cases) . "  passed: {$pass}  failed: " . count($fail) . "\n";

foreach ($fail as $f) {
    echo "\n=== MISMATCH [{$f['kind']}] {$f['name']}\n";
    $want = $vis($f['want']);
    $got  = $vis($f['got']);

    // Show only the neighbourhood of the first difference when both are long.
    $wl = mb_strlen($want);
    $gl = mb_strlen($got);
    $p  = 0;
    while ($p < min($wl, $gl) && mb_substr($want, $p, 1) === mb_substr($got, $p, 1)) {
        $p++;
    }
    $from = max(0, $p - 120);
    echo "  at char {$p} (ts len {$wl}, php len {$gl})\n";
    echo "  TS : ..." . mb_substr($want, $from, 400) . "\n";
    echo "  PHP: ..." . mb_substr($got, $from, 400) . "\n";
}

exit($fail === [] ? 0 : 1);
