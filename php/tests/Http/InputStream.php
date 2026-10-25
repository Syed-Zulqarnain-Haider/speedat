<?php

declare(strict_types=1);

namespace Speedat\Tests\Http;

/**
 * A stand-in for `php://input`, so a JSON route can be tested in-process.
 *
 * `Core\Request::json()` opens `php://input`, which is the request body under a
 * web server and standard input under the CLI - and standard input in a test
 * runner is either empty or, worse, a terminal that would block. So
 * `HttpTestCase::postJson()` unregisters the built-in `php://` wrapper, puts
 * this one in its place for the length of one request, and restores it
 * immediately afterwards.
 *
 * It answers `php://input` and NOTHING ELSE: every other `php://` stream -
 * `memory`, `temp`, `stdout`, `filter` - returns false from `stream_open()`,
 * which is the honest answer for a wrapper that does not implement them and
 * makes a test that accidentally relies on one fail loudly rather than read the
 * wrong bytes.
 */
final class InputStream
{
    /** Set by the caller immediately before the request, cleared after it. */
    public static string $body = '';

    /** Required by the streams API; PHP writes the stream context here. */
    public mixed $context = null;

    private string $data = '';

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if ($path !== 'php://input') {
            return false;
        }

        $this->data     = self::$body;
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->data, $this->position, max(0, $count));
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return false;
    }

    /** @return array<array-key,mixed> */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->data)];
    }

    public function stream_close(): void
    {
        $this->data     = '';
        $this->position = 0;
    }
}
