<?php

declare(strict_types=1);

/**
 * Set the file permissions an extract does not set.
 *
 *   php bin/harden.php              apply them
 *   php bin/harden.php --dry-run    say what would change, change nothing
 *
 * WHY THIS EXISTS. A zip carries no unix modes worth honouring - this one is
 * built on Windows, where there are none - so File Manager writes every
 * extracted file with the ACCOUNT'S UMASK. The 2026-09-29 go-live rehearsal
 * extracted a correct release and got 664 on every file and 775 on every
 * directory (docs/go-live-rehearsal.md section 2.2, finding 3.5): group-writable,
 * all of it, including config.php. So the permissions step is not a tidy-up
 * after a good extract. It is the step that CREATES the permissions, and it has
 * to run after EVERY extract, including an upgrade over the top.
 *
 * The owner can do the same job by hand in hPanel -> File Manager, which has a
 * recursive permissions dialog (docs/deploy-hostinger.md section 4a). This script
 * is for when he would rather not click through it four times, and it is the
 * only version that reports what it actually changed.
 *
 * IT NEEDS NO CONFIGURATION AND NO DATABASE. No Bootstrap, no Config, no PDO -
 * deliberately, because the moment it is most useful is immediately after the
 * extract, BEFORE config.php exists. It reads nothing but the directory tree it
 * is standing in.
 *
 * HOW THE OWNER RUNS IT WITH NO SHELL. hPanel -> Advanced -> Cron Jobs, a
 * one-off entry, then delete the entry:
 *   /usr/bin/php /home/uXXXXXXXX/domains/<domain>/bin/harden.php
 * Cron output goes nowhere he can see, so the run also writes its report to
 * var/permissions-report.txt, which he opens in File Manager. Evidence he can
 * read is the whole point: "I ran it" is not the same as "it worked".
 *
 * WHAT IT DOES NOT DO. It never widens anything to 777, it never follows a
 * symlink, and it refuses to run anywhere that does not look like a Speedat
 * release - a recursive chmod pointed at the wrong directory is a bad afternoon.
 *
 * THE RELEASE ROOT ITSELF IS TIGHTENED, NOT SET. The second rehearsal
 * (2026-09-29, docs/go-live-rehearsal.md run 2) found the walk skipping the one
 * directory it was pointed at: `~/domains/<site>/` came out of the extract at
 * 775 and stayed there, while the report said "Nothing is group- or
 * world-writable." It is now checked like everything else - but only the group
 * and world WRITE bits are cleared, never the rest. That directory belongs to
 * the panel, not to us: an operator who deliberately set it to 700 would not
 * thank a deploy script for opening it to 755.
 */

/** Everything went as intended. */
const HARDEN_OK = 0;

/** At least one chmod was refused by the host. */
const HARDEN_FAILED = 1;

/** This is not a Speedat release directory, or chmod is unavailable. */
const HARDEN_REFUSED = 2;

/**
 * The mode a path in a deployed release must end up with.
 *
 * Split out from the walk so it can be asserted directly. On Windows PHP's
 * chmod only moves the read-only bit, so a test that made files and looked at
 * the result would prove nothing here - but the RULE is the part that has to be
 * right, and the rule is a pure function of the path.
 *
 * @param string $relative path inside the release root, '/'-separated, no
 *                         leading slash ('config/config.php', 'storage/logs')
 */
function speedat_release_mode(string $relative, bool $isDirectory): int
{
    $relative = trim(str_replace('\\', '/', $relative), '/');
    $first    = explode('/', $relative)[0];

    // config.php holds the application key, the database password and the SMTP
    // password. Owner-only, and it is the one file in the tree that would be
    // worth reading to a stranger who got a foothold on a shared host.
    if ($relative === 'config/config.php') {
        return 0600;
    }

    // storage/ is where the application writes; var/ is where the build and the
    // reports land. Nobody else on a shared box needs to read either, and both
    // accumulate content nobody reviewed - logs carry filter values, uploads
    // carry whatever was uploaded.
    if ($first === 'storage' || $first === 'var') {
        return $isDirectory ? 0700 : 0600;
    }

    // config/ itself is closed even though config.sample.php inside it holds
    // nothing: it is the directory whose NAME tells a scanner what to ask for.
    if ($first === 'config') {
        return $isDirectory ? 0700 : 0644;
    }

    return $isDirectory ? 0755 : 0644;
}

