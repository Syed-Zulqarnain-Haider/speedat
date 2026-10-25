<?php

declare(strict_types=1);

namespace Speedat\Core;

/**
 * Structured logs: one JSON object per line, so a log can be grepped by
 * `event` without parsing prose. The direct port of src/lib/log.ts, writing
 * to storage/logs/ instead of a hosting provider's log drain, because shared
 * hosting has no log drain.
 *
 * Two rules that are not negotiable:
 *   - Field names that look like secrets are redacted before writing. Logs get
 *     copied into support tickets.
 *   - A failing logger never breaks a request. If the file cannot be written
 *     the line goes to PHP's own error_log and the request carries on.
 */
final class Log
{
    private const REDACT = '/pass|secret|token|key|authorization|cookie|credential/i';

    /** Never let one runaway request fill the disk. */
    private const MAX_LINE = 8192;

    private static ?string $requestId = null;

    /** Correlates every line written during one request. */
    public static function requestId(): string
    {
        if (self::$requestId === null) {
            self::$requestId = bin2hex(random_bytes(6));
        }

        return self::$requestId;
    }

    /** @param array<string,mixed> $fields */
    public static function info(string $event, array $fields = []): void
    {
        self::write('info', $event, $fields);
    }

    /** @param array<string,mixed> $fields */
    public static function warn(string $event, array $fields = []): void
    {
        self::write('warn', $event, $fields);
    }

    /** @param array<string,mixed> $fields */
    public static function error(string $event, array $fields = []): void
    {
        self::write('error', $event, $fields);
    }

    /**
     * Longest exception message written. Long enough for any driver message
     * worth reading, short enough that a message carrying a payload carries only
     * the start of one.
     */
    private const MAX_MESSAGE = 300;

    /**
     * Log a throwable with its stack trace. The trace goes to the file only -
     * never to the browser, which is what Response's error page is for.
     *
     * @param array<string,mixed> $fields
     */
    public static function exception(string $event, \Throwable $e, array $fields = []): void
    {
        self::write('error', $event, $fields + [
            'errorClass'   => $e::class,
            'errorMessage' => self::safeMessage($e),
            'file'         => $e->getFile() . ':' . $e->getLine(),
            'trace'        => array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
        ]);
    }

    /**
     * An exception message with the customer's own bytes taken out of it.
     *
     * WHY. REDACT above matches FIELD NAMES, so it never applied to a message,
     * and $e->getMessage() went in verbatim. MySQL quotes the offending data in
     * its own text:
     *
     *   SQLSTATE[22007]: 1366 Incorrect string value: '\xC3( Kha...' for column
     *   `speedat`.`leads`.`name` at row 1
     *
     * A fragment of a name is mild. The same code path logs a fragment of a
     * contact message, and docs/quality-bar.md §2 says "no customer message
     * bodies beyond what the inbox needs". 1062 (duplicate entry) quotes the
     * value too, which on this schema means an email address.
     *
     * The substitution keeps the diagnosis and drops the payload: which error,
     * which column, which row - none of which is the customer's - and an ellipsis
     * where their bytes were. The length cap then catches whatever a future
     * driver version decides to quote in a shape this pattern does not know.
     *
     * The log lives above the web root, so this is hygiene rather than exposure.
     * Hygiene is still the difference between a log that can be pasted into a
     * support ticket and one that cannot.
     */
    private static function safeMessage(\Throwable $e): string
    {
        $message = $e->getMessage();

        if ($e instanceof \PDOException) {
            // Both spellings MySQL uses: "Incorrect string value: '…'",
            // "Incorrect integer value: '…'", and "Duplicate entry '…' for key".
            $message = (string) preg_replace(
                ['/(Incorrect [a-z ]*value): \'(?:[^\']|\'\')*\'/i', '/(Duplicate entry) \'(?:[^\']|\'\')*\'/i'],
                ['$1: \'...\'', '$1 \'...\''],
                $message
            );
        }

        return Str::limit($message, self::MAX_MESSAGE);
    }

    /** @param array<string,mixed> $fields */
    private static function write(string $level, string $event, array $fields): void
    {
        $line = json_encode(
            [
                'ts'    => Clock::utcNow()->format('Y-m-d\TH:i:s.v\Z'),
                'level' => $level,
                'event' => $event,
                'rid'   => self::requestId(),
            ] + self::redact($fields),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($line === false) {
            $line = '{"level":"error","event":"log_encode_failed"}';
        }

        if (strlen($line) > self::MAX_LINE) {
            $line = substr($line, 0, self::MAX_LINE - 20) . '...","truncated":true}';
        }

        // Paths::logs() can THROW - it refuses a `paths.logs` that points inside
        // the web root - and Log's second rule is that a failing logger never
        // breaks a request. An unusable log directory therefore falls through to
        // the host's error log, where the ConfigError's own sentence says what is
        // wrong, instead of turning every logged line into a second exception
        // (including the ones logged from an exception handler, which is where
        // that would be hardest to read).
        try {
            $dir = Paths::logs();
        } catch (\Throwable $e) {
            error_log('speedat log directory unusable: ' . $e->getMessage());
            error_log('speedat ' . $line);

            return;
        }

        if (Paths::ensureWritable($dir)) {
            $file = $dir . '/app-' . Clock::utcNow()->format('Y-m-d') . '.log';
            if (@file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) !== false) {
                return;
            }
        }

        // Last resort: the host's own error log. Losing a line is better than
        // losing the request.
        error_log('speedat ' . $line);
    }

    /**
     * @param  array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function redact(array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            if (is_string($k) && preg_match(self::REDACT, $k) === 1) {
                $out[$k] = '[redacted]';
                continue;
            }
            if (is_array($v)) {
                /** @var array<string,mixed> $v */
                $out[$k] = self::redact($v);
                continue;
            }
            $out[$k] = is_scalar($v) || $v === null ? $v : get_debug_type($v);
        }

        return $out;
    }
}
