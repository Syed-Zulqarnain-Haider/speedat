<?php

declare(strict_types=1);

namespace Speedat\Tests\Tracking\Support;

use Speedat\Domain\Tracking\TrackingProvider;
use Speedat\Domain\Tracking\TrackReport;

/**
 * A tracking service the test scripts, and that remembers what it was asked.
 *
 * Every number is accepted by register() unless reject() said otherwise, and
 * every fetch() answers "nothing yet" (a report with no stage and no scans)
 * unless answer() gave it something or omit() left the number out. The calls
 * are kept in order as "register:<number>:<code>" and "fetch:<number>:<code>",
 * so a test can say both WHAT was asked and in WHICH ORDER.
 */
final class FakeProvider implements TrackingProvider
{
    /** @var list<string> */
    private array $calls = [];

    /** @var array<string,string> number => reason */
    private array $rejections = [];

    /** @var array<string,TrackReport> */
    private array $reports = [];

    /** @var array<string,true> numbers fetch() leaves out of its answer */
    private array $omitted = [];

    public function __construct(private readonly int $batch = 40)
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function batchSize(): int
    {
        return $this->batch;
    }

    public function register(array $requests): array
    {
        $out = [];
        foreach ($requests as $request) {
            $this->calls[]          = 'register:' . $request->number . ':' . $request->carrierCode;
            $out[$request->number] = $this->rejections[$request->number] ?? '';
        }

        return $out;
    }

    public function fetch(array $requests): array
    {
        $out = [];
        foreach ($requests as $request) {
            $this->calls[] = 'fetch:' . $request->number . ':' . $request->carrierCode;
            if (isset($this->omitted[$request->number])) {
                continue;
            }
            $out[$request->number] = $this->reports[$request->number] ?? new TrackReport(null, []);
        }

        return $out;
    }

    /* ------------------------------------------------------------ scripting */

    public function reject(string $number, string $reason): self
    {
        $this->rejections[$number] = $reason;

        return $this;
    }

    public function answer(string $number, TrackReport $report): self
    {
        $this->reports[$number] = $report;

        return $this;
    }

    /** Leave this number out of fetch()'s answer, as a provider reply sometimes does. */
    public function omit(string $number): self
    {
        $this->omitted[$number] = true;

        return $this;
    }

    /* ------------------------------------------------------- what was asked */

    /** @return list<string> */
    public function calls(): array
    {
        return $this->calls;
    }

    /** Forget what was asked so far; the script stays. */
    public function clearCalls(): void
    {
        $this->calls = [];
    }

    /** @return list<string> the numbers register() was given, in order */
    public function registered(): array
    {
        return $this->numbers('register');
    }

    /** @return list<string> the numbers fetch() was given, in order */
    public function fetched(): array
    {
        return $this->numbers('fetch');
    }

    /** @return list<string> */
    private function numbers(string $kind): array
    {
        $out = [];
        foreach ($this->calls as $call) {
            $parts = explode(':', $call);
            if ($parts[0] === $kind) {
                $out[] = $parts[1];
            }
        }

        return $out;
    }
}
