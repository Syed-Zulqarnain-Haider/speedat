<?php

declare(strict_types=1);

namespace Speedat\Tests\Auth;

use Speedat\Domain\Auth\AuthError;
use Speedat\Domain\Auth\SecretBox;
use Speedat\Tests\TestCase;

/**
 * The TOTP seed at rest: it comes back out, it does not come back out changed,
 * it belongs to exactly one admin, and a secret written by the Next.js app still
 * opens.
 */
final class SecretBoxTest extends TestCase
{
    private const OWNER = 'owner@speedat.pk';

    private const OTHER = 'editor@speedat.pk';

    /**
     * Produced by Node, with the same scheme src/lib/auth/totp.ts uses:
     *
     *   key = sha256("totp-test-key"), aes-256-gcm,
     *   iv  = 000102030405060708090a0b, plaintext "JBSWY3DPEHPK3PXP"
     *
     * If the PHP cannot open this, a cut-over would silently invalidate the
     * owner's authenticator - which is exactly the bug this pins.
     */
    private const FROM_NODE = 'AAECAwQFBgcICQoL.AerNFCduwHZqJMMZeJWbbw==.eJf1H6x6hpj6Zx/oW5qmNQ==';

    public function testASecretEncryptedByTheTypeScriptStillOpens(): void
    {
        $box = new SecretBox('totp-test-key');

        $this->assertSame('JBSWY3DPEHPK3PXP', $box->decrypt(self::FROM_NODE));
    }

    /**
     * The v1 format, on purpose. `encrypt()`'s owner argument is required, and
     * the '' here is this file saying "pin the shape the TypeScript writes" - not
     * a caller forgetting to bind a row, which is what the required argument
     * makes impossible.
     */
    public function testARoundTripAndTheStoredShape(): void
    {
        $box    = new SecretBox('an application key that is not in this file in production');
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $stored = $box->encrypt($secret, '');

        $this->assertSame($secret, $box->decrypt($stored));

        $parts = explode('.', $stored);
        $this->assertSame(3, count($parts), 'iv.ciphertext.tag');
        $this->assertSame(12, strlen((string) base64_decode($parts[0], true)), '96-bit iv');
        $this->assertSame(16, strlen((string) base64_decode($parts[2], true)), '128-bit tag');

        // A fresh iv every time, so two admins with the same secret do not
        // produce the same column value.
        $this->assertNotSame($stored, $box->encrypt($secret, ''));
    }

    public function testATamperedValueIsRefusedRatherThanDecryptedToRubbish(): void
    {
        $box    = new SecretBox('key one');
        $stored = $box->encrypt('JBSWY3DPEHPK3PXP', '');
        [$iv, $cipherText, $tag] = explode('.', $stored);

        $flipped = base64_decode($cipherText, true);
        $this->assertNotSame(false, $flipped);
        /** @var string $flipped */
        $flipped[0] = chr(ord($flipped[0]) ^ 0x01);

        $this->assertThrows(
            AuthError::class,
            static fn () => $box->decrypt($iv . '.' . base64_encode($flipped) . '.' . $tag),
            'GCM must catch a flipped bit',
        );

        foreach (['', 'not.three.parts.at.all', 'one.two', 'AAAA.BBBB.CCCC', $iv . '.' . $cipherText] as $malformed) {
            $this->assertThrows(AuthError::class, static fn () => $box->decrypt($malformed), var_export($malformed, true));
        }
    }

    public function testTheWrongKeyDoesNotOpenIt(): void
    {
        $stored = (new SecretBox('key one'))->encrypt('JBSWY3DPEHPK3PXP', '');

        $this->assertThrows(AuthError::class, static fn () => (new SecretBox('key two'))->decrypt($stored));
    }

