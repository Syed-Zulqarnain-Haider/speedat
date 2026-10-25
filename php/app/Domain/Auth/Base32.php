<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

/**
 * RFC 4648 base32, the alphabet authenticator apps speak.
 *
 * Encoding is unpadded, because that is what `otpauth://` URIs and every
 * "enter the key by hand" box expect. Decoding is forgiving in exactly the ways
 * a human typing a secret off a screen needs - lower case, spaces, hyphens and
 * trailing "=" are all accepted - and strict about everything else, because a
 * silently-dropped character produces a secret that is wrong in a way nobody
 * can debug from a six-digit code that "just does not work".
 */
final class Base32
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $out    = '';
        $buffer = 0;
        $bits   = 0;

        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $buffer = ($buffer << 8) | ord($bytes[$i]);
            $bits  += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $out  .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }

        if ($bits > 0) {
            // Left-over bits are padded with zeroes on the right.
            $out .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $out;
    }

    /**
     * @throws AuthError when the text holds a character base32 cannot mean
     */
    public static function decode(string $text): string
    {
        $clean = strtoupper(str_replace([' ', '-', "\t", "\r", "\n", '='], '', $text));

        if ($clean === '') {
            return '';
        }

        $buffer = 0;
        $bits   = 0;
        $out    = '';

        for ($i = 0, $n = strlen($clean); $i < $n; $i++) {
            $index = strpos(self::ALPHABET, $clean[$i]);

            if ($index === false) {
                throw new AuthError('Secret is not valid base32', 'That key is not valid');
            }

            $buffer = ($buffer << 5) | $index;
            $bits  += 5;

            if ($bits >= 8) {
                $bits -= 8;
                $out  .= chr(($buffer >> $bits) & 0xFF);
            }
        }

        return $out;
    }

    /** True when the text decodes cleanly and is long enough to be a TOTP secret. */
    public static function looksLikeSecret(string $text, int $minBytes = 10): bool
    {
        try {
            return strlen(self::decode($text)) >= $minBytes;
        } catch (AuthError) {
            return false;
        }
    }
}
