<?php

declare(strict_types=1);

namespace Speedat\Tests\Notify;

use Speedat\Tests\TestCase;
use Speedat\Vendor\Smtp\Message;
use Speedat\Vendor\Smtp\SmtpClient;
use Speedat\Vendor\Smtp\SmtpException;
use Speedat\Vendor\Smtp\SmtpSettings;

/**
 * The SMTP client, against a fake server this test opens on 127.0.0.1 and
 * answers itself. No network, no mail server, no second process - see
 * tests/Notify/FakeSmtpServer.php for why it is shaped the way it is.
 *
 * What is covered: the command sequence, both AUTH mechanisms, dot-stuffing,
 * a refusal at every stage that can refuse, the read timeout, and the rule that
 * no credential ever reaches an exception message.
 */
final class SmtpClientTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    private ?FakeSmtpServer $fake = null;

    public function tearDown(): void
    {
        $this->fake?->close();
        $this->fake = null;
    }

    /* ===================================================================== */
    /*  the happy path                                                       */
    /* ===================================================================== */

    public function testItWalksTheWholeProtocolInOrder(): void
    {
        $fake = $this->fake();
        $fake->scriptSession('PLAIN LOGIN', [
            '235 2.7.0 Authentication successful',
            '250 2.1.0 Ok',
            '250 2.1.5 Ok',
            '354 End data with <CR><LF>.<CR><LF>',
            '250 2.0.0 Ok: queued as 4B2C1',
            '221 2.0.0 Bye',
        ]);

        $this->client()->converse($fake->clientStream(), $this->message(), true);

        $transcript = $fake->assertSaidInOrder([
            'EHLO speedat.test',
            'AUTH PLAIN ',
            'MAIL FROM:<noreply@speedat.test>',
            'RCPT TO:<office@speedat.test>',
            'DATA',
            'Subject: New message from Ayesha Khan',
            "\r\n.\r\n",
            'QUIT',
        ], $this->fail(...));

        $this->assertTrue(str_contains($transcript, 'MIME-Version: 1.0'));
        $this->assertTrue(str_contains($transcript, 'Content-Transfer-Encoding: quoted-printable'));
    }

    /** AUTH PLAIN is one round trip: NUL user NUL password, base64. */
    public function testAuthPlainSendsTheRfc4616Token(): void
    {
        $fake = $this->fake();
        $fake->scriptSession('PLAIN LOGIN', $this->tail());

        $this->client()->converse($fake->clientStream(), $this->message(), true);

        $expected = base64_encode("\0" . 'office@speedat.test' . "\0" . self::PASSWORD);
        $this->assertTrue(
            str_contains($fake->transcript(), 'AUTH PLAIN ' . $expected),
            'the AUTH PLAIN token was not the RFC 4616 one',
        );
    }

    /** A server that only offers LOGIN gets the three-step exchange. */
    public function testAuthLoginIsUsedWhenPlainIsNotOffered(): void
    {
        $fake = $this->fake();
        $fake->scriptSession('LOGIN', array_merge(
            ['334 VXNlcm5hbWU6', '334 UGFzc3dvcmQ6', '235 2.7.0 Authentication successful'],
            $this->tail(false),
        ));

        $this->client()->converse($fake->clientStream(), $this->message(), true);

        $fake->assertSaidInOrder([
            'AUTH LOGIN',
            base64_encode('office@speedat.test'),
            base64_encode(self::PASSWORD),
            'MAIL FROM:',
        ], $this->fail(...));
    }

    /** Nothing is sent to a server that was not asked for credentials. */
    public function testNoAuthHappensWhenNoUsernameIsConfigured(): void
    {
        $fake = $this->fake();
        $fake->scriptSession('PLAIN LOGIN', $this->tail(false));

        $this->client(username: '')->converse($fake->clientStream(), $this->message(), true);

        $this->assertStringNotContains('AUTH', $fake->transcript());
    }

    /* ===================================================================== */
    /*  dot-stuffing                                                         */
    /* ===================================================================== */

    /**
     * A body line that is just "." would end the message early, and a customer
     * writing one would truncate their own email.
     */
    public function testALineThatIsOnlyAFullStopIsStuffed(): void
    {
        $fake = $this->fake();
        $fake->scriptSession('PLAIN', $this->tail());

        $message = Message::create(
            'noreply@speedat.test',
            'Speedat',
            'office@speedat.test',
            'Dot test',
            "before\n.\n.hidden\nafter",
        );

        $this->client()->converse($fake->clientStream(), $message, true);

        $transcript = $fake->transcript();

        $this->assertTrue(str_contains($transcript, "\r\n..\r\n"), 'a lone dot line was not stuffed');
        $this->assertTrue(str_contains($transcript, "\r\n..hidden"), 'a leading dot was not stuffed');

        // The message still ends where it is supposed to, once.
        $this->assertSame(
            1,
            substr_count($transcript, "\r\n.\r\n"),
            'the terminator appears exactly once, and the stuffed lines are not it',
        );
    }

    /** The static helper on its own, so the rule is readable without a socket. */
    public function testDotStuffingLeavesEverythingElseAlone(): void
    {
        $this->assertSame("..\r\n", SmtpClient::dotStuff(".\r\n"));
        $this->assertSame("a.b\r\n..c\r\n", SmtpClient::dotStuff("a.b\r\n.c\r\n"));
        $this->assertSame("..start", SmtpClient::dotStuff('.start'));
        $this->assertSame('no dots here', SmtpClient::dotStuff('no dots here'));
    }

    /* ===================================================================== */
    /*  failures                                                             */
    /* ===================================================================== */

    public function testABadGreetingIsAClearException(): void
    {
        $fake = $this->fake();
        $fake->script(['554 smtp.test No SMTP service here']);

        $e = $this->assertThrows(
            SmtpException::class,
            fn () => $this->client()->converse($fake->clientStream(), $this->message(), true),
        );

        $this->assertSame('greeting', $e instanceof SmtpException ? $e->stage : '');
        $this->assertSame(554, $e instanceof SmtpException ? $e->replyCode : 0);
    }

    public function testARefusedRecipientNamesTheStageAndTheCode(): void
    {
        $fake = $this->fake();
        $fake->scriptSession('PLAIN', [
            '235 2.7.0 Authentication successful',
            '250 2.1.0 Ok',
            '550 5.1.1 <office@speedat.test>: Recipient address rejected',
        ]);

        $e = $this->assertThrows(
            SmtpException::class,
            fn () => $this->client()->converse($fake->clientStream(), $this->message(), true),
        );

        $this->assertSame('rcpt', $e instanceof SmtpException ? $e->stage : '');
        $this->assertSame(550, $e instanceof SmtpException ? $e->replyCode : 0);
        $this->assertStringContains('Recipient address rejected', $e->getMessage());
    }

    /**
     * A failed AUTH says so and says nothing else.
     *
     * Some servers echo the username back in the refusal, and an exception
     * message ends up in a log file, and a log file ends up in a support ticket.
     */
    public function testAFailedAuthNeverPutsACredentialInTheMessage(): void
    {
        $fake = $this->fake();
        $fake->scriptSession('PLAIN', ['535 5.7.8 Error: authentication failed for office@speedat.test']);

        $e = $this->assertThrows(
            SmtpException::class,
            fn () => $this->client()->converse($fake->clientStream(), $this->message(), true),
        );

        $this->assertSame('auth', $e instanceof SmtpException ? $e->stage : '');
        $this->assertSame(535, $e instanceof SmtpException ? $e->replyCode : 0);
        $this->assertStringNotContains(self::PASSWORD, $e->getMessage());
        $this->assertStringNotContains('office@speedat.test', $e->getMessage());
        $this->assertSame('The mail server rejected the credentials.', $e->getMessage());
    }

    public function testAServerThatWillNotStartTlsIsRefusedRatherThanFallenBackFrom(): void
    {
        $fake = $this->fake();
        $fake->scriptSession('', ['454 4.7.0 TLS not available due to local problem']);

        // secure = tls and the stream is NOT already private, so the client must
        // insist on STARTTLS - and must not carry on in the clear when it is
        // refused.
        $e = $this->assertThrows(
            SmtpException::class,
            fn () => $this->client(secure: SmtpSettings::SECURE_STARTTLS)
                ->converse($fake->clientStream(), $this->message(), false),
        );

        $this->assertSame('starttls', $e instanceof SmtpException ? $e->stage : '');
        $fake->assertSaidInOrder(['EHLO speedat.test', 'STARTTLS'], $this->fail(...));
        $this->assertStringNotContains('AUTH', $fake->transcript(), 'nothing was sent in the clear');
    }

    public function testAServerThatSaysNothingTimesOutInsteadOfHanging(): void
    {
        $fake = $this->fake();
        // Nothing scripted at all: the greeting never comes.

        $started = microtime(true);
        $e       = $this->assertThrows(
            SmtpException::class,
            fn () => $this->client(timeout: 1)->converse($fake->clientStream(), $this->message(), true),
        );
        $elapsed = microtime(true) - $started;

        $this->assertSame('greeting', $e instanceof SmtpException ? $e->stage : '');
        $this->assertTrue($elapsed < 5.0, 'the timeout took ' . round($elapsed, 1) . 's, which is not a timeout');
    }

    /* ===================================================================== */
    /*  settings                                                             */
    /* ===================================================================== */

    public function testCredentialsAreNeverConfiguredForAnUnencryptedConnection(): void
    {
        $e = $this->assertThrows(SmtpException::class, static fn () => new SmtpSettings(
            'smtp.test',
            25,
            SmtpSettings::SECURE_NONE,
            'office@speedat.test',
            'hunter2',
        ));

        $this->assertStringContains('unencrypted', $e->getMessage());
        $this->assertStringNotContains('hunter2', $e->getMessage());
    }

    public function testAControlCharacterInACredentialIsRefused(): void
    {
        $this->assertThrows(SmtpException::class, static fn () => new SmtpSettings(
            'smtp.test',
            465,
            SmtpSettings::SECURE_IMPLICIT,
            "office@speedat.test\r\nRCPT TO:<victim@example.com>",
            'x',
        ));
    }

    public function testImplicitTlsUsesAnSslTargetAndStartTlsUsesTcp(): void
    {
        $this->assertSame(
            'ssl://smtp.test:465',
            (new SmtpSettings('smtp.test', 465, SmtpSettings::SECURE_IMPLICIT))->target(),
        );
        $this->assertSame(
            'tcp://smtp.test:587',
            (new SmtpSettings('smtp.test', 587, SmtpSettings::SECURE_STARTTLS))->target(),
        );
    }

    public function testAnImpossibleHostOrPortIsRefusedAtConstruction(): void
    {
        $this->assertThrows(SmtpException::class, static fn () => new SmtpSettings(''));
        $this->assertThrows(SmtpException::class, static fn () => new SmtpSettings('smtp.test', 0));
        $this->assertThrows(SmtpException::class, static fn () => new SmtpSettings('smtp.test', 70_000));
        $this->assertThrows(SmtpException::class, static fn () => new SmtpSettings('smtp.test', 587, 'maybe'));
        $this->assertThrows(SmtpException::class, static fn () => new SmtpSettings('smtp.test', 587, 'tls', '', '', 0));
    }

    /* ------------------------------------------------------------ helpers */

    private function fake(): FakeSmtpServer
    {
        return $this->fake = new FakeSmtpServer();
    }

    private function client(
        string $username = 'office@speedat.test',
        string $secure = SmtpSettings::SECURE_IMPLICIT,
        int $timeout = 3,
    ): SmtpClient {
        return new SmtpClient(new SmtpSettings(
            'smtp.test',
            465,
            $secure,
            $username,
            $username === '' ? '' : self::PASSWORD,
            $timeout,
            'speedat.test',
        ));
    }

    private function message(): Message
    {
        return Message::create(
            'noreply@speedat.test',
            'Speedat International',
            'office@speedat.test',
            'New message from Ayesha Khan',
            "Name: Ayesha Khan\nPhone: 03001234567",
            null,
            null,
            'lead',
            'speedat.test',
        );
    }

    /**
     * The replies after AUTH: envelope, data, body, quit.
     *
     * @return list<string>
     */
    private function tail(bool $withAuth = true): array
    {
        $replies = [
            '250 2.1.0 Ok',
            '250 2.1.5 Ok',
            '354 End data',
            '250 2.0.0 Ok: queued',
            '221 2.0.0 Bye',
        ];

        return $withAuth ? array_merge(['235 2.7.0 Authentication successful'], $replies) : $replies;
    }
}