/**
 * Does this directory look like an extracted Speedat release?
 *
 * The web root is `public_html` on the host and `public` in the development
 * tree, so either counts. Both markers must be present: a directory with an
 * app/Core/Bootstrap.php and no web root at all is somebody's source checkout
 * one level too high.
 */
function speedat_looks_like_a_release(string $root): bool
{
    if (!is_file($root . '/app/Core/Bootstrap.php')) {
        return false;
    }

    return is_dir($root . '/public_html') || is_dir($root . '/public');
}

/**
 * The mode the release root should end at, given the mode it has.
 *
 * Tighten-only: clear the group and world write bits and change nothing else.
 * Returns null when there is nothing to do.
 *
 * Split out from the walk for the same reason speedat_release_mode() is - the
 * rule is the part that has to be right, and on Windows there are no modes to
 * observe.
 */
function speedat_root_mode(int $current): ?int
{
    $wanted = $current & ~0022;

    return $wanted === $current ? null : $wanted;
}

/**
 * Walk the tree and set every mode.
 *
 * @return array{changed:int,kept:int,failed:list<string>,loose:list<string>,modes:array<string,int>}
 *         `modes` counts how many paths ended at each mode, which is what the
 *         checklist asks to be written down; `loose` is anything still
 *         group- or world-writable when the walk finished.
 */
