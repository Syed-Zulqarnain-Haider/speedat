<?php

declare(strict_types=1);

namespace Speedat\Tests\Core;

use Speedat\Core\Clock;
use Speedat\Core\Config;
use Speedat\Core\Log;
use Speedat\Core\Paths;
use Speedat\Tests\TestCase;

/**
 * The structured log: what goes in a line, and what must not.
 *
 * The rule this file mostly exists for is the second half of
 * docs/quality-bar.md §2 - "nothing sensitive is logged ... no customer message
 * bodies beyond what the inbox needs". Log::REDACT matches FIELD NAMES, so it
 * never applied to an exception's own text, and MySQL quotes the offending DATA
 * in its message:
 *
 *   SQLSTATE[22007]: 1366 Incorrect string value: '\xC3( Kha...' for column
 *   `speedat`.`leads`.`name` at row 1
 *
 * That is a fragment of a customer's name in the log, from the code path a
 * contact form takes, and the same path logs a fragment of a message body
 * (audit round 1, finding 20).
 *
 * Every assertion below reads the file Log actually wrote, not a return value:
 * Log's whole contract is about what ends up on disk.
 */
final class LogTest extends TestCase
{
    private int $offset = 0;

    public function setUp(): void
    {
        if (!Paths::ensureWritable(Paths::logs())) {
            $this->skip('storage/logs is not writable here, so there is no file to read back');
        }

        $this->offset = $this->length();
    }

    public function tearDown(): void
    {
        Clock::freeze(null);
    }

    /* ===================================================================== */
    /*  THE FINDING                                                          */
    /* ===================================================================== */

    /**
     * A 1366 message keeps its diagnosis and loses the customer's bytes.
     *
     * What a reader needs is which error, which column and which row - none of
     * which belongs to the customer. What they do not need is the value.
     */
    public function testADriverMessageQuotingTheDataIsLoggedWithoutTheData(): void
    {
        $driverText = "SQLSTATE[22007]: 1366 Incorrect string value: '\\xC3( Khan' "
            . 'for column `speedat_php_test`.`leads`.`name` at row 1';

        Log::exception('db_write_failed', new \PDOException($driverText));

        $written = $this->since();

        $this->assertStringNotContains('Khan', $written, "the customer's own bytes must not be in the log");
        $this->assertStringNotContains('\\xC3(', $written);

        // And everything a reader actually needs survives.
        $this->assertStringContains('db_write_failed', $written);
        $this->assertStringContains('1366', $written, 'which error');
        $this->assertStringContains('leads', $written, 'which table');
        $this->assertStringContains('name', $written, 'which column');
        $this->assertStringContains('at row 1', $written, 'which row');
        $this->assertStringContains("Incorrect string value: '...'", $written, 'and an ellipsis where the value was');
    }

    /**
     * 1062 quotes the value too, and on this schema a duplicate key is an email
     * address - which is the one field a leaked fragment identifies a person by.
     */
    public function testADuplicateKeyMessageDoesNotCarryTheEmailAddress(): void
    {
        Log::exception('admin_insert_failed', new \PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry "
            . "'someone@customer.example' for key 'admins.PRIMARY'"
        ));

        $written = $this->since();

