<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Paths;
use Speedat\Tests\TestCase;

/**
 * Bootstrap's last resort, both halves, against the real binary.
 *
 * WHAT WENT WRONG BEFORE. An uncaught fatal in bin/cron.php used to print an
 * HTML error page onto stdout and exit 0. hPanel, `&&` in a shell wrapper and
 * every monitoring tool ever written read the exit status and nothing else, so a
 * job that had done none of its work reported success, night after night, and
 * the only evidence was a mailed-out page of markup nobody reads.
 *
 * WHY CHILD PROCESSES. The exit code IS the thing under test, and a process
 * cannot read its own. A memory-exhaustion fatal cannot be provoked in-process
 * either without taking the runner down with it. So tests/Core/bootstrap-child.php
 * is run for real, four ways, and what comes back out is read.
 *
 * WHY php-cgi FOR THE WEB HALF. `PHP_SAPI` is not settable; the only honest way
 * to exercise the non-CLI branch is a SAPI that is not CLI. php-cgi ships beside
 * php in every build, reports `cgi-fcgi`, and prints its status line and headers
 * to stdout where they can be read. It is skipped, with a reason, where it is
 * not installed.
 *
 * NOTE: it is run WITHOUT `-q`. That flag means "send no headers", which makes
 * headers_sent() true before the first line of the script, and lastResort()
 * correctly declines to write headers that would be thrown away - so the whole
 * branch would be skipped and the test would prove nothing.
 */
final class BootstrapCliTest extends TestCase
{
    public function setUp(): void
    {
        if (!function_exists('proc_open')) {
            $this->skip('proc_open is disabled in this PHP, so no child process can be started');
        }
        if (!is_file($this->child())) {
            $this->skip('tests/Core/bootstrap-child.php is missing');
        }
    }

    /**
     * The control. Installing the handlers must not disturb a run that works -
     * a safety net that catches the acrobat mid-act is not a safety net.
     */
    public function testAHealthyCliRunIsUntouched(): void
    {
        $result = $this->runChild('ok');

        $this->assertSame(0, $result['code'], 'a healthy command exits 0');
        $this->assertSame('child ok', trim($result['out']));
        $this->assertSame('', trim($result['err']), 'and says nothing on stderr');
    }

    /**
     * An uncaught exception: non-zero exit, one plain line on stderr, and NO
     * HTML anywhere. Anything that reads the exit status now sees the failure.
     */
    public function testAnUncaughtExceptionExitsNonZeroWithOnePlainLineOnStderr(): void
    {
        $result = $this->runChild('uncaught');

        $this->assertSame(1, $result['code'], 'a cron job that failed must not report success');

        $err = trim($result['err']);
        $this->assertSame(
            'speedat: the command failed - see storage/logs/app-*.log for the reason.',
            $err,
            'one line, and it names where the reason is'
        );

        // Not a page, not a stack trace, not a file path from inside the app.
        $this->assertStringNotContains('<', $err);
        $this->assertStringNotContains('RuntimeException', $err, 'the class name went to the log, not to stderr');
        $this->assertStringNotContains('<html', $result['out'], 'and nothing printed a page onto stdout');
        $this->assertStringNotContains('<!doctype', $result['out']);
    }

    /**
     * A warning is not survivable either. set_error_handler() turns notices into
     * exceptions precisely so they cannot be walked past, and the line after the
     * warning must never run.
     */
    public function testAWarningIsFatalRatherThanIgnored(): void
    {
        $result = $this->runChild('warning');

        $this->assertSame(1, $result['code']);
        $this->assertStringContains('speedat: the command failed', $result['err']);
        $this->assertStringNotContains('must never be reached', $result['out'], 'execution stopped at the warning');
    }

