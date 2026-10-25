<?php

declare(strict_types=1);

namespace Speedat\Tests\Auth;

use Speedat\Domain\Auth\AuthError;
use Speedat\Domain\Auth\PasswordSetupStatus;
use Speedat\Domain\Auth\PasswordSetupToken;
use Speedat\Tests\TestCase;

/**
 * The "set your password" link: sixty minutes, one use, and nothing in the
 * database that can be replayed as a link.
 */
final class PasswordSetupTokenTest extends TestCase
{
    private const NOW = 1_700_000_000;

    public function testWhatIsIssuedAndWhatIsStored(): void
    {
        $token = PasswordSetupToken::issue(now: self::NOW);

        $this->assertSame(64, strlen($token->plain), '32 random bytes as hex');
        $this->assertSame(1, preg_match('/^[0-9a-f]{64}$/', $token->plain));
        $this->assertSame(self::NOW + 3600, $token->expiresAt, 'sixty minutes');

        // The stored value is the hash, and it is not the token.
        $this->assertSame(PasswordSetupToken::hashOf($token->plain), $token->hash);
        $this->assertNotSame($token->plain, $token->hash);
        $this->assertStringNotContains($token->plain, $token->hash);

        // Two links are two links.
        $this->assertNotSame($token->plain, PasswordSetupToken::issue(now: self::NOW)->plain);
    }

    public function testAFreshLinkIsAccepted(): void
    {
        $token = PasswordSetupToken::issue(now: self::NOW);

        $this->assertSame(
            PasswordSetupStatus::Valid,
            PasswordSetupToken::check($token->plain, $token->hash, $token->expiresAt, null, self::NOW + 60),
        );

        PasswordSetupToken::assertUsable($token->plain, $token->hash, $token->expiresAt, null, self::NOW + 60);
        $this->assertTrue(true, 'assertUsable() returned without throwing');
    }

    public function testItExpiresAtSixtyMinutesExactly(): void
    {
        $token = PasswordSetupToken::issue(now: self::NOW);

        $this->assertSame(
            PasswordSetupStatus::Valid,
            PasswordSetupToken::check($token->plain, $token->hash, $token->expiresAt, null, self::NOW + 3599),
        );

        $this->assertSame(
            PasswordSetupStatus::Expired,
            PasswordSetupToken::check($token->plain, $token->hash, $token->expiresAt, null, self::NOW + 3600),
            'the second it expires, it is expired',
        );

        $this->assertSame(
            PasswordSetupStatus::Expired,
            PasswordSetupToken::check($token->plain, $token->hash, $token->expiresAt, null, self::NOW + 86400),
        );
    }

    public function testItIsSingleUse(): void
    {
        $token = PasswordSetupToken::issue(now: self::NOW);

        $this->assertSame(
            PasswordSetupStatus::AlreadyUsed,
            PasswordSetupToken::check($token->plain, $token->hash, $token->expiresAt, self::NOW + 10, self::NOW + 20),
            'a spent link is spent even though it has not expired',
        );
    }

    public function testAnotherTokenOrNoRowAtAllIsRefused(): void
    {
        $token = PasswordSetupToken::issue(now: self::NOW);
        $other = PasswordSetupToken::issue(now: self::NOW);

        $this->assertSame(
            PasswordSetupStatus::Unknown,
            PasswordSetupToken::check($other->plain, $token->hash, $token->expiresAt, null, self::NOW),
        );

        $this->assertSame(
            PasswordSetupStatus::Unknown,
            PasswordSetupToken::check($token->plain, null, $token->expiresAt, null, self::NOW),
            'no matching row',
        );

        $this->assertSame(
            PasswordSetupStatus::Unknown,
            PasswordSetupToken::check($token->plain, '', $token->expiresAt, null, self::NOW),
        );
    }

    public function testJunkInTheUrlNeverReachesTheComparison(): void
    {
        $token = PasswordSetupToken::issue(now: self::NOW);

        foreach (['', 'nope', str_repeat('z', 64), strtoupper($token->plain), $token->plain . 'a', substr($token->plain, 1)] as $junk) {
            $this->assertSame(
                PasswordSetupStatus::Malformed,
                PasswordSetupToken::check($junk, $token->hash, $token->expiresAt, null, self::NOW),
                var_export($junk, true),
            );
        }
    }

    public function testEveryRefusalReadsTheSameToTheVisitorAndDiffersInTheLog(): void
    {
        $token  = PasswordSetupToken::issue(now: self::NOW);
        $seen   = [];

        $failures = [
            'expired' => [$token->plain, $token->hash, $token->expiresAt, null, self::NOW + 7200],
            'used'    => [$token->plain, $token->hash, $token->expiresAt, self::NOW + 1, self::NOW + 2],
            'unknown' => [$token->plain, PasswordSetupToken::issue(now: self::NOW)->hash, $token->expiresAt, null, self::NOW],
            'junk'    => ['not-a-token', $token->hash, $token->expiresAt, null, self::NOW],
        ];

        foreach ($failures as $why => $arguments) {
            $error = $this->assertThrows(
                AuthError::class,
                static fn () => PasswordSetupToken::assertUsable(...$arguments),
                $why,
            );

            $this->assertSame(PasswordSetupToken::REFUSAL, $error->publicMessage(), $why);
            $seen[] = $error->getMessage();
        }

        $this->assertSame(4, count(array_unique($seen)), 'the log must say which of the four it was');
    }

    public function testALinkThatWouldDieBeforeItArrivesIsRefused(): void
    {
        $this->assertThrows(AuthError::class, static fn () => PasswordSetupToken::issue(30));
    }
}
