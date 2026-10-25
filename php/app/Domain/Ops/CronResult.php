<?php

declare(strict_types=1);

namespace Speedat\Domain\Ops;

/**
 * What one cron run did, in a form both callers can use: the HTTP handler
 * turns it into JSON, the CLI wrapper reads exitCode() and prints the lines.
 *
 * `ok` is false when ANY job threw. That matters more than it looks: hPanel,
 * a shell `&&` and every monitoring tool ever written read the exit status and
 * nothing else, so a run that alerted on nothing because the database was
 * refusing connections must not report success.
 */
final class CronResult
{
    /**
     * @param array<string,mixed> $checks   what each check found - the JSON body
     * @param array<string,string> $jobs    job name => one-line summary, for the cron log
     * @param list<string>        $failures job names that threw, with the reason
     */
    public function __construct(
        public readonly array $checks,
        public readonly array $jobs,
        public readonly array $failures,
        public readonly int $elapsedMs,
    ) {
    }

    public function ok(): bool
    {
        return $this->failures === [];
    }

    public function exitCode(): int
    {
        return $this->ok() ? 0 : 1;
    }

    /** 500 on a failure, so an HTTP-triggered cron shows up red as well. */
    public function httpStatus(): int
    {
        return $this->ok() ? 200 : 500;
    }

    /** @return array<string,mixed> */
    public function body(): array
    {
        return ['ok' => $this->ok()]
            + $this->checks
            + ['jobs' => $this->jobs, 'failures' => $this->failures, 'ms' => $this->elapsedMs];
    }

    public function response(): OpsResponse
    {
        return OpsResponse::json($this->body(), $this->httpStatus());
    }

    /** The cron log's view: one line per job, and the reason for any failure. */
    public function text(): string
    {
        $lines = [];
        foreach ($this->jobs as $name => $summary) {
            $lines[] = '  ok   ' . str_pad($name, 14) . $summary;
        }
        foreach ($this->failures as $failure) {
            $lines[] = '  FAIL ' . $failure;
        }
        $lines[] = 'Finished in ' . $this->elapsedMs . 'ms with ' . count($this->failures) . ' failure(s).';

        return implode("\n", $lines) . "\n";
    }
}
