<?php

declare(strict_types=1);

namespace Speedat\Tests\Notify;

use Speedat\Core\Config;
use Speedat\Core\Mail;
use Speedat\Core\Paths;
use Speedat\Domain\Notify\Notifier;
use Speedat\Tests\TestCase;

/**
 * ONE WAY OUT, AND IT IS NOT mail().
 *
 * Hostinger caps PHP's mail() at ten messages a minute and a hundred a rolling
 * day and then blocks it SILENTLY (docs/hostinger-constraints.md section 9). A
 * notification dropped without an error is the worst failure this application
 * can have: the office's only warning that nobody has published rates for a
 * fortnight would be the message that went missing.
 *
 * So mail() is not a fallback, not an option and not a default. These tests are
 * the guard, and they are written to fail the day somebody adds it back -
 * whether as a call, as a transport, or as a value in the sample config.
 *
 * The source scan uses the tokeniser rather than a grep because a grep for
 * "mail(" matches every `Mail(`, `->mail()` and `sendmail(` in the tree and
 * would either miss the real thing or cry wolf until somebody deleted the test.
 */
final class MailPathTest extends TestCase
{
    /**
     * Files exempt from the scan, with the reason. Keep this list empty if you
     * can: every entry is a place where the cap can bite.
     *
     * @var array<string,string>
     */
    private const EXEMPT = [];

