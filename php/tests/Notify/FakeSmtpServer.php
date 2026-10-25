<?php

declare(strict_types=1);

namespace Speedat\Tests\Notify;

/**
 * A fake SMTP server, in this process, over a real loopback socket.
 *
 * WHY IT IS BUILT THIS WAY. The client is synchronous: it writes EHLO and then
 * blocks reading the reply. One PHP process cannot both block in the client and
 * answer as the server, and spawning a second process is out of the question -
 * the target host has no shell at all and the tests must run wherever the code
 * does. So the whole server side of the conversation is written into the socket
 * BEFORE the client starts, and what the client wrote is read back and checked
 * afterwards. The socket is a genuine TCP connection on 127.0.0.1, accepted by
 * a listener this class opens, so the client exercises its real read, write and
 * timeout paths - nothing is stubbed. Nothing leaves the machine.
 *
 * The ordering check is what makes the pre-scripting sound: the transcript is
 * asserted in sequence, so a client that asked for things in the wrong order,
 * or skipped a step, fails.
 *
 * The only thing this cannot exercise is real TLS, because there is no
 * certificate. STARTTLS is covered by scripting a refusal, and the authenticated
 * path is exercised with SmtpSettings::SECURE_IMPLICIT, where the client's
 * contract is "the stream handed to me is already private".
 */
final class FakeSmtpServer
{
    /** @var resource|null */
    private mixed $listener = null;

    /** @var resource|null the end the client talks on */
    private mixed $client = null;

    /** @var resource|null the end this class talks on */
    private mixed $server = null;

    public function __construct()
    {
        $errno  = 0;
        $errstr = '';

        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($listener === false) {
            throw new \RuntimeException('Could not open a loopback listener: ' . $errstr);
        }
        $this->listener = $listener;

        $address = stream_socket_get_name($listener, false);
        if ($address === false) {
            throw new \RuntimeException('The loopback listener has no address.');
        }

        // The test's own end of the connection - this is what gets handed to the
        // client under test.
        $client = stream_socket_client('tcp://' . $address, $errno, $errstr, 5);
        if ($client === false) {
            throw new \RuntimeException('Could not connect to the loopback listener: ' . $errstr);
        }
        $this->client = $client;

        $server = stream_socket_accept($listener, 5);
        if ($server === false) {
            throw new \RuntimeException('The loopback listener never accepted the connection.');
        }
        $this->server = $server;

        stream_set_timeout($this->client, 5);
        stream_set_timeout($this->server, 5);
    }

    /** The stream to hand to SmtpClient::converse(). @return resource */
    public function clientStream(): mixed
    {
        if ($this->client === null) {
            throw new \RuntimeException('The fake server is closed.');
        }

        return $this->client;
    }

    /**
     * Queue the server's whole side of the conversation.
     *
     * Each entry is one reply, written with its CRLF. A multi-line reply is
     * given as one string with the dashes already in it, exactly as a real
     * server sends it.
     *
     * @param list<string> $replies
     */
    public function script(array $replies): void
    {
        foreach ($replies as $reply) {
            $this->write($reply . "\r\n");
        }
    }

    /** A greeting, an EHLO answer advertising $auth, and then $rest. @param list<string> $rest */
    public function scriptSession(string $auth, array $rest): void
    {
        $this->script(array_merge([
            '220 smtp.test ESMTP ready',
            "250-smtp.test\r\n250-SIZE 35882577\r\n250-8BITMIME" . ($auth !== '' ? "\r\n250-AUTH " . $auth : '') . "\r\n250 HELP",
        ], $rest));
    }

    /** Everything the client has written so far. */
    public function transcript(): string
    {
        if ($this->server === null) {
            return '';
        }

        // Non-blocking: the client has finished, so whatever is in the buffer is
        // the whole of what it said, and a blocking read would hang forever
        // waiting for a connection nobody is going to write to again.
        stream_set_blocking($this->server, false);

        $out = '';
        while (($chunk = fread($this->server, 8192)) !== false && $chunk !== '') {
            $out .= $chunk;
        }

        return $out;
    }

    /**
     * Assert that the client said these things, in this order.
     *
     * @param  list<string> $expected
     * @return string       the transcript, for any further assertions
     */
    public function assertSaidInOrder(array $expected, \Closure $fail): string
    {
        $transcript = $this->transcript();
        $offset     = 0;

        foreach ($expected as $needle) {
            $at = strpos($transcript, $needle, $offset);
            if ($at === false) {
                $fail('the client never said "' . $needle . '" (after offset ' . $offset . ') in: ' . $transcript);
            }
            $offset = $at + strlen($needle);
        }

        return $transcript;
    }

    public function close(): void
    {
        foreach (['client', 'server', 'listener'] as $name) {
            if ($this->{$name} !== null) {
                @fclose($this->{$name});
                $this->{$name} = null;
            }
        }
    }

    private function write(string $bytes): void
    {
        if ($this->server === null) {
            throw new \RuntimeException('The fake server is closed.');
        }

        if (fwrite($this->server, $bytes) === false) {
            throw new \RuntimeException('Could not write to the fake server socket.');
        }
    }
}