    /**
     * The half that needed the shutdown function: a real E_ERROR. Memory
     * exhaustion never reaches set_exception_handler(), so without
     * register_shutdown_function() this is the case that exits 0.
     */
    public function testAFatalThatNoHandlerCanCatchStillExitsNonZero(): void
    {
        $result = $this->runChild('fatal');

        $this->assertSame(1, $result['code'], 'the shutdown function is what makes this non-zero');
        $this->assertStringContains('speedat: the command failed', $result['err']);
        $this->assertStringNotContains('must never be reached', $result['out']);
    }

    /**
     * The web half, and the contrast that explains why the two are split.
     *
     * Under a web SAPI the same uncaught exception produces a 500, a no-store
     * header and a plain page - and an exit code of 0, because nothing reads a
     * web request's exit status. That is exactly why the CLI branch has an
     * exit(1) of its own rather than sharing this path.
     */
    public function testAWebRequestGetsAPageAndA500RatherThanAnExitCode(): void
    {
        $cgi = $this->cgiBinary();
        if ($cgi === null) {
            $this->skip('php-cgi is not installed beside ' . PHP_BINARY . ', so the non-CLI SAPI cannot be exercised');
        }

        $result = $this->runChild('uncaught', $cgi);

        $this->assertStringContains('Status: 500', $result['out'], 'the visitor gets a 500');
        $this->assertStringContains('Cache-Control: no-store', $result['out'], 'and it is never cached');
        $this->assertStringContains('Something went wrong', $result['out']);

        // The page says nothing about what broke: that went to storage/logs.
        $this->assertStringNotContains('RuntimeException', $result['out']);
        $this->assertStringNotContains('bootstrap-child.php', $result['out']);

        // And the stderr line is CLI-only - a web server's error log is not
        // where a visitor-facing failure belongs.
        $this->assertStringNotContains('speedat: the command failed', $result['err']);
    }

    // ---------------------------------------------------------------- helpers

    private function child(): string
    {
        return Paths::root() . '/tests/Core/bootstrap-child.php';
    }

    /** php-cgi beside the running binary, or null. */
    private function cgiBinary(): ?string
    {
        $suffix = str_ends_with(strtolower(PHP_BINARY), '.exe') ? '.exe' : '';

        $candidate = dirname(PHP_BINARY) . '/php-cgi' . $suffix;

        return is_file($candidate) ? $candidate : null;
    }

    /**
     * Run the child and collect its exit code, stdout and stderr.
     *
     * The mode travels in the environment rather than in $argv because
     * register_argc_argv is off by default in the CGI SAPI.
     *
     * @return array{code:int,out:string,err:string}
     */
    private function runChild(string $mode, ?string $binary = null): array
    {
        $pipes   = [];
        $process = proc_open(
            [$binary ?? PHP_BINARY, $this->child()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            Paths::root(),
            ['SPEEDAT_CHILD_MODE' => $mode] + $this->inheritedEnvironment()
        );

        if (!is_resource($process)) {
            $this->fail('could not start ' . $this->child());
        }

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }

    /**
     * The real process environment, to hand to a child.
     *
     * Passing an env array to proc_open() REPLACES the environment rather than
     * adding to it, and on Windows a PHP child with no SystemRoot or PATH does
     * not start at all. So it has to be carried across deliberately.
     *
     * getenv(), NOT $_SERVER. $_SERVER is a variable that any earlier test may
     * have replaced - AppTest does, with five fake CGI keys - and copying those
     * into a php-cgi child makes it look like a web request with no
     * REDIRECT_STATUS, which php-cgi refuses with "Security Alert! The PHP CGI
     * cannot be accessed directly". That is how this test came to pass on its own
     * and fail in the full run. getenv() reads the process environment, which no
     * test can quietly edit.
     *
     * REDIRECT_STATUS is then set on purpose: it is what php-cgi's
     * force-cgi-redirect guard looks for, and setting it means this test does not
     * depend on which variables happen to be exported on the machine.
     *
     * @return array<string,string>
     */
    private function inheritedEnvironment(): array
    {
        $environment = ['REDIRECT_STATUS' => '1'];

        $real = getenv();
        foreach ($real as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }
}
