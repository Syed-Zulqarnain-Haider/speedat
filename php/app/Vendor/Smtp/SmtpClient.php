<?php

declare(strict_types=1);

namespace Speedat\Vendor\Smtp;

/**
 * A hand-written SMTP client: enough of RFC 5321 to hand one message to one
 * server, and nothing else.
 *
 * WHY THIS EXISTS. PHP's mail() is capped at 10 a minute and 100 a rolling day
 * on the target host and then blocks silently (docs/hostinger-constraints.md),
 * which for a courier site means the lead notification the office is waiting
 * for simply never arrives and nothing anywhere says so. The mailbox SMTP
 * allowance is thirty times larger and every step of it either succeeds or
 * raises. There is no Composer on the host, so the client is written here
 * rather than pulled in.
 *
 * WHAT IT SPEAKS
 *   greeting -> EHLO -> [STARTTLS -> EHLO] -> [AUTH] -> MAIL FROM -> RCPT TO
 *            -> DATA -> body -> "." -> QUIT
 *
 * Both TLS shapes are covered: STARTTLS on 587 (the port Hostinger documents)
 * and implicit TLS on 465, where the socket is already encrypted before the
 * greeting arrives.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *   - No connection reuse or pipelining. One message, one connection: a shared
 *     host gives one request at a time and a pooled socket across requests is
 *     a file descriptor nobody closes.
 *   - No 8BITMIME, no CHUNKING, no DSN. The body is quoted-printable, which is
 *     7-bit clean, so every server can take it as-is.
 *   - No retries. A failure raises; whether to try again is a decision for the
 *     caller who knows whether a customer is waiting.
 *   - It never writes a log line. The credentials pass through here and the
 *     surest way never to log one is to have no logger.
 */
final class SmtpClient
{
    /** A well-behaved server's reply fits in a fraction of this. */
    private const MAX_REPLY_BYTES = 8192;

    /** Guards against a server that dribbles continuation lines forever. */
    private const MAX_REPLY_LINES = 64;

    public function __construct(private readonly SmtpSettings $settings)
    {
    }

    /**
     * Connect, hand over the message, disconnect.
     *
     * @throws SmtpException on any failure, at any stage
     */
    public function send(Message $message): void
    {
        $stream = $this->open();

        try {
            // With implicit TLS the socket was encrypted by the stream wrapper
            // before the first byte, so there is nothing left to negotiate.
            $this->converse($stream, $message, $this->settings->secure === SmtpSettings::SECURE_IMPLICIT);
        } finally {
            // Best effort: the server may already have gone away, and a failure
            // to close politely must not replace the real exception.
            @fclose($stream);
        }
    }

    /**
     * The conversation itself, over a stream somebody else opened.
     *
     * This is the seam the tests use: they put a real loopback socket in front
     * of it and script the server's side, so the protocol is exercised end to
     * end without a network and without a mail server.
     *
     * @param resource $stream
     * @param bool     $encrypted true when the stream is already private, so
     *                            STARTTLS is neither needed nor attempted
     *
     * @throws SmtpException
     */
    public function converse(mixed $stream, Message $message, bool $encrypted = false): void
    {
        stream_set_timeout($stream, $this->settings->timeout);

        $this->expect($stream, 'greeting', [220]);

        $caps = $this->hello($stream);

        if ($this->settings->secure === SmtpSettings::SECURE_STARTTLS && !$encrypted) {
            $this->send_($stream, 'STARTTLS', 'starttls');
            $this->expect($stream, 'starttls', [220]);

            if (@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw SmtpException::tls('starttls');
            }

            // Capabilities advertised before TLS are not to be trusted, and
            // AUTH usually only appears after it. RFC 3207 requires the reset.
            $caps      = $this->hello($stream);
            $encrypted = true;
        }

        if ($this->settings->wantsAuth()) {
            if (!$encrypted) {
                // SmtpSettings already refuses credentials with secure=none;
                // this catches the case where STARTTLS was configured, skipped
                // and nobody noticed.
                throw SmtpException::invalid('Refusing to send SMTP credentials over an unencrypted connection.');
            }
            $this->authenticate($stream, $caps);
        }

        $this->send_($stream, 'MAIL FROM:<' . $message->fromAddress . '>', 'mail-from');
        $this->expect($stream, 'mail-from', [250]);

        foreach ($message->to as $recipient) {
            $this->send_($stream, 'RCPT TO:<' . $recipient . '>', 'rcpt');
            // 251 is "not local, will forward", which is a yes.
            $this->expect($stream, 'rcpt', [250, 251]);
        }

        $this->send_($stream, 'DATA', 'data');
        $this->expect($stream, 'data', [354]);

        $this->write($stream, self::dotStuff($message->data()) . "\r\n.\r\n", 'body');
        $this->expect($stream, 'body', [250]);

        // QUIT is a courtesy. The message is accepted the moment the 250 above
        // arrives, so a server that hangs up first has not lost anything.
        try {
            $this->send_($stream, 'QUIT', 'quit');
            $this->expect($stream, 'quit', [221]);
        } catch (SmtpException) {
            // Deliberately swallowed: see above.
        }
    }

