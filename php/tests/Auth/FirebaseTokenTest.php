<?php

declare(strict_types=1);

namespace Speedat\Tests\Auth;

use Speedat\Domain\Auth\FirebaseToken;
use Speedat\Domain\Auth\FirebaseTokenError;
use Speedat\Tests\Auth\Support\RsaKeyPair;
use Speedat\Tests\Auth\Support\StubCertificates;
use Speedat\Tests\TestCase;

/**
 * Firebase ID-token verification, against a key pair this test generates and
 * tokens this test signs.
 *
 * The forgeries are the point. A verifier that accepts a good token is easy;
 * what has to be proved is that it refuses the alg-none token, the token for
 * another Firebase project, the one signed by a key it has never heard of, and
 * the one whose payload was edited after signing.
 */
final class FirebaseTokenTest extends TestCase
{
    private const PROJECT = 'speedat-c3b12';
    private const KID     = 'test-key-1';
    private const NOW     = 1_700_000_000;
    private const UID     = 'aBcDeF1234567890';
    private const EMAIL   = 'owner@speedat.pk';

    private RsaKeyPair $keys;

    public function setUp(): void
    {
        if (!RsaKeyPair::isAvailable()) {
            $this->skip('this PHP cannot generate an RSA key (no openssl.cnf found), so tokens cannot be signed here');
        }

        $this->keys = RsaKeyPair::shared();
    }

    // ------------------------------------------------------------- the happy path

    public function testAGoodTokenYieldsTheIdentity(): void
    {
        $identity = $this->verifier()->verify($this->token());

        $this->assertSame(self::UID, $identity->uid);
        $this->assertSame(self::EMAIL, $identity->email);
        $this->assertTrue($identity->emailVerified);
        $this->assertSame(self::EMAIL, $identity->verifiedEmail());
        $this->assertSame('google.com', $identity->signInProvider);
        $this->assertSame(self::NOW - 60, $identity->issuedAt);
        $this->assertSame(self::NOW + 3540, $identity->expiresAt);
        $this->assertSame(self::EMAIL, $this->verifier()->verifiedEmailFrom($this->token()));
    }

    public function testTheAddressIsNormalisedTheWayTheAdminsTableStoresIt(): void
    {
        $identity = $this->verifier()->verify($this->token(['email' => '  Owner@Speedat.PK ']));

        $this->assertSame(self::EMAIL, $identity->verifiedEmail());
    }

    // ------------------------------------------------------------- the forgeries

    public function testAnAlgNoneTokenIsRefusedForItsAlgorithm(): void
    {
        // The classic: drop the signature, claim there is no algorithm, and
        // hope the verifier reads the claims anyway.
        $header  = RsaKeyPair::encodeSegment(['alg' => 'none', 'kid' => self::KID, 'typ' => 'JWT']);
        $payload = RsaKeyPair::encodeSegment($this->claims());

        foreach ([$header . '.' . $payload . '.', $header . '.' . $payload . '.AAAA'] as $forged) {
            $error = $this->assertThrows(FirebaseTokenError::class, fn () => $this->verifier()->verify($forged));
            $this->assertStringContains('not RS256', $error->getMessage());
        }
    }

    public function testAnHmacSignedTokenIsRefusedRatherThanVerifiedWithThePublicKey(): void
    {
        // The other classic: switch to HS256 and sign with the public key,
        // which the verifier happily has a copy of.
        $header  = RsaKeyPair::encodeSegment(['alg' => 'HS256', 'kid' => self::KID, 'typ' => 'JWT']);
        $payload = RsaKeyPair::encodeSegment($this->claims());
        $mac     = hash_hmac('sha256', $header . '.' . $payload, $this->keys->certificatePem, true);

        $error = $this->assertThrows(
            FirebaseTokenError::class,
            fn () => $this->verifier()->verify($header . '.' . $payload . '.' . RsaKeyPair::base64Url($mac)),
        );

        $this->assertStringContains('not RS256', $error->getMessage());
    }

    public function testATamperedPayloadBreaksTheSignature(): void
    {
        [$header, $payload, $signature] = explode('.', $this->token());

        $claims          = $this->claims();
        $claims['email'] = 'attacker@example.com';
        $swapped         = $header . '.' . RsaKeyPair::encodeSegment($claims) . '.' . $signature;

        $error = $this->assertThrows(FirebaseTokenError::class, fn () => $this->verifier()->verify($swapped));
        $this->assertStringContains('signature does not match', $error->getMessage());

        // Bending one character of the signature must fail too. It has to be a
        // character in the middle: the LAST base64url character of a 256-byte
        // signature carries four bits that decode to nothing, so changing it
        // yields the same bytes and - correctly - still verifies.
        $bent = $header . '.' . $payload . '.'
            . substr($signature, 0, 10) . ($signature[10] === 'A' ? 'B' : 'A') . substr($signature, 11);
        $this->assertThrows(FirebaseTokenError::class, fn () => $this->verifier()->verify($bent));
    }

