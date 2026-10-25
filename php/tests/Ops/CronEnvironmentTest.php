<?php

declare(strict_types=1);

namespace Speedat\Tests\Ops;

use Speedat\Core\Paths;
use Speedat\Tests\TestCase;

/**
 * `bin/cron.php` says which PHP it is running under, before it does anything.
 *
 * WHY THIS MATTERS ON THE HOST THIS SHIPS TO. hPanel sets the PHP version that
 * serves the WEBSITE. The cron entry runs whatever `/usr/bin/php` happens to
 * be, and Hostinger does not document the two as the same build. When they are
 * not, the website is perfectly healthy, the cron entry "runs", and every
 * sweep silently does nothing because `pdo_mysql` is absent from that build -
 * so sessions accumulate, rate-limit windows never clear, and the logs grow
 * until the disk quota takes the site down. Nothing in the summary line says
 * why.
 *
 * `docs/go-live-checklist.md` item 14 asks for exactly this as evidence: "a
 * `cron_run` line ... **and the PHP version on its environment line**". There
 * was no environment line until 2026-09-29, so the checklist asked the
 * operator for proof the job could not produce, and the 2026-09-29 go-live
 * rehearsal ran the cron on staging and had nothing to paste
 * (`docs/go-live-rehearsal.md`).
 *
 * The job is run as a CHILD process, not required into this one: it calls
 * exit(), it writes to the log, and the whole point is what a separate `php
 * bin/cron.php` prints.
 */
final class CronEnvironmentTest extends TestCase
{
    public function testTheCronNamesItsPhpVersionAndItsExtensionsBeforeItDoesAnything(): void
    {
        $out = $this->runCron();

        $this->assertStringContains(
            'php  ' . PHP_VERSION,
            $out,
            'bin/cron.php does not print the PHP version it is running under, so a cron entry pointed at '
            . 'the wrong PHP build looks identical to one pointed at the right build'
        );

        $this->assertStringContains(
            'pdo_mysql',
            $out,
            'bin/cron.php does not say whether pdo_mysql is loaded - the one extension whose absence makes '
            . 'every job below fail for a reason the summary never mentions'
        );

        // Before the jobs, not after. A run that dies inside a job must still
        // leave the line that explains which interpreter it died in.
        $phpAt = strpos($out, 'php  ');
        $jobAt = strpos($out, 'sessions');

        $this->assertTrue($phpAt !== false, 'no environment line at all');
        $this->assertTrue(
            $jobAt === false || $phpAt < $jobAt,
            'the environment line is printed after the jobs, so a job that dies takes it with it'
        );
    }

    public function testTheEnvironmentIsInTheLogAndNotOnlyOnTheScreen(): void
    {
        // Cron output on shared hosting goes nowhere by default - hPanel's
        // command field refuses `>` and `2>&1` - so the screen is not where
        // anybody reads this a week later.
        $this->runCron();

        $log = Paths::logs() . '/app-' . gmdate('Y-m-d') . '.log';
        $text = @file_get_contents($log);
        $this->assertTrue($text !== false, 'the cron wrote no log file at ' . $log);

        $this->assertStringContains(
            '"event":"cron_environment"',
            (string) $text,
            'the PHP version is printed but never logged, so the evidence go-live-checklist.md item 14 '
            . 'asks for disappears with the terminal'
        );
        $this->assertStringContains(
            '"php":"' . PHP_VERSION . '"',
            (string) $text,
            'the cron_environment line does not carry the PHP version'
        );
    }

    /** Run bin/cron.php in its own process and return stdout + stderr. */
    private function runCron(): string
    {
        if (!function_exists('proc_open')) {
            $this->skip('proc_open is disabled in this PHP, so the cron cannot be run as a child');
        }

        $script = Paths::root() . '/bin/cron.php';
        if (!is_file($script)) {
            $this->fail('bin/cron.php is missing');
        }

        $process = proc_open(
            [PHP_BINARY, $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            Paths::root()
        );

        if (!is_resource($process)) {
            $this->skip('could not start a child process for bin/cron.php');
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $out . $err;
    }
}