    public function testNoApplicationFileCallsPhpsMailFunction(): void
    {
        $offenders = [];

        // A scan that found nothing because it looked nowhere is a green test
        // guarding an empty room.
        $this->assertTrue(count($this->applicationFiles()) > 100, 'the scan actually walked the tree');

        foreach ($this->applicationFiles() as $relative => $absolute) {
            if (isset(self::EXEMPT[$relative])) {
                continue;
            }

            foreach ($this->mailCalls((string) file_get_contents($absolute)) as $line) {
                $offenders[] = $relative . ':' . $line;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'mail() is capped and silently blocked on this host; send through Domain\Notify instead: '
            . implode(', ', $offenders)
        );
    }

    /** The tokeniser finds itself, so the finder is checked against a known sample. */
    public function testTheScannerWouldActuallyCatchIt(): void
    {
        $code = "<?php\n\$ok = mail('a@b.test', 's', 'b');\n";

        $this->assertSame([2], $this->mailCalls($code), 'a plain call is found, on its own line');
        $this->assertSame([2], $this->mailCalls("<?php\n\$ok = @mail(\$to, \$s, \$b);\n"), 'and a silenced one');
        $this->assertSame([2], $this->mailCalls("<?php\n\$ok = \\mail(\$to, \$s, \$b);\n"), 'and a root-namespaced one');

        // The things a grep for "mail(" would wrongly flag.
        $this->assertSame([], $this->mailCalls("<?php\n\$app->mail()->toOffice('s', 'b');\n"), 'a method call is not it');
        $this->assertSame([], $this->mailCalls("<?php\nMail::fromConfig();\n"), 'nor a static call on the class');
        $this->assertSame([], $this->mailCalls("<?php\nnew Mail(\$notifier);\n"), 'nor constructing it');
        $this->assertSame([], $this->mailCalls("<?php\nfunction mail(): void {}\n"), 'nor declaring one');
        $this->assertSame([], $this->mailCalls("<?php\n// mail(\$to, \$s, \$b) would be capped\n"), 'nor a comment');
        $this->assertSame([], $this->mailCalls("<?php\n\$s = 'mail(\$to)';\n"), 'nor a string');
    }

    /** A configuration asking for mail() gets nothing, loudly - never a capped sender. */
    public function testTheMailTransportNoLongerExists(): void
    {
        $saved = Config::str('mail.transport');

        try {
            foreach (['mail', 'sendmail', 'php', 'MAIL'] as $wanted) {
                Config::set('mail.transport', $wanted);

                $this->assertSame(
                    'null',
                    Notifier::fromConfig()->transportName(),
                    '"' . $wanted . '" must not select a transport that sends anything'
                );
                $this->assertSame('null', Mail::fromConfig()->transportName(), 'and Core\Mail agrees');
            }
        } finally {
            Config::set('mail.transport', $saved);
        }
    }

    /** The two transports that do exist, and the fact that they are the same code path. */
    public function testSmtpAndLogAreTheOnlyTransports(): void
    {
        $savedTransport = Config::str('mail.transport');
        $savedHost      = Config::str('mail.smtp.host');

        try {
            Config::set('mail.transport', 'log');
            $this->assertSame('null', Notifier::fromConfig()->transportName(), 'log records and sends nothing');

            Config::set('mail.transport', 'smtp');
            Config::set('mail.smtp.host', 'smtp.example.invalid');
            $this->assertSame('smtp', Notifier::fromConfig()->transportName());
            $this->assertSame('smtp', Mail::fromConfig()->transportName(), 'Core\Mail is the same notifier');

            // An smtp transport with no host is a misconfiguration, and it must
            // degrade to sending nothing rather than to sending through the MTA.
            Config::set('mail.smtp.host', '');
            $this->assertSame('null', Notifier::fromConfig()->transportName());
        } finally {
            Config::set('mail.transport', $savedTransport);
            Config::set('mail.smtp.host', $savedHost);
        }
    }

    /** Core\Mail must hold no transport of its own: one sender, or this was pointless. */
    public function testCoreMailOnlyDelegates(): void
    {
        $source = (string) file_get_contents(Paths::root() . '/app/Core/Mail.php');

        foreach (['stream_socket_client', 'fsockopen', 'EHLO', 'STARTTLS', 'AUTH LOGIN', 'boundary', 'base64_encode'] as $protocol) {
            $this->assertStringNotContains(
                $protocol,
                $source,
                'Core\Mail must not carry a second copy of the transport or the MIME encoder'
            );
        }

        $this->assertStringContains('Speedat\Domain\Notify\Notifier', $source, 'it delegates to the one sender');
    }

    /** What a new host copies must not point at the capped sender. */
    public function testTheSampleConfigDefaultsToSmtp(): void
    {
        /** @var mixed $sample */
        $sample = require Paths::root() . '/config/config.sample.php';

        $this->assertTrue(is_array($sample), 'config.sample.php returns an array');

        /** @var array<string,mixed> $sample */
        $mail = is_array($sample['mail'] ?? null) ? $sample['mail'] : [];
        $smtp = is_array($mail['smtp'] ?? null) ? $mail['smtp'] : [];

        $this->assertSame('smtp', $mail['transport'] ?? null, 'the documented default is SMTP');

        foreach (['host', 'port', 'secure', 'user', 'pass', 'timeout'] as $key) {
            $this->assertTrue(array_key_exists($key, $smtp), 'mail.smtp.' . $key . ' is documented');
        }

        // 465 with implicit TLS, or 587 with STARTTLS. Never a bare port.
        $this->assertTrue(
            ($smtp['port'] ?? 0) === 465 && ($smtp['secure'] ?? '') === 'ssl'
            || ($smtp['port'] ?? 0) === 587 && ($smtp['secure'] ?? '') === 'tls',
            'the sample pairs the port with the right encryption'
        );

        foreach (['from', 'from_name', 'office'] as $key) {
            $this->assertTrue(array_key_exists($key, $mail), 'mail.' . $key . ' is documented');
        }
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * Every PHP file that can run on the host: app/, bin/ and public/.
     *
     * Not app/ alone, because the cap does not care which directory reached it.
     * bin/cron.php is the one thing here that runs unattended and the only
     * caller of the alerts, so a `mail(` added there for a "quick" notification
     * would be dropped with nobody watching - exactly the failure this scan
     * exists to make impossible. public/ is in for the same reason: it is the
     * front door, and a form that answered with mail() would be blocked on the
     * first busy afternoon.
     *
     * @return array<string,string> repository-relative path => absolute path
     */
    private function applicationFiles(): array
    {
        $root  = Paths::root();
        $files = [];

        foreach (['app', 'bin', 'public'] as $directory) {
            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );

            /** @var \SplFileInfo $entry */
            foreach ($walk as $entry) {
                if (!$entry->isFile() || $entry->getExtension() !== 'php') {
                    continue;
                }

                $relative         = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
                $files[$relative] = $entry->getPathname();
            }
        }

        return $files;
    }

    /**
     * The lines that call the global mail() function.
     *
     * A call is the name "mail" followed by "(", not preceded by `->`, `?->`,
     * `::`, `function` or `new`.
     *
     * PHP 8 hands a namespaced name back as ONE token, so `\mail(` arrives as
     * T_NAME_FULLY_QUALIFIED "\mail" and never as a backslash plus a T_STRING.
     * Matching only T_STRING would therefore let `\mail(` through - which is
     * exactly how a scanner like this ends up passing while the thing it guards
     * against is in the tree.
     *
     * @return list<int>
     */
    private function mailCalls(string $source): array
    {
        $tokens = token_get_all($source);
        $lines  = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token)) {
                continue;
            }

            $name = strtolower($token[1]);

            $isName = $token[0] === T_STRING && $name === 'mail'
                || $token[0] === T_NAME_FULLY_QUALIFIED && $name === '\\mail';

            if (!$isName) {
                continue;
            }

            if (!$this->isFollowedByParenthesis($tokens, $i) || $this->isQualified($tokens, $i)) {
                continue;
            }

            $lines[] = (int) $token[2];
        }

        return $lines;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private function isFollowedByParenthesis(array $tokens, int $i): bool
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            $next = $tokens[$j];

            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $next === '(';
        }

        return false;
    }

    /**
     * Whether the name belongs to something other than the global function: a
     * method, a class constant, a declaration or a class being instantiated.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private function isQualified(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            $previous = $tokens[$j];

            if (is_array($previous) && in_array($previous[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($previous)) {
                return in_array(
                    $previous[0],
                    [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW],
                    true
                );
            }

            // Everything else - `@`, `=`, `(`, `,`, `;` - is a plain call site.
            return false;
        }

        return false;
    }
}
