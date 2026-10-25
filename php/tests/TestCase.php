<?php

declare(strict_types=1);

namespace Speedat\Tests;

/**
 * The test framework, in one class. No PHPUnit, because there is no Composer
 * on the target host and a dependency that only exists to run tests is still a
 * dependency somebody has to install before they can change anything.
 *
 * A test is a public method whose name starts with `test` on a class that
 * extends this one, in a file called *Test.php under tests/. bin/test.php finds
 * them. setUp() runs before each test and tearDown() after, whatever happened.
 *
 * Assertions throw. The runner catches, records and carries on to the next
 * test, so one broken assertion does not hide the twenty tests behind it.
 */
abstract class TestCase
{
    private int $assertions = 0;

    /** @var list<string> */
    private array $skipReasons = [];

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function assertionCount(): int
    {
        return $this->assertions;
    }

    /** @return list<string> */
    public function skipReasons(): array
    {
        return $this->skipReasons;
    }

    public function resetCounters(): void
    {
        $this->assertions  = 0;
        $this->skipReasons = [];
    }

    /**
     * Stop this test without failing it, saying why.
     *
     * A skip must always carry a reason: "skipped" with no explanation is how
     * a test stays switched off for a year.
     */
    protected function skip(string $why): never
    {
        $this->skipReasons[] = $why;

        throw new SkippedTest($why);
    }

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        $this->assertions++;

        if ($value !== true) {
            $this->fail($message !== '' ? $message : 'Expected true, got ' . self::describe($value));
        }
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        $this->assertions++;

        if ($value !== false) {
            $this->fail($message !== '' ? $message : 'Expected false, got ' . self::describe($value));
        }
    }

    /** Identity, not equality: 1 and "1" are a failure, which is usually the bug. */
    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($expected !== $actual) {
            $this->fail(
                ($message !== '' ? $message . ' - ' : '')
                . 'expected ' . self::describe($expected) . ', got ' . self::describe($actual)
            );
        }
    }

    protected function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($expected === $actual) {
            $this->fail(($message !== '' ? $message . ' - ' : '') . 'expected something other than ' . self::describe($expected));
        }
    }

    protected function assertNull(mixed $value, string $message = ''): void
    {
        $this->assertions++;

        if ($value !== null) {
            $this->fail($message !== '' ? $message : 'Expected null, got ' . self::describe($value));
        }
    }

    protected function assertNotNull(mixed $value, string $message = ''): void
    {
        $this->assertions++;

        if ($value === null) {
            $this->fail($message !== '' ? $message : 'Expected a value, got null');
        }
    }

    /**
     * How many things are in there.
     *
     * The message prints BOTH counts, because "expected 2" on its own sends the
     * reader back to the test to find out what it got, and `assertSame(2,
     * count($x))` - which is what people write when this helper is missing -
     * prints two bare integers with no hint that they are lengths.
     *
     * @param \Countable|array<array-key,mixed> $actual
     */
    protected function assertCount(int $expected, \Countable|array $actual, string $message = ''): void
    {
        $this->assertions++;

        $count = count($actual);

        if ($count !== $expected) {
            $this->fail(
                ($message !== '' ? $message . ' - ' : '')
                . 'expected ' . $expected . ' item(s), got ' . $count
                . (is_array($actual) ? ': ' . self::truncate(self::keysOf($actual)) : '')
            );
        }
    }

    /** @param array<array-key,mixed> $actual */
    private static function keysOf(array $actual): string
    {
        $keys = [];
        foreach (array_keys($actual) as $key) {
            $keys[] = (string) $key;
        }

        return '[' . implode(', ', $keys) . ']';
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;

        if (!str_contains($haystack, $needle)) {
            $this->fail(
                ($message !== '' ? $message . ' - ' : '')
                . 'expected to find "' . $needle . '" in "' . self::truncate($haystack) . '"'
            );
        }
    }

    protected function assertStringNotContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;

        if (str_contains($haystack, $needle)) {
            $this->fail(
                ($message !== '' ? $message . ' - ' : '')
                . 'did not expect to find "' . $needle . '" in "' . self::truncate($haystack) . '"'
            );
        }
    }

    /**
     * Assert that a callable throws.
     *
     * @param class-string<\Throwable> $expected
     */
    protected function assertThrows(string $expected, \Closure $work, string $message = ''): \Throwable
    {
        $this->assertions++;

        try {
            $work();
        } catch (\Throwable $e) {
            if (!$e instanceof $expected) {
                $this->fail(
                    ($message !== '' ? $message . ' - ' : '')
                    . 'expected ' . $expected . ', got ' . $e::class . ': ' . $e->getMessage()
                );
            }

            return $e;
        }

        $this->fail(($message !== '' ? $message . ' - ' : '') . 'expected ' . $expected . ', nothing was thrown');
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    /**
     * A value, for a failure message, WITH ITS TYPE when the type is the thing
     * that differs.
     *
     * assertSame() compares with `!==`, so 500 and 500.0 are a failure. This
     * used to render both as "500" and print "expected 500, got 500", which
     * reads as a bug in the test framework and sends the reader looking in
     * entirely the wrong place - the real answer being that a division had
     * turned a rupee count into a float. Integers and floats therefore carry
     * their type; strings keep their quotes, which already distinguish "500"
     * from 500.
     */
    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"' . self::truncate($value) . '"',
            is_bool($value)   => $value ? 'true' : 'false',
            $value === null   => 'null',
            is_int($value)    => 'int(' . $value . ')',
            // var_export keeps the point: 500.0 stays "500.0" where a cast to
            // string would flatten it back to "500" and lose the whole message.
            is_float($value)  => 'float(' . var_export($value, true) . ')',
            is_array($value)  => 'array(' . count($value) . ')',
            default           => get_debug_type($value),
        };
    }

    private static function truncate(string $value): string
    {
        return strlen($value) > 120 ? substr($value, 0, 117) . '...' : $value;
    }
}