function speedat_harden(string $root, bool $apply = true): array
{
    $report = ['changed' => 0, 'kept' => 0, 'failed' => [], 'loose' => [], 'modes' => []];

    // The root first, because it is the one path the recursive iterator below
    // never yields and the one an extract leaves group-writable.
    $rootPerms = @fileperms($root);
    if ($rootPerms !== false && !is_link($root)) {
        $rootWanted = speedat_root_mode($rootPerms & 0777);
        if ($rootWanted !== null) {
            if (!$apply) {
                $report['changed']++;
            } elseif (@chmod($root, $rootWanted)) {
                $report['changed']++;
            } else {
                $report['failed'][] = '. (the release root, wanted ' . decoct($rootWanted) . ')';
            }
        } else {
            $report['kept']++;
        }
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    /** @var SplFileInfo $item */
    foreach ($iterator as $item) {
        $path = str_replace('\\', '/', $item->getPathname());

        // A symlink's chmod applies to the TARGET, which may be anywhere. The
        // host disables symlink() anyway (docs/hostinger-constraints.md section
        // 3), so one here was not made by us and is not ours to re-permission.
        if (is_link($path)) {
            continue;
        }

        $relative = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
        $wanted   = speedat_release_mode($relative, $item->isDir());

        $report['modes'][decoct($wanted)] = ($report['modes'][decoct($wanted)] ?? 0) + 1;

        $current = @fileperms($path);
        $current = $current === false ? null : ($current & 0777);

        if ($current === $wanted) {
            $report['kept']++;
            continue;
        }

        if (!$apply) {
            $report['changed']++;
            continue;
        }

        if (@chmod($path, $wanted)) {
            $report['changed']++;
            continue;
        }

        $report['failed'][] = $relative . ' (wanted ' . decoct($wanted) . ')';
    }

    // Then look again, because "chmod returned true" and "the file is not
    // group-writable" are different claims on a host that may be running PHP as
    // somebody else. This second pass is the one the checklist trusts.
    if ($apply) {
        clearstatcache();

        $rootPerms = @fileperms($root);
        if ($rootPerms !== false && ($rootPerms & 0022) !== 0) {
            $report['loose'][] = '. (the release root) is ' . decoct($rootPerms & 0777);
        }

        $second = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $item */
        foreach ($second as $item) {
            $path = str_replace('\\', '/', $item->getPathname());
            if (is_link($path)) {
                continue;
            }

            $mode = @fileperms($path);
            if ($mode !== false && ($mode & 0022) !== 0) {
                $report['loose'][] = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/')
                    . ' is ' . decoct($mode & 0777);
            }
        }
    }

    return $report;
}

/** The report as the owner reads it in File Manager. */
function speedat_harden_summary(array $report, string $root, bool $apply): string
{
    $lines   = [];
    $lines[] = 'Speedat - permissions';
    $lines[] = '=====================';
    $lines[] = gmdate('Y-m-d H:i:s') . ' UTC, ' . $root;
    $lines[] = $apply ? '' : '(--dry-run: nothing was changed)';
    $lines[] = '';

    ksort($report['modes']);
    foreach ($report['modes'] as $mode => $count) {
        $lines[] = sprintf('  %6s  %d path(s)', $mode, $count);
    }

    $lines[] = '';
    $lines[] = '  ' . $report['changed'] . ' changed, ' . $report['kept'] . ' already correct';

    if ($report['failed'] !== []) {
        $lines[] = '';
        $lines[] = '  REFUSED BY THE HOST (' . count($report['failed']) . '):';
        foreach (array_slice($report['failed'], 0, 20) as $failure) {
            $lines[] = '    ' . $failure;
        }
        if (count($report['failed']) > 20) {
            $lines[] = '    ... and ' . (count($report['failed']) - 20) . ' more';
        }
        $lines[] = '';
        $lines[] = '  Set these by hand: hPanel -> File Manager -> right-click -> Permissions.';
    }

    if ($report['loose'] !== []) {
        $lines[] = '';
        $lines[] = '  STILL GROUP- OR WORLD-WRITABLE (' . count($report['loose']) . '):';
        foreach (array_slice($report['loose'], 0, 20) as $loose) {
            $lines[] = '    ' . $loose;
        }
    }

    if ($report['failed'] === [] && $report['loose'] === []) {
        $lines[] = '';
        $lines[] = '  Nothing is group- or world-writable. This is what "done" looks like.';
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

// ---------------------------------------------------------------------------
// Run it, but only when this file IS the script - seed.php requires it.
// ---------------------------------------------------------------------------

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $root   = dirname(__DIR__);
    $dryRun = in_array('--dry-run', array_slice($argv, 1), true);

    // CLI only, said explicitly rather than inherited: this file deliberately
    // does not boot the application, so it does not get Bootstrap::cli's refusal
    // for free. It lives above public_html and no URL reaches it; this is the
    // belt for the day somebody copies bin/ into the web root.
    if (!speedat_looks_like_a_release($root)) {
        fwrite(STDERR, $root . " does not look like a Speedat release (no app/Core/Bootstrap.php\n"
            . "beside a public_html/). Refusing to chmod a tree I cannot identify.\n");
        exit(HARDEN_REFUSED);
    }

    if (!function_exists('chmod')) {
        fwrite(STDERR, "chmod() is disabled in this PHP build, so nothing here can set a mode.\n"
            . "Use hPanel -> File Manager -> right-click -> Permissions instead;\n"
            . "docs/deploy-hostinger.md section 4a says exactly what to click.\n");
        exit(HARDEN_REFUSED);
    }

    // Windows has no POSIX modes - PHP's chmod there moves the read-only bit and
    // nothing else, and fileperms() reports 666/777 for everything. Applying
    // would change nothing and then report every file as world-writable, which
    // is a false alarm on the one machine where it cannot possibly matter. So
    // the build machine gets the plan and no claims.
    if (DIRECTORY_SEPARATOR === '\\') {
        echo "This is Windows: there are no POSIX modes here to set." . PHP_EOL
            . "Printing what WOULD be set on the host, and changing nothing." . PHP_EOL . PHP_EOL;
        $dryRun = true;
    }

    $report  = speedat_harden($root, !$dryRun);
    $summary = speedat_harden_summary($report, $root, !$dryRun);

    echo $summary;

    // Cron output goes nowhere the owner can see, so leave the same words in a
    // file he can open. Written after the walk, so its own mode is already set.
    //
    // NOT ON --dry-run. The documented promise is "prints the same report and
    // changes nothing", and a run that writes a file has changed something; the
    // second rehearsal caught the next run counting that file as "1 already
    // correct", which is a dry run reporting on its own footprint. A dry run
    // from cron would be pointless anyway - its whole output is the terminal.
    $reportFile = $root . '/var/permissions-report.txt';
    if (!$dryRun && @file_put_contents($reportFile, $summary) !== false) {
        @chmod($reportFile, 0600);
        echo PHP_EOL . 'Written to var/permissions-report.txt' . PHP_EOL;
    }

    exit($report['failed'] === [] && $report['loose'] === [] ? HARDEN_OK : HARDEN_FAILED);
}