        $this->assertStringNotContains('someone@customer.example', $written);
        $this->assertStringContains('1062', $written);
        $this->assertStringContains("Duplicate entry '...'", $written);
        $this->assertStringContains('admins.PRIMARY', $written, 'which key is not a secret');
    }

    /**
     * A message shape the substitution does not know about is still bounded, so
     * a driver version that decides to quote a whole 20,000-character TEXT
     * column writes 300 characters and not 20,000.
     */
    public function testAnUnrecognisedMessageIsStillClamped(): void
    {
        Log::exception('quote_failed', new \PDOException('SQLSTATE[HY000]: ' . str_repeat('m', 5000)));

        $written = $this->since();

        $this->assertStringContains('quote_failed', $written);
        $this->assertFalse(
            str_contains($written, str_repeat('m', 400)),
            'the message is capped at 300 characters, whatever shape it has'
        );
        $this->assertStringContains(str_repeat('m', 200), $written, 'but enough of it survives to be read');
    }

    /**
     * A non-PDO exception is capped but NOT rewritten: its message is ours, and
     * mangling it would lose the sentence somebody wrote on purpose.
     */
    public function testAnOrdinaryExceptionKeepsItsOwnWords(): void
    {
        Log::exception('cron_failed', new \RuntimeException("Incorrect string value: 'this is ours'"));

        $this->assertStringContains("Incorrect string value: 'this is ours'", $this->since());
    }

    /* ===================================================================== */
    /*  THE RULES THAT WERE ALREADY THERE                                     */
    /* ===================================================================== */

    public function testAFieldNameThatLooksLikeASecretIsRedacted(): void
    {
        Log::warn('sign_in_attempt', [
            'email'      => 'someone@example.test',
            'password'   => 'hunter2',
            'api_key'    => 'AKIA-nope',
            'csrf_token' => 'abc',
            'nested'     => ['db_pass' => 'p4ss', 'host' => 'localhost'],
        ]);

        $written = $this->since();

        $this->assertStringNotContains('hunter2', $written);
        $this->assertStringNotContains('AKIA-nope', $written);
        $this->assertStringNotContains('p4ss', $written);
        $this->assertStringContains('[redacted]', $written);
        $this->assertStringContains('someone@example.test', $written, 'an address is not a secret; the inbox needs it');
        $this->assertStringContains('localhost', $written, 'and neither is a host name');
    }

    public function testEveryLineIsOneJsonObjectWithTheFieldsThatMakeItGreppable(): void
    {
        Log::info('page_view', ['path' => '/faq']);

        $lines = array_values(array_filter(explode("\n", $this->since())));
        $this->assertCount(1, $lines, 'one event, one line');

        $decoded = json_decode($lines[0], true);
        $this->assertTrue(is_array($decoded), 'and that line is JSON');

        foreach (['ts', 'level', 'event', 'rid'] as $field) {
            $this->assertTrue(isset($decoded[$field]), 'every line carries ' . $field);
        }

        $this->assertSame('info', $decoded['level'] ?? null);
        $this->assertSame('page_view', $decoded['event'] ?? null);
        $this->assertSame('/faq', $decoded['path'] ?? null);
        $this->assertSame(Log::requestId(), $decoded['rid'] ?? null, 'one request id correlates the whole request');
    }

    /**
     * Log's second non-negotiable rule: a failing logger never breaks a request.
     *
     * That rule now has one more way to be tested, because Paths::logs() can
     * THROW - it refuses a `paths.logs` inside the web root (finding 19). Before
     * the guard in write(), that ConfigError came straight out of every call to
     * Log, including the ones made from inside an exception handler.
     */
    public function testAnUnusableLogDirectoryDoesNotBreakTheCaller(): void
    {
        $was = Config::str('paths.logs');
        Config::set('paths.logs', 'public_html/logs');

        try {
            // No exception, no warning: the line goes to the host's error log.
            Log::error('something_broke', ['path' => '/quote']);
            Log::exception('something_broke', new \RuntimeException('and then this'));

            $this->assertTrue(true, 'logging survived a log directory it is not allowed to use');
        } finally {
            Config::set('paths.logs', $was);
        }
    }

    // ---------------------------------------------------------------- helpers

    private function file(): string
    {
        return Paths::logs() . '/app-' . Clock::utcNow()->format('Y-m-d') . '.log';
    }

    private function length(): int
    {
        $file = $this->file();

        return is_file($file) ? (int) filesize($file) : 0;
    }

    /** Everything written since setUp(). */
    private function since(): string
    {
        $file = $this->file();
        if (!is_file($file)) {
            $this->fail('Log wrote nothing to ' . $file);
        }

        $text = (string) file_get_contents($file);

        return $this->offset < strlen($text) ? substr($text, $this->offset) : '';
    }
}
