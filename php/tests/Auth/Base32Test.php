<?php

declare(strict_types=1);

namespace Speedat\Tests\Auth;

use Speedat\Domain\Auth\AuthError;
use Speedat\Domain\Auth\Base32;
use Speedat\Tests\TestCase;

/**
 * Base32 against the RFC 4648 section 10 vectors, and against the way a person
 * actually types a secret off a screen.
 */
final class Base32Test extends TestCase
{
    /** @var array<string,string> plain => base32, from RFC 4648 (padding removed) */
    private const RFC4648 = [
        ''       => '',
        'f'      => 'MY',
        'fo'     => 'MZXQ',
        'foo'    => 'MZXW6',
        'foob'   => 'MZXW6YQ',
        'fooba'  => 'MZXW6YTB',
        'foobar' => 'MZXW6YTBOI',
    ];

    public function testTheRfc4648Vectors(): void
    {
        foreach (self::RFC4648 as $plain => $encoded) {
            $this->assertSame($encoded, Base32::encode($plain), 'encode("' . $plain . '")');
            $this->assertSame($plain, Base32::decode($encoded), 'decode("' . $encoded . '")');
        }
    }

    public function testThePaddedFormIsStillAccepted(): void
    {
        // The RFC's own padded spellings - what other tools hand over.
        $this->assertSame('f', Base32::decode('MY======'));
        $this->assertSame('foobar', Base32::decode('MZXW6YTBOI======'));
    }

    public function testEveryByteSurvivesARoundTrip(): void
    {
        $all = '';

        for ($i = 0; $i < 256; $i++) {
            $all .= chr($i);
        }

        $this->assertSame($all, Base32::decode(Base32::encode($all)));

        // And random lengths, because the awkward cases are the ones where the
        // bit count is not a multiple of five.
        for ($length = 1; $length <= 40; $length++) {
            $bytes = random_bytes($length);
            $this->assertSame($bytes, Base32::decode(Base32::encode($bytes)), 'length ' . $length);
        }
    }

    public function testItForgivesHowAPersonTypesASecret(): void
    {
        $secret = Base32::encode(random_bytes(20));
        $raw    = Base32::decode($secret);

        $this->assertSame($raw, Base32::decode(strtolower($secret)));
        $this->assertSame($raw, Base32::decode(trim(chunk_split($secret, 4, ' '))));
        $this->assertSame($raw, Base32::decode(trim(chunk_split($secret, 4, '-'), '-')));
        $this->assertSame($raw, Base32::decode("  " . $secret . "\n"));
    }

    public function testACharacterBase32CannotMeanIsRefused(): void
    {
        // 0, 1 and 8 are not in the alphabet on purpose - they are the
        // characters people confuse with O, I and B.
        foreach (['JBSWY3DP0HPK3PXP', 'JBSWY3DP1HPK3PXP', 'JBSWY3DP8HPK3PXP', 'not base32!'] as $bad) {
            $error = $this->assertThrows(AuthError::class, static fn () => Base32::decode($bad), $bad);
            $this->assertSame('That key is not valid', $error->publicMessage());
        }

        $this->assertFalse(Base32::looksLikeSecret('JBSWY3DP0HPK3PXP'));
        $this->assertFalse(Base32::looksLikeSecret('MZXW6'), 'five bytes is too short for a TOTP secret');
        $this->assertTrue(Base32::looksLikeSecret(Base32::encode(random_bytes(20))));
    }
}