    public function testAKeyIdTheVerifierHasNeverHeardOfIsRefused(): void
    {
        $token = $this->keys->sign(
            ['alg' => 'RS256', 'kid' => 'some-other-key', 'typ' => 'JWT'],
            $this->claims(),
        );

        $error = $this->assertThrows(FirebaseTokenError::class, fn () => $this->verifier()->verify($token));
        $this->assertStringContains('unknown key id', $error->getMessage());

        // A missing kid is refused as well.
        $noKid = $this->keys->sign(['alg' => 'RS256', 'typ' => 'JWT'], $this->claims());
        $this->assertThrows(FirebaseTokenError::class, fn () => $this->verifier()->verify($noKid));
    }

    public function testATokenSignedByAnotherKeyEntirelyIsRefused(): void
    {
        // Same kid, different private key: what a stolen-but-wrong key looks like.
        $other = RsaKeyPair::generate();
        $token = $other->sign(['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT'], $this->claims());

        $error = $this->assertThrows(FirebaseTokenError::class, fn () => $this->verifier()->verify($token));
        $this->assertStringContains('signature does not match', $error->getMessage());
    }

    public function testATokenForAnotherProjectIsRefused(): void
    {
        $wrongAudience = $this->assertThrows(
            FirebaseTokenError::class,
            fn () => $this->verifier()->verify($this->token(['aud' => 'someone-elses-project'])),
        );
        $this->assertStringContains('audience', $wrongAudience->getMessage());

        $wrongIssuer = $this->assertThrows(
            FirebaseTokenError::class,
            fn () => $this->verifier()->verify($this->token(['iss' => 'https://securetoken.google.com/someone-elses-project'])),
        );
        $this->assertStringContains('issuer', $wrongIssuer->getMessage());

        // An issuer that merely starts right is not right.
        $this->assertThrows(
            FirebaseTokenError::class,
            fn () => $this->verifier()->verify($this->token(['iss' => 'https://securetoken.google.com/' . self::PROJECT . '.evil.com'])),
        );
    }

    public function testAnExpiredTokenIsRefusedOnceTheSkewAllowanceIsGone(): void
    {
        $token = $this->token(['exp' => self::NOW - 30]);

        // Thirty seconds past, sixty seconds of allowance: still in.
        $this->assertSame(self::UID, $this->verifier()->verify($token)->uid);

        // Two minutes past: out.
        $error = $this->assertThrows(
            FirebaseTokenError::class,
            fn () => $this->verifier(now: self::NOW + 120)->verify($token),
        );
        $this->assertStringContains('expired', $error->getMessage());

        // And with no allowance configured, thirty seconds is already too late.
        $this->assertThrows(
            FirebaseTokenError::class,
            fn () => $this->verifier(leeway: 0)->verify($token),
        );
    }

    public function testATokenFromTheFutureIsRefused(): void
    {
        $error = $this->assertThrows(
            FirebaseTokenError::class,
            fn () => $this->verifier()->verify($this->token(['iat' => self::NOW + 600])),
        );
        $this->assertStringContains('future', $error->getMessage());

        $this->assertThrows(
            FirebaseTokenError::class,
            fn () => $this->verifier()->verify($this->token(['auth_time' => self::NOW + 600])),
        );
    }

    public function testClaimsThatAreMissingOrTheWrongTypeAreRefused(): void
    {
        // A null value here means "remove the claim entirely".
        $cases = [
            'no subject'       => ['sub' => ''],
            'subject not text' => ['sub' => 12345],
            'subject missing'  => ['sub' => null],
            'no exp'           => ['exp' => null],
            'exp as text'      => ['exp' => '1700000000'],
            'no iat'           => ['iat' => null],
            'email not text'   => ['email' => ['owner@speedat.pk']],
        ];

        foreach ($cases as $why => $override) {
            $claims = $this->claims();

            foreach ($override as $claim => $value) {
                if ($value === null) {
                    unset($claims[$claim]);
                } else {
                    $claims[$claim] = $value;
                }
            }

            $token = $this->keys->sign(['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT'], $claims);
            $this->assertThrows(FirebaseTokenError::class, fn () => $this->verifier()->verify($token), $why);
        }
    }

