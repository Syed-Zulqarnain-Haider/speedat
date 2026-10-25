<?php

declare(strict_types=1);

namespace Speedat\Vendor\Smtp;

/**
 * One email, from the envelope down to the last encoded byte.
 *
 * The body is quoted-printable, not base64. Both are legal; quoted-printable
 * leaves an English message readable in a raw mailbox and in a spam-filter
 * report, which is worth more here than the few bytes base64 would save on a
 * message that is mostly ASCII anyway. UTF-8 survives it intact because the
 * encoder works on bytes: "-" becomes "=E2=80=94" and a soft line break can
 * never land inside an "=XX" triple.
 *
 * Header values are single-line by construction. A CR or LF anywhere in a
 * recipient, a subject or a display name is refused rather than stripped:
 * header injection is how a "new lead" notification turns into a relay, and a
 * silently repaired header hides the attempt.
 */
final class Message
{
    /** RFC 5322 says 998 octets; quoted-printable keeps us to 76. */
    private const MAX_SUBJECT = 900;

    private function __construct(
        public readonly string $fromAddress,
        public readonly string $fromName,
        /** @var list<string> */
        public readonly array $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html,
        public readonly ?string $replyTo,
        /** Grouping label carried as an X- header; analytics, never routing. */
        public readonly ?string $tag,
        public readonly string $messageId,
        public readonly \DateTimeImmutable $date,
    ) {
    }

    /**
     * @param string|list<string> $to
     */
    public static function create(
        string $fromAddress,
        string $fromName,
        string|array $to,
        string $subject,
        string $text,
        ?string $html = null,
        ?string $replyTo = null,
        ?string $tag = null,
        ?string $messageIdDomain = null,
        ?\DateTimeImmutable $date = null,
    ): self {
        $from = self::address($fromAddress, 'sender');

        $recipients = [];
        foreach (is_array($to) ? $to : [$to] as $one) {
            $recipients[] = self::address((string) $one, 'recipient');
        }
        if ($recipients === []) {
            throw SmtpException::invalid('A message needs at least one recipient.');
        }

        $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? '');
        if ($subject === '') {
            throw SmtpException::invalid('A message needs a subject.');
        }
        if (strlen($subject) > self::MAX_SUBJECT) {
            $subject = substr($subject, 0, self::MAX_SUBJECT);
        }

        $name = trim(str_replace(["\r", "\n"], ' ', $fromName));

        if ($replyTo !== null && $replyTo !== '') {
            $replyTo = self::address($replyTo, 'reply-to');
        } else {
            $replyTo = null;
        }

        if ($tag !== null && preg_match('/^[A-Za-z0-9._-]{1,40}$/', $tag) !== 1) {
            throw SmtpException::invalid('A message tag must be 1-40 characters of A-Z, 0-9, dot, dash or underscore.');
        }

        $domain = $messageIdDomain !== null && $messageIdDomain !== ''
            ? $messageIdDomain
            : substr($from, (int) strrpos($from, '@') + 1);

        if (preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $domain) !== 1) {
            $domain = 'localhost';
        }

        return new self(
            fromAddress: $from,
            fromName: $name,
            to: $recipients,
            subject: $subject,
            text: $text,
            html: $html === '' ? null : $html,
            replyTo: $replyTo,
            tag: $tag,
            messageId: bin2hex(random_bytes(16)) . '@' . $domain,
            date: $date ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /** The bytes after DATA, headers and body, CRLF throughout, no trailing dot. */
    public function data(): string
    {
        $headers = $this->headers();
        $lines   = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . $this->body();
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        $from = $this->fromName !== ''
            ? self::encodeHeaderWord($this->fromName) . ' <' . $this->fromAddress . '>'
            : $this->fromAddress;

        $headers = [
            'Date'         => $this->date->setTimezone(new \DateTimeZone('UTC'))->format('D, d M Y H:i:s +0000'),
            'From'         => $from,
            'To'           => implode(', ', $this->to),
            'Subject'      => self::encodeHeaderWord($this->subject),
            'Message-ID'   => '<' . $this->messageId . '>',
            'MIME-Version' => '1.0',
        ];

        if ($this->replyTo !== null) {
            $headers['Reply-To'] = $this->replyTo;
        }
        if ($this->tag !== null) {
            $headers['X-Speedat-Tag'] = $this->tag;
        }

        // Tells a well-behaved autoresponder not to answer a robot, which is
        // how two mail servers end up talking to each other until Monday.
        $headers['Auto-Submitted'] = 'auto-generated';

        if ($this->html === null) {
            $headers['Content-Type']              = 'text/plain; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'quoted-printable';

            return $headers;
        }

        $headers['Content-Type'] = 'multipart/alternative; boundary="' . $this->boundary() . '"';

        return $headers;
    }

    public function body(): string
    {
        if ($this->html === null) {
            return self::quotedPrintable($this->text);
        }

        $b = $this->boundary();

        return "This is a message in MIME format.\r\n\r\n"
            . '--' . $b . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::quotedPrintable($this->text) . "\r\n"
            . '--' . $b . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . self::quotedPrintable($this->html) . "\r\n"
            . '--' . $b . "--\r\n";
    }

    /**
     * Quoted-printable, RFC 2045. PHP's own encoder is used because it is in
     * ext/standard - always present, no Composer - and it already handles the
     * two details that are easy to get wrong: trailing whitespace before a line
     * break is encoded, and soft line breaks never split an "=XX" triple.
     *
     * Line endings are normalised to CRLF first: a lone "\n" reaching the wire
     * is a bare line feed, which strict servers reject outright.
     */
    public static function quotedPrintable(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = str_replace("\n", "\r\n", $s);

        return quoted_printable_encode($s);
    }

    /**
     * RFC 2047 for a header that is not plain printable ASCII - a customer's
     * name, usually. Encoded in whole, in one word: the subjects here are short
     * and a 75-character-per-word split buys nothing but edge cases.
     */
    public static function encodeHeaderWord(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** Validate one address and refuse anything that could carry a header. */
    private static function address(string $raw, string $what): string
    {
        $value = trim($raw);

        if (preg_match('/[\r\n\x00]/', $value) === 1) {
            throw SmtpException::invalid('A ' . $what . ' address may not contain a line break.');
        }
        if ($value === '' || strlen($value) > 254 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw SmtpException::invalid('That is not a usable ' . $what . ' address.');
        }

        return $value;
    }

    /** Derived from the message id so the same message always serialises the same. */
    private function boundary(): string
    {
        return 'sp-' . substr($this->messageId, 0, 24);
    }
}
