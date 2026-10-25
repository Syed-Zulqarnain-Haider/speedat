<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

use Closure;
use SensitiveParameter;

/**
 * Time-based one-time passwords, RFC 6238 - the port of src/lib/auth/totp.ts
 * (which leans on the `otpauth` package; this leans on hash_hmac).
 *
 * The defaults are the ones every authenticator app assumes and the ones the
 * TypeScript sets explicitly: SHA-1, six digits, a thirty-second step, and a
 * window of one step either side so a code typed as the minute turns over is
 * still accepted.
 *
 * SHA-1 is not a mistake here. HMAC-SHA1 is not broken by the SHA-1 collision
 * work, and Google Authenticator, Authy and 1Password all read SHA-1 only when
 * the URI omits the algorithm - shipping SHA-256 would lock the owner out of
 * his own phone.
 *
 * THE REPLAY GUARD IS THE PART THAT IS EASY TO LEAVE OUT. A six-digit code is
 * valid for up to ninety seconds; anyone who reads it over the owner's shoulder
 * (or off a phishing page) can use it again inside that window. So the caller
 * stores the step that was accepted, hands it back on the next verify, and this
 * class refuses that step and everything before it. Same rule as the
 * TypeScript's `totpLastStep` column, and the same column in the MySQL schema.
 *
 * This class holds no state and touches no database: it takes the last step and
 * returns the new one. Persisting it is the repository's job in phase 3, and it
 * must be written in the same statement that checks it (UPDATE ... WHERE
 * totp_last_step IS NULL OR totp_last_step < :step) or two simultaneous
 * requests can both spend the same code.
 */
final class Totp
{
    /** What the enrolment QR and the authenticator app will call this account. */
    public const ISSUER = 'Speedat Admin';

    /** 20 bytes = 160 bits, the size RFC 4226 recommends and `otpauth` defaults to. */
    public const SECRET_BYTES = 20;

    public function __construct(
        private readonly int $digits = 6,
        private readonly int $period = 30,
        private readonly string $algorithm = 'sha1',
        private readonly string $issuer = self::ISSUER,
        private readonly ?Closure $clock = null,
    ) {
        if ($digits < 6 || $digits > 10) {
            throw new AuthError('TOTP digits must be between 6 and 10');
        }

        if ($period < 1) {
            throw new AuthError('TOTP period must be at least one second');
        }

        // THREE ALGORITHMS, NOT EVERY ONE hash_hmac() KNOWS (audit round 1,
        // finding 23). RFC 4226's dynamic truncation reads four bytes from an
        // offset of up to 15, so it touches byte 18 of the digest. That is safe
        // for sha1 (20 bytes) and for anything longer, and out of bounds for a
        // 16-byte one - and `md5` was accepted here. In PHP 8 that read is a
        // warning, which Core\Bootstrap's error handler turns into an exception,
        // so the symptom is a 500 on the second factor rather than a wrong code,
        // about one secret in sixteen.
        //
        // These three are also all any authenticator app speaks, so the narrower
        // list costs nothing at all.
        $readableByAnAuthenticator = ['sha1', 'sha256', 'sha512'];

        if (!in_array($algorithm, $readableByAnAuthenticator, true)) {
            throw new AuthError(
                'TOTP algorithm ' . $algorithm . ' is not one an authenticator app reads (sha1, sha256 or sha512)'
            );
        }

        // Still checked against the host: a PHP build missing one of the three
        // should fail here, loudly, and not at somebody's first sign-in.
        if (!in_array($algorithm, hash_hmac_algos(), true)) {
            throw new AuthError('TOTP algorithm ' . $algorithm . ' is not available');
        }
    }

    /** A fresh, unenrolled secret, base32 as the apps expect it. */
    public function newSecret(int $bytes = self::SECRET_BYTES): string
    {
        return Base32::encode(random_bytes(max(10, $bytes)));
    }

    /** The time step a moment falls in. */
    public function stepAt(?int $unixTime = null): int
    {
        return intdiv($unixTime ?? $this->now(), $this->period);
    }

