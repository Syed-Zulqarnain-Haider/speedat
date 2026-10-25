<?php

declare(strict_types=1);

/**
 * The scheduled job. ONE hPanel cron entry runs this; everything that has to
 * happen on a timer happens inside it.
 *
 * hPanel -> Advanced -> Cron Jobs -> Custom, once an hour (0 * * * *):
 *   /usr/bin/php /home/uXXXXXXXX/domains/<domain>/bin/cron.php
 *
 * EXACTLY THAT, with nothing after it. hPanel's command field REJECTS `>`,
 * `2>&1` and `/dev/null` (docs/hostinger-constraints.md section 6), and this
 * docblock used to hand the owner a command containing all three. There is no
 * need for them: the job writes to storage/logs/ itself and prints a short
 * summary. If redirection is ever genuinely wanted, it goes in a small .sh file
 * that cron calls with /bin/sh.
 *
 * Absolute paths only, and no `~`: cron has no working directory worth counting
 * on. The cron clock is UTC and cannot be changed.
 *
 * HOURLY, not daily. The alerts that must reach the office once a day dedupe
 * themselves through Domain\Ops\DailyOnce; that only works because this runs
 * more often than they fire.
 *
 * IT READS NO ARGUMENTS - no --token, nothing. It is CLI-only: Bootstrap::cli
 * refuses to run under a web server, and this file lives above public_html, so
 * there is no URL that reaches it and nothing for a token to guard. `tokens.cron`
 * is for the other case, a plan that only offers URL-triggered cron, where a
 * route checks the token rather than this file moving into the web root.
 *
 * Every job is wrapped: one failing job reports itself and the rest still run.
 * A cron that stops at the first error is a cron whose later jobs silently
 * never happen.
 */

use Speedat\Core\Audit;
use Speedat\Core\Autoloader;
use Speedat\Core\Bootstrap;
use Speedat\Core\Log;
use Speedat\Core\Paths;
use Speedat\Core\RateLimit;
use Speedat\Core\Session;

$root = dirname(__DIR__);
require $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app');
Bootstrap::cli($root);

$startedAt = microtime(true);
$results   = [];
$failures  = 0;

/*
 * ---------------------------------------------------------------------------
 * FIRST LINE OF EVERY RUN: WHICH PHP THIS IS, AND WHAT IT HAS.
 * ---------------------------------------------------------------------------
 * On shared hosting the CLI PHP that cron runs is NOT documented to be the
 * same build as the one serving the website. hPanel sets the web version; the
 * cron entry runs whatever `/usr/bin/php` happens to be, which on real
 * accounts has been an older major release with a different extension set.
 *
 * The failure that produces is the worst kind: the website is fine, the cron
 * entry "runs", and the sweeps silently never happen because `pdo_mysql` is
 * not loaded in that build - so sessions accumulate, rate-limit windows never
 * clear, and the logs grow until the disk quota takes the site down.
 *
 * docs/go-live-checklist.md item 14 has always asked for this as evidence: "a
 * cron_run line ... AND the PHP version on its environment line". Until
 * 2026-09-29 no such line existed, so the checklist asked for proof the job
 * could not produce, and the go-live rehearsal had nothing to paste. It is
 * written BEFORE the jobs run, so a run that dies inside a job still leaves
 * the one fact that explains why.
 *
 * Only the extensions this application would actually miss are named. A dump
 * of every loaded extension is forty names nobody reads; nine with a yes or a
 * no beside each is a line somebody can act on.
 *
 * THE FIELDS COME FROM Housekeeping, NOT FROM HERE. The same event is also
 * written once a UTC day by Domain\Ops\Housekeeping, which is the path the
 * URL-triggered cron takes - and on a shell-less plan the URL one is the ONLY
 * cron the owner can run. The two used to emit different shapes under one
 * event name, so the operator most likely to be on the wrong PHP build got the
 * version of the line that does not say which extension is missing.
 */
$environment = \Speedat\Domain\Ops\Housekeeping::environmentFields();
$loaded      = $environment['loaded'];
$absent      = $environment['missing'];

Log::info('cron_environment', $environment);

echo '  php  ' . PHP_VERSION . '  (' . PHP_SAPI . ', ' . PHP_BINARY . ')' . PHP_EOL;
echo '  ext  ' . implode(' ', $loaded) . PHP_EOL;
if ($absent !== []) {
    // Not a failure in itself - `intl` and `sodium` are optional and the
    // application falls back - but it is the first thing to read when a job
    // below reports something that makes no sense.
    echo '  --   not loaded: ' . implode(' ', $absent) . PHP_EOL;
}
echo PHP_EOL;

/*
 * pdo_mysql is not optional. Without it every job below dies on its first
 * query with a message about a driver, and the summary reads as three separate
 * failures rather than as one missing extension. Said once, in plain words, at
 * the top - because the person reading this is looking at an hPanel cron entry
 * that appears to be running perfectly well.
 */
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR,
        "pdo_mysql is NOT loaded in this PHP build, so nothing below can reach the database.\n"
        . 'This is the CLI PHP the cron entry runs (' . PHP_BINARY . ', ' . PHP_VERSION . "), which on\n"
        . "shared hosting is not always the build serving the website. Point the cron entry at the\n"
        . "same version hPanel sets for the site - hPanel -> Advanced -> Cron Jobs, and the PHP path\n"
        . "under PHP Configuration.\n");
}

/**
 * @var array<string,Closure():string> $jobs
 *
 * Phase 2 adds the intake mailbox poll and the stale-rates alert here; both
 * are ports of src/lib/alerts.ts and src/lib/import/intake.ts.
 */
$jobs = [
    // Expired and idle logins. Session enforces both on read as well, so this
    // is housekeeping rather than security.
    'sessions' => static fn (): string => Session::sweep() . ' expired session(s) removed',

    // Rate-limit windows nobody will ask about again.
    'rate_limits' => static fn (): string => RateLimit::sweep() . ' old window(s) removed',

    // Logs older than 30 days. Shared hosting has a disk quota, and a disk that
    // fills up takes the site down long before anyone reads the logs.
    'logs' => static fn (): string => pruneLogs(30) . ' log file(s) removed',
];

foreach ($jobs as $name => $job) {
    try {
        $summary        = $job();
        $results[$name] = $summary;
        echo '  ok  ' . str_pad($name, 14) . $summary . PHP_EOL;
    } catch (Throwable $e) {
        $failures++;
        $results[$name] = 'FAILED: ' . $e->getMessage();
        Log::exception('cron_job_failed', $e, ['job' => $name]);
        fwrite(STDERR, '  FAIL ' . str_pad($name, 14) . $e->getMessage() . PHP_EOL);
    }
}

$elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

Log::info('cron_run', ['jobs' => $results, 'ms' => $elapsedMs, 'failures' => $failures]);

try {
    Audit::log('system', 'cron_run', ['jobs' => $results, 'ms' => $elapsedMs]);
} catch (Throwable) {
    // Audit never throws, but the database may have been what failed.
}

echo PHP_EOL . 'Finished in ' . $elapsedMs . 'ms with ' . $failures . " failure(s).\n";
exit($failures === 0 ? 0 : 1);

/** Delete app-YYYY-MM-DD.log files older than $days. */
function pruneLogs(int $days): int
{
    $cutoff  = time() - $days * 86400;
    $removed = 0;

    foreach (glob(Paths::logs() . '/app-*.log') ?: [] as $file) {
        $modified = @filemtime($file);
        if ($modified !== false && $modified < $cutoff && @unlink($file)) {
            $removed++;
        }
    }

    return $removed;
}