    public function testKeyRotation(): void
    {
        $old       = new SecretBox('the old application key');
        $written   = $old->encrypt('JBSWY3DPEHPK3PXP', '');
        $rotated   = new SecretBox('the new application key', ['the old application key']);
        $newSecret = $rotated->encrypt('JBSWY3DPEHPK3PXP', '');

        // Step 1 of a rotation: everything still opens.
        $this->assertSame('JBSWY3DPEHPK3PXP', $rotated->decrypt($written));
        $this->assertSame('JBSWY3DPEHPK3PXP', $rotated->decrypt($newSecret));

        // Step 2: which rows still need re-encrypting.
        $this->assertFalse($rotated->encryptedWithCurrentKey($written));
        $this->assertTrue($rotated->encryptedWithCurrentKey($newSecret));

        // Step 3: with the old key dropped, only the re-encrypted row opens.
        $final = new SecretBox('the new application key');
        $this->assertSame('JBSWY3DPEHPK3PXP', $final->decrypt($newSecret));
        $this->assertThrows(AuthError::class, static fn () => $final->decrypt($written));
    }

    public function testAnEmptyKeyIsRefusedOutright(): void
    {
        $this->assertThrows(AuthError::class, static fn () => new SecretBox(''));
        $this->assertThrows(AuthError::class, static fn () => new SecretBox('   '));
    }

    /* ===================================================================== */
    /*  BOUND TO THE ROW  (audit round 1, finding 22)                        */
    /* ===================================================================== */

    /**
     * THE ATTACK. Copy the owner's `totp_secret_enc` into another admin's row and
     * that admin's second factor becomes the owner's phone. It needs SQL write
     * access, so it is depth rather than a hole - but the depth costs one
     * argument, and without it the column is a portable credential.
     */
    public function testASecretMovedToAnotherAdminsRowDoesNotOpen(): void
    {
        $box    = new SecretBox('an application key');
        $stored = $box->encrypt('JBSWY3DPEHPK3PXP', self::OWNER);

        $this->assertSame('JBSWY3DPEHPK3PXP', $box->decrypt($stored, self::OWNER), 'its own row still opens it');

        $this->assertThrows(
            AuthError::class,
            static fn () => $box->decrypt($stored, self::OTHER),
            "the owner's seed must not open in the editor's row",
        );

        // And not by dropping the owner either: the value says it is bound, so a
        // caller that passes nothing is told so rather than left reading the
        // failure as a corrupt column or a wrong key.
        $error = $this->assertThrows(AuthError::class, static fn () => $box->decrypt($stored));
        $this->assertStringContains('bound', $error->getMessage());
    }

    /** The stored shape: a "v2." marker, then the same iv.ciphertext.tag as before. */
    public function testTheBoundShapeIsTheOldShapeWithAMarker(): void
    {
        $box    = new SecretBox('an application key');
        $stored = $box->encrypt('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', self::OWNER);

        $parts = explode('.', $stored);
        $this->assertSame(4, count($parts), 'v2.iv.ciphertext.tag');
        $this->assertSame('v2', $parts[0]);
        $this->assertSame(12, strlen((string) base64_decode($parts[1], true)), '96-bit iv');
        $this->assertSame(16, strlen((string) base64_decode($parts[3], true)), '128-bit tag');

        // It has to fit admins.totp_secret_enc, VARCHAR(255): a 32-character
        // seed is 89 characters bound, so no migration is needed to store one.
        $this->assertTrue(strlen($stored) <= 255, 'bound length ' . strlen($stored) . ' must fit VARCHAR(255)');

        $this->assertTrue($box->isBoundToOwner($stored));
        $this->assertFalse($box->isBoundToOwner($box->encrypt('JBSWY3DPEHPK3PXP', '')), 'no owner, no binding');
        $this->assertFalse($box->isBoundToOwner(self::FROM_NODE), 'a Next.js row is unbound - it needs re-encrypting');
    }

