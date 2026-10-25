<?php

declare(strict_types=1);

namespace Speedat\Tests\Auth;

use Speedat\Domain\Auth\AuthError;
use Speedat\Domain\Auth\Password;
use Speedat\Domain\Auth\PasswordPolicy;
use Speedat\Tests\TestCase;

/**
 * Hashing, rehashing and the policy. The policy tests are as much about what it
 * ACCEPTS as what it refuses: a rule that rejects a good passphrase is a rule
 * that teaches the owner to pick "Speedat@2026" instead.
 */
final class PasswordTest extends TestCase
{
    private const GOOD = 'nine crates of mangoes';

    public function testAHashVerifiesAndADifferentPasswordDoesNot(): void
    {
        $hash = Password::hash(self::GOOD);

        $this->assertTrue(Password::verify(self::GOOD, $hash));
        $this->assertFalse(Password::verify(self::GOOD . ' ', $hash));
        $this->assertFalse(Password::verify('nine crates of mangos', $hash));
        $this->assertFalse(Password::verify('', $hash));

        // Salted: the same password hashed twice is two different strings.
        $this->assertNotSame($hash, Password::hash(self::GOOD));
        $this->assertTrue(Password::verify(self::GOOD, Password::hash(self::GOOD)));
    }

    public function testTheHashSaysWhichAlgorithmMadeIt(): void
    {
        $hash = Password::hash(self::GOOD);

        if (Password::hasArgon2id()) {
            $this->assertSame('argon2id', Password::algorithm());
            $this->assertStringContains('$argon2id$', $hash);
        } else {
            $this->assertSame('bcrypt', Password::algorithm());
            $this->assertStringContains('$2y$12$', $hash);
        }
    }

    public function testAnAccountWithNoPasswordIsRefusedWithoutAWarning(): void
    {
        $this->assertFalse(Password::verify(self::GOOD, null));
        $this->assertFalse(Password::verify(self::GOOD, ''));

        // And the refusal costs what a real check costs, so the response time
        // does not enumerate which admins have password sign-in switched on.
        $this->assertTrue(Password::timingDecoyIsUsable(), 'the timing decoy must be a real hash');
    }

    public function testAnEmptyPasswordIsNeverHashed(): void
    {
        $this->assertThrows(AuthError::class, static fn () => Password::hash(''));
    }

    public function testAFreshHashDoesNotNeedRehashingButAWeakOldOneDoes(): void
    {
        $this->assertFalse(Password::needsRehash(Password::hash(self::GOOD)));
        $this->assertTrue(Password::needsRehash(''));

        // What an older, cheaper deployment would have left in the column.
        $cheap = (string) password_hash(self::GOOD, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->assertTrue(Password::needsRehash($cheap), 'a cost-4 bcrypt hash must be upgraded on sign-in');

        // And it must still verify, or the upgrade path locks people out.
        $this->assertTrue(Password::verify(self::GOOD, $cheap));
    }

    public function testTheHostsLengthLimitIsStatedRatherThanTruncated(): void
    {
        $limit = Password::maxLength();
        $long  = str_repeat('a', $limit + 1);

        if (Password::hasArgon2id()) {
            // argon2id has no 72-byte problem, so the only limit is the
            // policy's readability cap.
            $this->assertSame(PasswordPolicy::MAX_LENGTH, $limit);
        } else {
            $this->assertSame(Password::BCRYPT_MAX_BYTES, $limit);
            $this->assertThrows(AuthError::class, static fn () => Password::hash($long));
        }

        $problems = PasswordPolicy::problems($long, '');
        $this->assertNotSame([], $problems, 'a password past the host limit must be refused, not truncated');
    }

    public function testAGoodPassphraseIsAccepted(): void
    {
        foreach ([
            'nine crates of mangoes',
            'Lahore-to-Dubai-by-friday',
            'correct horse battery staple',
            'Sp33dat ships parcels daily',
        ] as $good) {
            $this->assertSame([], PasswordPolicy::problems($good, 'owner@speedat.pk'), $good);
        }
    }

    public function testTheRulesThatRefuse(): void
    {
        $cases = [
            'too short'            => ['short1234', ''],
            'empty'                => ['', ''],
            'only spaces'          => ['            ', ''],
            'leading space'        => [' nine crates of mangoes', ''],
            'trailing space'       => ['nine crates of mangoes ', ''],
            'the email itself'     => ['owner@speedat.pk', 'owner@speedat.pk'],
            'contains the name'    => ['xx-ownerman-2026', 'ownerman@speedat.pk'],
            'on every list'        => ['Password123!', ''],
            'on every list, plain' => ['qwertyuiop', ''],
            'the company name'     => ['speedat12345', ''],
            'one character'        => ['aaaaaaaaaaaaaa', ''],
            'a keyboard run'       => ['abcdefghijklm', ''],
            'a digit run'          => ['123456789012', ''],
        ];

        foreach ($cases as $why => [$password, $email]) {
            $this->assertNotSame([], PasswordPolicy::problems($password, $email), $why . ': ' . $password);
        }
    }

    public function testEnforceThrowsWithSomethingThePersonCanAct(): void
    {
        $error = $this->assertThrows(
            AuthError::class,
            static fn () => PasswordPolicy::enforce('short', 'owner@speedat.pk'),
        );

        $this->assertStringContains('at least 12 characters', $error->publicMessage());
        $this->assertStringNotContains('short', $error->publicMessage(), 'never echo the password back');
        $this->assertStringNotContains('short', $error->getMessage(), 'and never log it either');
    }

    public function testEnforceIsSilentWhenThePasswordIsFine(): void
    {
        PasswordPolicy::enforce(self::GOOD, 'owner@speedat.pk');
        $this->assertTrue(true, 'enforce() returned without throwing');
    }
}