    /**
     * Dot-stuffing, RFC 5321 section 4.5.2. A body line that begins with a full
     * stop gets a second one, because a line containing only "." is what ends
     * the message - without this, a customer's note reading "." truncates their
     * own email.
     */
    public static function dotStuff(string $data): string
    {
        return (string) preg_replace('/^\./m', '..', $data);
    }

    /* ---------------------------------------------------------------- steps */

    /**
     * EHLO, falling back to HELO for a server too old to know better.
     *
     * @param  resource            $stream
     * @return array<string,string> capability name (upper case) => its arguments
     */
    private function hello(mixed $stream): array
    {
        $this->send_($stream, 'EHLO ' . $this->settings->ehloDomain, 'ehlo');

        try {
            $reply = $this->expect($stream, 'ehlo', [250]);
        } catch (SmtpException $e) {
            if ($e->replyCode !== 500 && $e->replyCode !== 502) {
                throw $e;
            }
            $this->send_($stream, 'HELO ' . $this->settings->ehloDomain, 'helo');
            $this->expect($stream, 'helo', [250]);

            return [];
        }

        $caps = [];
        foreach (explode("\n", $reply) as $i => $line) {
            if ($i === 0) {
                continue; // the greeting line, not a capability
            }
            $line = trim(substr(trim($line), 4));
            if ($line === '') {
                continue;
            }
            $parts             = explode(' ', $line, 2);
            $caps[strtoupper($parts[0])] = $parts[1] ?? '';
        }

        return $caps;
    }

    /**
     * AUTH PLAIN when the server offers it (one round trip), AUTH LOGIN
     * otherwise.
     *
     * A server that advertises neither still gets AUTH LOGIN tried at it: some
     * hosted mailboxes only advertise AUTH after a successful STARTTLS and some
     * do not advertise it at all, and a clear 5xx from the server is a better
     * diagnosis than this client deciding in advance that it cannot work.
     *
     * @param resource            $stream
     * @param array<string,string> $caps
     */
    private function authenticate(mixed $stream, array $caps): void
    {
        $offered = strtoupper($caps['AUTH'] ?? '');

        if (str_contains($offered, 'PLAIN')) {
            // RFC 4616: NUL authzid NUL authcid NUL password, base64.
            $token = base64_encode("\0" . $this->settings->username . "\0" . $this->settings->password);
            $this->send_($stream, 'AUTH PLAIN ' . $token, 'auth');
            $this->expectAuth($stream);

            return;
        }

        $this->send_($stream, 'AUTH LOGIN', 'auth');
        $this->expect($stream, 'auth', [334]);

        $this->send_($stream, base64_encode($this->settings->username), 'auth');
        $this->expect($stream, 'auth', [334]);

        $this->send_($stream, base64_encode($this->settings->password), 'auth');
        $this->expectAuth($stream);
    }