    /**
     * Rows enrolled before the binding still open, with or without an owner
     * argument. This is the requirement that outranks the finding: the owner's
     * authenticator already holds those seeds, and refusing them would lock him
     * out of his own admin on cut-over night.
     */
    public function testAnUnboundRowStillOpensEitherWay(): void
    {
        $node = new SecretBox('totp-test-key');

        $this->assertSame('JBSWY3DPEHPK3PXP', $node->decrypt(self::FROM_NODE), 'read without an owner');
        $this->assertSame(
            'JBSWY3DPEHPK3PXP',
            $node->decrypt(self::FROM_NODE, self::OWNER),
            'and read by the row it sits in, which has nothing bound to check',
        );

        // The upgrade path, which needs no schema change: read it, write it back
        // bound. isBoundToOwner() is how a pass over the table finds the rows
        // that still need it.
        $box     = new SecretBox('totp-test-key');
        $rebound = $box->encrypt($box->decrypt(self::FROM_NODE), self::OWNER);

        $this->assertTrue($box->isBoundToOwner($rebound));
        $this->assertSame('JBSWY3DPEHPK3PXP', $box->decrypt($rebound, self::OWNER));
        $this->assertThrows(AuthError::class, static fn () => $box->decrypt($rebound, self::OTHER));
    }

    /**
     * `admins` is PRIMARY KEY (email) on a utf8mb4_unicode_ci column, so
     * "Owner@Speedat.pk" and "owner@speedat.pk" are the same row to MySQL. The
     * binding has to agree with that, or a caller that read the row with one
     * spelling would be told the secret had been tampered with - a 2FA lockout
     * caused by a capital letter.
     */
    public function testTheSameRowSpelledDifferentlyStillOpensIt(): void
    {
        $box    = new SecretBox('an application key');
        $stored = $box->encrypt('JBSWY3DPEHPK3PXP', 'Owner@Speedat.PK');

        foreach (['owner@speedat.pk', 'OWNER@SPEEDAT.PK', '  Owner@Speedat.pk  '] as $spelling) {
            $this->assertSame('JBSWY3DPEHPK3PXP', $box->decrypt($stored, $spelling), $spelling);
        }

        // A DIFFERENT row is still a different row.
        $this->assertThrows(AuthError::class, static fn () => $box->decrypt($stored, 'owner@speedat.com'));
    }

    /** A bound row rotates like any other, and the owner is part of the check. */
    public function testRotationOfABoundRow(): void
    {
        $old     = new SecretBox('the old application key');
        $written = $old->encrypt('JBSWY3DPEHPK3PXP', self::OWNER);
        $rotated = new SecretBox('the new application key', ['the old application key']);

        $this->assertSame('JBSWY3DPEHPK3PXP', $rotated->decrypt($written, self::OWNER));
        $this->assertFalse($rotated->encryptedWithCurrentKey($written, self::OWNER), 'still on the retired key');

        $reWritten = $rotated->encrypt('JBSWY3DPEHPK3PXP', self::OWNER);
        $this->assertTrue($rotated->encryptedWithCurrentKey($reWritten, self::OWNER));

        // The wrong owner is not "a row that needs re-encrypting", but saying so
        // in the same false is right: neither can be read with the current key as
        // it stands, and a rotation pass must not silently skip it.
        $this->assertFalse($rotated->encryptedWithCurrentKey($reWritten, self::OTHER));
        $this->assertFalse($rotated->encryptedWithCurrentKey($reWritten), 'no owner, no answer');
    }

    /** A bound value is tamper-evident in its marker as well as its tag. */
    public function testAMalformedBoundValueIsRefused(): void
    {
        $box    = new SecretBox('an application key');
        $stored = $box->encrypt('JBSWY3DPEHPK3PXP', self::OWNER);

        foreach (['v2.', 'v2.one.two', 'v2.' . self::FROM_NODE . '.extra', 'v3.' . substr($stored, 3)] as $malformed) {
            $this->assertThrows(
                AuthError::class,
                static fn () => $box->decrypt($malformed, self::OWNER),
                var_export($malformed, true),
            );
        }
    }
}
