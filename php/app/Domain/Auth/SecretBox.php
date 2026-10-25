<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

use SensitiveParameter;

/**
 * AES-256-GCM for the one secret this app stores and must be able to read back:
 * the TOTP seed. Byte-for-byte the format src/lib/auth/totp.ts writes -
 *
 *     base64(iv) "." base64(ciphertext) "." base64(tag)
 *
 * - with the key derived the same way (sha256 of the configured string), so a
 * secret enrolled in the Next.js app still decrypts after the port. That is not
 * a nicety: the owner's phone already holds that seed, and a format change
 * would mean re-enrolling his authenticator on cut-over night.
 *
 * KEY ROTATION IS AN ARGUMENT, NOT A CONSTANT. Construct it with the current
 * key first and any retired keys after it: everything is encrypted with the
 * first, and decryption tries each in turn. A rotation is therefore
 *
 *   1. deploy with the new key first and the old one second,
 *   2. re-encrypt at rest (read + write each row; encryptedWithCurrentKey()
 *      says which rows still need it),
 *   3. drop the old key from the config.
 *
 * No key is ever written into this file. A hardcoded key is a key in the
 * repository, in the backups and in every developer's clone.
 *
 * ==========================================================================
 * THE CIPHERTEXT IS BOUND TO ITS ROW (audit round 1, finding 22)
 * ==========================================================================
 *
 * GCM authenticates more than the ciphertext: it can also authenticate data
 * that is NOT encrypted - "additional authenticated data" - and a tag that
 * covers the owner is a tag that only verifies in that owner's row. Without it
 * a `totp_secret_enc` value is a self-contained blob: anyone who can write the
 * column can copy the owner's encrypted seed into their own admin row and log
 * in with the owner's authenticator. That needs SQL write access, which is
 * already most of the way to lost, so this is depth rather than a hole - but
 * depth that costs one argument.
 *
 * TWO FORMATS, BOTH READ, ONE WRITTEN:
 *
 *   v1   base64(iv) "." base64(ciphertext) "." base64(tag)
 *        Empty AAD. What src/lib/auth/totp.ts writes and what
 *        bin/export-from-nextjs.php carries across, so it must keep opening
 *        for ever - or cut-over night means re-enrolling every authenticator.
 *
 *   v2   "v2." base64(iv) "." base64(ciphertext) "." base64(tag)
 *        The tag also covers the owner (see fold()). Written whenever an owner
 *        is given, which every caller in this application does.
 *
 * The prefix cannot be mistaken for a v1 value: a v1 blob starts with the 16
 * base64 characters of a 12-byte iv, and "." is not in the base64 alphabet, so
 * no v1 value has a "." in its first three characters.
 *
 * A v1 row is opened WITHOUT an owner check, because there is nothing in it to
 * check against. isBoundToOwner() says which rows are still in that state; the
 * re-encrypt pass is the same read-and-write loop a key rotation uses, and it
 * needs no schema change - a v2 value for a 32-character TOTP seed is 89
 * characters and `admins.totp_secret_enc` is VARCHAR(255).
 */
final class SecretBox
{
    private const CIPHER  = 'aes-256-gcm';
    private const IV_LEN  = 12;  // 96 bits, the size GCM is defined for
    private const TAG_LEN = 16;

    /** Marks a value whose tag also covers its owner. */
    private const BOUND_PREFIX = 'v2.';

    /** @var non-empty-list<string> raw 32-byte keys; [0] encrypts, all of them decrypt */
    private array $keys;

    /**
     * @param string        $currentKey  the application key (any length; it is hashed to 32 bytes)
     * @param list<string>  $retiredKeys older keys still needed to read old rows
     */
    public function __construct(
        #[SensitiveParameter] string $currentKey,
        #[SensitiveParameter] array $retiredKeys = [],
    ) {
        if (trim($currentKey) === '') {
            throw new AuthError('The application key is not set, so secrets cannot be encrypted');
        }

        $keys = [self::derive($currentKey)];

        foreach ($retiredKeys as $old) {
            if (is_string($old) && trim($old) !== '') {
                $keys[] = self::derive($old);
            }
        }

        $this->keys = $keys;
    }

    /**
     * @param string $owner who the secret belongs to - `admins.email`, which is
     *                      that table's PRIMARY KEY, so binding to the owner and
     *                      binding to the row are the same thing here.
     *
     *                      REQUIRED, and deliberately so: a default of '' would
     *                      mean a caller that forgot the argument wrote an
     *                      unbound row and nothing ever said so, which is the
     *                      finding this binding exists to close. Passing ''
     *                      explicitly writes the old v1 format and is for
     *                      something that genuinely has no row - today, only the
     *                      tests that pin that format.
     */
    public function encrypt(#[SensitiveParameter] string $plain, string $owner): string
    {
        $aad = self::fold($owner);
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plain,
            self::CIPHER,
            $this->keys[0],
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            self::TAG_LEN,
        );

        if ($cipherText === false) {
            throw new AuthError('Encrypting the secret failed');
        }

        $value = base64_encode($iv) . '.' . base64_encode($cipherText) . '.' . base64_encode($tag);

        return $aad === '' ? $value : self::BOUND_PREFIX . $value;
    }