    /** @param resource $stream */
    private function expectAuth(mixed $stream): void
    {
        try {
            $this->expect($stream, 'auth', [235]);
        } catch (SmtpException $e) {
            // Re-thrown without the server's text: some servers echo the
            // username back in a failed AUTH reply, and this is headed for a log.
            throw SmtpException::auth($e->replyCode);
        }
    }

    /* ---------------------------------------------------------------- wire */

    /** @return resource */
    private function open(): mixed
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer'       => $this->settings->verifyPeer,
            'verify_peer_name'  => $this->settings->verifyPeer,
            'allow_self_signed' => !$this->settings->verifyPeer,
            'SNI_enabled'       => true,
            'peer_name'         => $this->settings->host,
        ]]);

        $errno  = 0;
        $errstr = '';

        $stream = @stream_socket_client(
            $this->settings->target(),
            $errno,
            $errstr,
            (float) $this->settings->timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($stream === false) {
            throw SmtpException::connect($this->settings->host, $this->settings->port, $errstr !== '' ? $errstr : 'error ' . $errno);
        }

        return $stream;
    }

    /**
     * Write one command and its CRLF.
     *
     * Named with a trailing underscore because `send()` is the public entry
     * point and two methods called send would be one too many.
     *
     * @param resource $stream
     */
    private function send_(mixed $stream, string $line, string $stage): void
    {
        // Nothing reaching here should contain a line break - addresses and
        // credentials are validated before construction - but a command split
        // in two is command injection, so it is checked at the wire as well.
        if (preg_match('/[\r\n]/', $line) === 1) {
            throw SmtpException::invalid('An SMTP command may not contain a line break.');
        }

        $this->write($stream, $line . "\r\n", $stage);
    }

    /** @param resource $stream */
    private function write(mixed $stream, string $payload, string $stage): void
    {
        $remaining = strlen($payload);
        $offset    = 0;

        // fwrite on a socket is free to accept less than it was given.
        while ($remaining > 0) {
            $written = @fwrite($stream, substr($payload, $offset), $remaining);

            if ($written === false || $written === 0) {
                if (self::timedOut($stream)) {
                    throw SmtpException::timeout($stage);
                }

                throw SmtpException::write($stage);
            }

            $offset    += $written;
            $remaining -= $written;
        }
    }

    /**
     * Read one reply and insist on one of the codes.
     *
     * A multi-line reply repeats the code on every line with a dash after it;
     * the last line has a space. The whole reply is returned so that EHLO can
     * read the capabilities out of it.
     *
     * @param  resource   $stream
     * @param  list<int>  $codes
     * @throws SmtpException
     */
    private function expect(mixed $stream, string $stage, array $codes): string
    {
        $reply = '';
        $lines = 0;

        while (true) {
            $line = @fgets($stream, 1024);

            if ($line === false) {
                throw self::timedOut($stream)
                    ? SmtpException::timeout($stage)
                    : SmtpException::refused($stage, 0, 'the connection closed without a reply');
            }

            $reply .= $line;

            if (++$lines > self::MAX_REPLY_LINES || strlen($reply) > self::MAX_REPLY_BYTES) {
                throw SmtpException::refused($stage, 0, 'the reply was too long to be real');
            }

            // Continuation lines are "250-TEXT"; the final one is "250 TEXT".
            $trimmed = rtrim($line, "\r\n");
            if (strlen($trimmed) < 4 || $trimmed[3] !== '-') {
                break;
            }
        }

        $code = (int) substr(ltrim($reply), 0, 3);

        if (!in_array($code, $codes, true)) {
            throw SmtpException::refused($stage, $code, $reply);
        }

        return $reply;
    }

    /** @param resource $stream */
    private static function timedOut(mixed $stream): bool
    {
        $meta = stream_get_meta_data($stream);

        return ($meta['timed_out'] ?? false) === true;
    }
}
