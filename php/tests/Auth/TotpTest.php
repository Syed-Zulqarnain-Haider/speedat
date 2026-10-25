<?php

declare(strict_types=1);

namespace Speedat\Tests\Auth;

use Speedat\Domain\Auth\AuthError;
use Speedat\Domain\Auth\Base32;
use Speedat\Domain\Auth\Totp;
use Speedat\Tests\TestCase;

/**
 * TOTP against the published RFC 6238 vectors, and against the two behaviours
 * the TypeScript added on top of them: a one-step window either side, and a
 * code that cannot be spent twice.
 */
final class TotpTest extends TestCase
{
    /** The RFC 6238 appendix B seed for SHA-1: "12345678901234567890". */
    private const RFC_SEED = '12345678901234567890';

    /** @var array<int,string> unix time => the 8-digit code, from RFC 6238 appendix B */
    private const RFC_VECTORS = [
        59          => '94287082',
        1111111109  => '07081804',
        1111111111  => '14050471',
        1234567890  => '89005924',
        2000000000  => '69279037',
        20000000000 => '65353130',
    ];

    public function testTheRfc6238Vectors(): void
    {
        $secret = Base32::encode(self::RFC_SEED);

        // The base32 of that seed is itself a published value; if this line
        // ever fails, the vectors below are being fed the wrong key.
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);

        $totp = new Totp(digits: 8);