    /**
     * @param  string    $owner the row this value was read from; ignored for a v1
     *                          value, which has nothing bound to it
     * @throws AuthError when the stored value is malformed, when no key opens it,
     *                   or when it belongs to a different owner (which, with GCM,
     *                   is the same failure as "it was tampered with")
     */
    public function decrypt(string $stored, string $owner = ''): string
    {
        [$iv, $cipherText, $tag, $aad] = self::parse($stored, $owner);

        foreach ($this->keys as $key) {
            $plain = openssl_decrypt($cipherText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);

            if ($plain !== false) {
                return $plain;
            }
        }

        throw new AuthError('The stored secret could not be decrypted with any configured key');
    }

    /**
     * Was this row written with the key now in use? False means a rotation is
     * half finished and this row still needs re-encrypting.
     */
    public function encryptedWithCurrentKey(string $stored, string $owner = ''): bool
    {
        try {
            [$iv, $cipherText, $tag, $aad] = self::parse($stored, $owner);
        } catch (AuthError) {
            return false;
        }

        return openssl_decrypt($cipherText, self::CIPHER, $this->keys[0], OPENSSL_RAW_DATA, $iv, $tag, $aad) !== false;
    }

    /**
     * Is this value tied to the row it sits in?
     *
     * False means it is a v1 blob - readable, but movable to another admin's row
     * by anyone who can write the column. Re-encrypting it is the fix and needs
     * no migration:
     *
     *     $secret = $box->decrypt($row['totp_secret_enc'], $row['email']);
     *     UPDATE admins SET totp_secret_enc = $box->encrypt($secret, $row['email'])
     *      WHERE email = $row['email'];
     *
     * The same loop is needed when an admin's email changes, because changing the
     * email changes the row's identity and therefore the binding.
     */
    public function isBoundToOwner(string $stored): bool
    {
        return str_starts_with($stored, self::BOUND_PREFIX);
    }

    /**
     * Split a stored value and work out what its tag has to cover.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} iv, ciphertext, tag, aad
     */
    private static function parse(string $stored, string $owner): array
    {
        if (!str_starts_with($stored, self::BOUND_PREFIX)) {
            // A v1 value. The owner is not checked because the value carries
            // nothing to check it against - refusing it would lock every admin
            // enrolled before this change out of their own second factor.
            return [...self::split($stored), ''];
        }

        $aad = self::fold($owner);

        if ($aad === '') {
            // Only a bound value carries the prefix, so its tag covers an owner
            // that is not empty. GCM would refuse this anyway; saying it plainly
            // keeps a caller that forgot the argument from reading the failure as
            // a corrupt column or a wrong key.
            throw new AuthError('This secret is bound to an admin, so the owner must be given to read it');
        }

        return [...self::split(substr($stored, strlen(self::BOUND_PREFIX))), $aad];
    }

    /**
     * The owner, folded the way the database compares it.
     *
     * `admins` is PRIMARY KEY (email) on a utf8mb4_unicode_ci column, so
     * "Owner@Speedat.pk" and "owner@speedat.pk" are the SAME ROW to MySQL. If the
     * tag covered the bytes exactly as the caller spelled them, a caller that
     * wrote the row with one spelling and read it back with another would be told
     * its own secret had been tampered with - a second-factor lockout caused by a
     * capital letter. One row must mean one AAD, so the AAD is the trimmed,
     * lower-cased form.
     */
    private static function fold(string $owner): string
    {
        return mb_strtolower(trim($owner), 'UTF-8');
    }

    /**
     * @return array{0: string, 1: string, 2: string} iv, ciphertext, tag - all raw
     */
    private static function split(string $stored): array
    {
        $parts = explode('.', $stored);

        if (count($parts) !== 3) {
            throw new AuthError('Stored secret is not in the iv.ciphertext.tag form');
        }

        $iv         = base64_decode($parts[0], true);
        $cipherText = base64_decode($parts[1], true);
        $tag        = base64_decode($parts[2], true);

        if ($iv === false || $cipherText === false || $tag === false) {
            throw new AuthError('Stored secret is not valid base64');
        }

        if (strlen($iv) !== self::IV_LEN || strlen($tag) !== self::TAG_LEN || $cipherText === '') {
            throw new AuthError('Stored secret has the wrong iv or tag length');
        }

        return [$iv, $cipherText, $tag];
    }

    /**
     * sha256 of the configured string, exactly as the TypeScript does it. Not a
     * KDF with a work factor, and deliberately so: this has to produce the same
     * 32 bytes as the Node code for the same key, or existing rows stop opening.
     * The key it hashes is high-entropy application configuration, not a human
     * password, so stretching would buy nothing here.
     */
    private static function derive(#[SensitiveParameter] string $key): string
    {
        return hash('sha256', $key, true);
    }
}