    public function testAnUnverifiedAddressIsNotAnAddress(): void
    {
        // "true" as a string is what a loose check would let through.
        foreach ([false, 'true', 1, null] as $value) {
            $identity = $this->verifier()->verify($this->token(['email_verified' => $value]));

            $this->assertFalse($identity->emailVerified, var_export($value, true));
            $this->assertNull($identity->verifiedEmail());
        }

        $error = $this->assertThrows(
            FirebaseTokenError::class,
            fn () => $this->verifier()->verifiedEmailFrom($this->token(['email_verified' => false])),
        );

        // The wording the Next.js route returns, kept exactly.
        $this->assertSame('Verify your email address first', $error->publicMessage());
    }

    public function testRubbishThatIsNotAJwtAtAll(): void
    {
        $cases = [
            '',
            '   ',
            'not-a-token',
            'one.two',
            'one.two.three.four',
            'a.b.',
            '!!!.###.$$$',
            str_repeat('a', 9000) . '.b.c',
        ];

        foreach ($cases as $junk) {
            $this->assertThrows(FirebaseTokenError::class, fn () => $this->verifier()->verify($junk), var_export($junk, true));
        }

        // A payload that is valid base64url but not a JSON object.
        $header = RsaKeyPair::encodeSegment(['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT']);
        $this->assertThrows(
            FirebaseTokenError::class,
            fn () => $this->verifier()->verify($header . '.' . RsaKeyPair::base64Url('[1,2,3]') . '.AAAA'),
        );
    }

    public function testAVisitorIsNeverToldWhichCheckFailed(): void
    {
        foreach ([
            $this->token(['aud' => 'someone-elses-project']),
            $this->token(['exp' => self::NOW - 86400]),
            'not-a-token',
        ] as $bad) {
            $error = $this->assertThrows(FirebaseTokenError::class, fn () => $this->verifier()->verify($bad));

            $this->assertSame('Sign-in failed', $error->publicMessage());
            $this->assertStringNotContains($bad, $error->getMessage(), 'the token itself must never reach the log');
        }
    }

    public function testCertificatesAreOnlyFetchedWhenTheTokenGetsThatFar(): void
    {
        $source   = new StubCertificates([self::KID => $this->keys->certificatePem]);
        $verifier = new FirebaseToken(self::PROJECT, $source, 60, static fn (): int => self::NOW);

        // Malformed, so it dies before the key lookup.
        $this->assertThrows(FirebaseTokenError::class, static fn () => $verifier->verify('nonsense'));
        $this->assertSame(0, $source->calls, 'a junk token must not cost a certificate lookup');

        $verifier->verify($this->token());
        $this->assertSame(1, $source->calls);
    }

    public function testGoogleBeingDownIsAFailureAndNotAPass(): void
    {
        $verifier = new FirebaseToken(
            self::PROJECT,
            new StubCertificates([], fail: true),
            60,
            static fn (): int => self::NOW,
        );

        $this->assertThrows(FirebaseTokenError::class, fn () => $verifier->verify($this->token()));
    }

    public function testAMisconfiguredProjectIdIsRefusedAtConstruction(): void
    {
        foreach (['', 'x', 'UPPER-CASE', 'has spaces', 'has_underscores'] as $bad) {
            $error = $this->assertThrows(
                FirebaseTokenError::class,
                static fn () => new FirebaseToken($bad, new StubCertificates([])),
                var_export($bad, true),
            );

            $this->assertSame('Sign-in is not configured', $error->publicMessage());
        }
    }

    // ------------------------------------------------------------------ helpers

    private function verifier(int $leeway = 60, int $now = self::NOW): FirebaseToken
    {
        return new FirebaseToken(
            self::PROJECT,
            new StubCertificates([self::KID => $this->keys->certificatePem]),
            $leeway,
            static fn (): int => $now,
        );
    }

    /** @param array<string,mixed> $overrides */
    private function token(array $overrides = []): string
    {
        return $this->keys->sign(
            ['alg' => 'RS256', 'kid' => self::KID, 'typ' => 'JWT'],
            array_merge($this->claims(), $overrides),
        );
    }

    /**
     * The claims a real Firebase ID token carries, as Google mints them.
     *
     * @return array<string,mixed>
     */
    private function claims(): array
    {
        return [
            'iss'            => 'https://securetoken.google.com/' . self::PROJECT,
            'aud'            => self::PROJECT,
            'auth_time'      => self::NOW - 60,
            'user_id'        => self::UID,
            'sub'            => self::UID,
            'iat'            => self::NOW - 60,
            'exp'            => self::NOW + 3540,
            'email'          => self::EMAIL,
            'email_verified' => true,
            'firebase'       => [
                'identities'       => ['google.com' => ['1234567890'], 'email' => [self::EMAIL]],
                'sign_in_provider' => 'google.com',
            ],
        ];
    }
}