        foreach (self::RFC_VECTORS as $time => $expected) {
            $this->assertSame($expected, $totp->codeAt($secret, $time), 'T = ' . $time);
        }
    }

    public function testTheSixDigitCodeIsTheLastSixOfTheEightDigitOne(): void
    {
        // Truncation to six digits is a modulo, so the six-digit code is the
        // tail of the eight-digit one. A cheap cross-check that the digit count
        // is applied at the right point.
        $secret = Base32::encode(self::RFC_SEED);
        $six    = new Totp();
        $eight  = new Totp(digits: 8);

        foreach (array_keys(self::RFC_VECTORS) as $time) {
            $this->assertSame(substr($eight->codeAt($secret, $time), -6), $six->codeAt($secret, $time), 'T = ' . $time);
        }
    }

    public function testACodeIsAcceptedOneStepEitherSideAndNoFurther(): void
    {
        $totp   = new Totp();
        $secret = $totp->newSecret();
        $now    = 1_700_000_000;
        $step   = $totp->stepAt($now);

        $this->assertSame($step, $totp->verify($secret, $totp->codeForStep($secret, $step), null, 1, $now));
        $this->assertSame($step - 1, $totp->verify($secret, $totp->codeForStep($secret, $step - 1), null, 1, $now));
        $this->assertSame($step + 1, $totp->verify($secret, $totp->codeForStep($secret, $step + 1), null, 1, $now));

        $this->assertNull($totp->verify($secret, $totp->codeForStep($secret, $step - 2), null, 1, $now));
        $this->assertNull($totp->verify($secret, $totp->codeForStep($secret, $step + 2), null, 1, $now));
    }

    public function testACodeCannotBeSpentTwice(): void
    {
        $totp   = new Totp();
        $secret = $totp->newSecret();
        $now    = 1_700_000_000;
        $step   = $totp->stepAt($now);
        $code   = $totp->codeForStep($secret, $step);

        $accepted = $totp->verify($secret, $code, null, 1, $now);
        $this->assertSame($step, $accepted);

        // The same code again, with the step the caller stored: refused.
        $this->assertNull($totp->verify($secret, $code, $accepted, 1, $now), 'a replayed code must be refused');

        // And so is the previous step's code, which is still inside the window
        // but is older than what has already been spent.
        $this->assertNull($totp->verify($secret, $totp->codeForStep($secret, $step - 1), $accepted, 1, $now));

        // The next step's code is newer, so it is still good.
        $this->assertSame($step + 1, $totp->verify($secret, $totp->codeForStep($secret, $step + 1), $accepted, 1, $now));
    }

    public function testTheShapeOfWhatWasTypedIsCheckedBeforeTheSecret(): void
    {
        $totp   = new Totp();
        $secret = $totp->newSecret();
        $now    = 1_700_000_000;
        $code   = $totp->codeAt($secret, $now);

        // Spaces are forgiven - people read codes in two groups of three.
        $this->assertNotNull($totp->verify($secret, substr($code, 0, 3) . ' ' . substr($code, 3), null, 1, $now));

        foreach ([null, '', '12345', '1234567', 'abcdef', '12345a', '  ', '000000 000000'] as $rubbish) {
            $this->assertNull($totp->verify($secret, $rubbish, null, 1, $now), var_export($rubbish, true));
        }
    }

    public function testAWrongCodeIsRefusedAndAnEmptySecretIsAnError(): void
    {
        $totp   = new Totp();
        $secret = $totp->newSecret();
        $now    = 1_700_000_000;
        $wrong  = str_pad((string) ((int) $totp->codeAt($secret, $now) + 1 % 1000000), 6, '0', STR_PAD_LEFT);

        $this->assertNull($totp->verify($secret, $wrong, null, 1, $now));
        $this->assertThrows(AuthError::class, static fn () => $totp->codeAt('', $now));
    }

    public function testASecretIsTwentyRandomBytes(): void
    {
        $totp   = new Totp();
        $secret = $totp->newSecret();

        $this->assertSame(32, strlen($secret), '20 bytes of base32 is 32 characters');
        $this->assertSame(20, strlen(Base32::decode($secret)));
        $this->assertNotSame($secret, $totp->newSecret(), 'two secrets must not be the same');
    }

    public function testTheUriIsTheOneTheTypeScriptProduced(): void
    {
        $totp   = new Totp();
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

        $this->assertSame(
            'otpauth://totp/Speedat%20Admin:owner%40speedat.pk'
                . '?issuer=Speedat%20Admin'
                . '&secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'
                . '&algorithm=SHA1&digits=6&period=30',
            $totp->uri($secret, 'owner@speedat.pk'),
        );

        $this->assertThrows(AuthError::class, static fn () => $totp->uri($secret, '  '));
    }

    public function testTheHandTypedFormIsGroupsOfFour(): void
    {
        $this->assertSame(
            'JBSW Y3DP EHPK 3PXP',
            Totp::readable('JBSWY3DPEHPK3PXP'),
        );
    }

    public function testConstructorArgumentsAreChecked(): void
    {
        $this->assertThrows(AuthError::class, static fn () => new Totp(digits: 4));
        $this->assertThrows(AuthError::class, static fn () => new Totp(digits: 12));
        $this->assertThrows(AuthError::class, static fn () => new Totp(period: 0));
        $this->assertThrows(AuthError::class, static fn () => new Totp(algorithm: 'md5-but-not-really'));
    }

    /**
     * A SHORT DIGEST IS REFUSED AT CONSTRUCTION (audit round 1, finding 23).
     *
     * RFC 4226's dynamic truncation reads four bytes from an offset of up to 15,
     * so it touches byte 18 of the digest. Fine for sha1 (20 bytes) and anything
     * longer; out of bounds for md5's 16 - and md5 is in hash_hmac_algos(), which
     * was the whole guard. The read is a warning in PHP 8, which Bootstrap's error
     * handler turns into an exception, so the symptom was a 500 on the second
     * factor for roughly one secret in sixteen.
     */
    public function testAnAlgorithmWithTooShortADigestIsRefusedRatherThanReadingPastTheHmac(): void
    {
        foreach (['md5', 'ripemd128', 'crc32b', 'md4'] as $tooShort) {
            if (!in_array($tooShort, hash_hmac_algos(), true)) {
                continue;   // not built into this PHP; nothing to refuse
            }

            $this->assertThrows(
                AuthError::class,
                static fn () => new Totp(algorithm: $tooShort),
                $tooShort . ' has a digest RFC 4226 truncation would read past'
            );
        }

        // The three an authenticator app actually reads are all still accepted,
        // and all produce a code of the right shape.
        foreach (['sha1', 'sha256', 'sha512'] as $supported) {
            $totp = new Totp(algorithm: $supported);
            $code = $totp->codeAt('JBSWY3DPEHPK3PXP', 59);

            $this->assertSame(6, strlen($code), $supported . ' produces a six-digit code');
            $this->assertSame(1, preg_match('/^\d{6}$/', $code), $supported . ': ' . $code);
        }
    }
}