    /** The code for a given step. */
    public function codeForStep(#[SensitiveParameter] string $secretBase32, int $step): string
    {
        $key = Base32::decode($secretBase32);

        if ($key === '') {
            throw new AuthError('TOTP secret is empty');
        }

        // The counter is eight bytes, big-endian. pack('J') needs 64-bit PHP,
        // which every supported host is, but build it by hand anyway so this
        // cannot become the one line that fails on an odd shared server.
        $counter = '';

        for ($i = 7; $i >= 0; $i--) {
            $counter .= chr(($step >> ($i * 8)) & 0xFF);
        }

        $hmac = hash_hmac($this->algorithm, $counter, $key, true);

        // RFC 4226 dynamic truncation: the low nibble of the last byte picks
        // where to read four bytes from, and the top bit is dropped so the
        // result is positive on every platform.
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;

        $binary = ((ord($hmac[$offset]) & 0x7F) << 24)
            | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
            | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
            | (ord($hmac[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** $this->digits)), $this->digits, '0', STR_PAD_LEFT);
    }

    /** The code showing on the phone right now. */
    public function codeAt(#[SensitiveParameter] string $secretBase32, ?int $unixTime = null): string
    {
        return $this->codeForStep($secretBase32, $this->stepAt($unixTime));
    }

    /**
     * Check a code, with the replay guard.
     *
     * @param string|null $typed            what the person typed; spaces are forgiven
     * @param int|null    $lastAcceptedStep the step this account last spent, or null
     * @param int         $window           steps either side of now to accept
     *
     * @return int|null the accepted step - store it - or null if the code is no good
     */
    public function verify(
        #[SensitiveParameter] string $secretBase32,
        ?string $typed,
        ?int $lastAcceptedStep = null,
        int $window = 1,
        ?int $unixTime = null,
    ): ?int {
        $code = preg_replace('/\s+/', '', (string) $typed) ?? '';

        if (preg_match('/^\d{' . $this->digits . '}$/', $code) !== 1) {
            return null;
        }

        $current = $this->stepAt($unixTime);

        // Oldest first, so a code that matches two steps (it cannot, but a
        // shortened digit count could) resolves to the earlier one and the
        // replay guard stays monotonic.
        for ($delta = -$window; $delta <= $window; $delta++) {
            $step = $current + $delta;

            if ($step < 0) {
                continue;
            }

            // hash_equals, not ===: the comparison is over a value an attacker
            // supplies and a value derived from the secret.
            if (!hash_equals($this->codeForStep($secretBase32, $step), $code)) {
                continue;
            }

            if ($lastAcceptedStep !== null && $step <= $lastAcceptedStep) {
                return null; // Already spent. Same rule as totp.ts.
            }

            return $step;
        }

        return null;
    }

    /**
     * The otpauth:// URI an authenticator app scans.
     *
     * The shape (issuer in the label AND as a parameter, then secret,
     * algorithm, digits, period) is what the `otpauth` package produced for the
     * Next.js app, so a QR from either build enrols identically.
     */
    public function uri(#[SensitiveParameter] string $secretBase32, string $label): string
    {
        $label = trim($label);

        if ($label === '') {
            throw new AuthError('A TOTP URI needs a label (the admin\'s email address)');
        }

        return 'otpauth://totp/'
            . rawurlencode($this->issuer) . ':' . rawurlencode($label)
            . '?issuer=' . rawurlencode($this->issuer)
            . '&secret=' . rawurlencode($secretBase32)
            . '&algorithm=' . strtoupper($this->algorithm)
            . '&digits=' . $this->digits
            . '&period=' . $this->period;
    }

    /** The key in groups of four, for the "cannot scan?" fold in TotpSetup.tsx. */
    public static function readable(#[SensitiveParameter] string $secretBase32): string
    {
        return trim(chunk_split($secretBase32, 4, ' '));
    }

    private function now(): int
    {
        $clock = $this->clock;

        return $clock === null ? time() : (int) $clock();
    }
}
