<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

use SensitiveParameter;

/**
 * What counts as an acceptable password, and why each rule is here.
 *
 * The rules are deliberately few. A policy that demands an upper-case letter, a
 * digit and a symbol produces "Speedat@1" - twelve characters of nothing -
 * while a length floor produces a passphrase. So: TWELVE CHARACTERS, not the
 * account's own address, and not one of the passwords every credential-stuffing
 * list already holds. Nothing about character classes, nothing about expiry.
 *
 * This account is the owner's and there are perhaps three of them; the threat
 * is an online guess against a rate-limited form and a leaked-password reuse,
 * not an offline crack of a stolen database (argon2id covers that).
 *
 * The weak list is small and embedded on purpose: no Composer, no download, no
 * 15 MB file on a shared host. It is the passwords that actually top the breach
 * lists plus the ones a Lahore courier's admin would obviously attract.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /** Not a security limit - a "you pasted your whole SSH key" limit. */
    public const MAX_LENGTH = 128;

    /** The longest straight alphabet/number-row run a password may contain. */
    private const LONGEST_ALLOWED_RUN = 7;

    /**
     * Passwords refused outright. Compared lower-cased and with trailing digits
     * and punctuation stripped, so "Password123!" is caught by "password".
     *
     * @var list<string>
     */
    private const WEAK = [
        'password', 'passw0rd', 'p@ssword', 'p@ssw0rd', 'letmein', 'welcome',
        'qwerty', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm', 'iloveyou', 'admin',
        'administrator', 'root', 'toor', 'login', 'default', 'changeme',
        'secret', 'monkey', 'dragon', 'sunshine', 'princess', 'football',
        'baseball', 'superman', 'trustno', 'whatever', 'starwars', 'master',
        'freedom', 'shadow', 'michael', 'jennifer', 'jordan', 'hunter',
        // The ones this particular admin would attract.
        'speedat', 'speedatadmin', 'speedatcourier', 'speedatinternational',
        'courier', 'lahore', 'pakistan', 'karachi', 'islamabad', 'delivery',
        'cargo', 'shipping', 'tracking', 'logistics',
    ];

    /**
     * Everything wrong with this password, in the order a person should fix it.
     * An empty list means it is acceptable.
     *
     * @param string $email the account the password is for; it must not be the password
     *
     * @return list<string> messages safe to show the person choosing the password
     */
    public static function problems(#[SensitiveParameter] string $password, string $email = ''): array
    {
        $problems = [];
        $length   = mb_strlen($password, 'UTF-8');

        if ($length < self::MIN_LENGTH) {
            $problems[] = 'Use at least ' . self::MIN_LENGTH . ' characters.';
        }

        if ($length > self::MAX_LENGTH) {
            $problems[] = 'Keep it under ' . self::MAX_LENGTH . ' characters.';
        }

        // A host without argon2id hashes with bcrypt, which throws away
        // everything past the 72nd byte. Say so rather than truncate.
        $hostLimit = Password::maxLength();

        if (strlen($password) > $hostLimit) {
            $problems[] = 'This server can only take a password of up to ' . $hostLimit . ' characters.';
        }

        if (trim($password) === '') {
            $problems[] = 'A password of only spaces is not a password.';
        }

        if (trim($password) !== $password) {
            $problems[] = 'Do not start or end the password with a space - it is too easy to lose.';
        }

        $lower = mb_strtolower(trim($password), 'UTF-8');
        $mail  = mb_strtolower(trim($email), 'UTF-8');

        if ($mail !== '') {
            $local = (string) strstr($mail, '@', true);
            $local = $local !== '' ? $local : $mail;

            if ($lower === $mail || $lower === $local) {
                $problems[] = 'Your password cannot be your email address.';
            } elseif (mb_strlen($local, 'UTF-8') >= 5 && str_contains($lower, $local)) {
                $problems[] = 'Your password cannot contain your email address.';
            }
        }

        if (self::isWeak($lower)) {
            $problems[] = 'That password is on every list attackers try first. Pick something else.';
        }

        if (self::isOneCharacterRepeated($lower)) {
            $problems[] = 'One character repeated is not a password.';
        }

        if (self::isARun($lower)) {
            $problems[] = 'A straight run of the keyboard or the alphabet is guessed in seconds.';
        }

        return $problems;
    }

    /** @throws AuthError with the first problem as the visible message */
    public static function enforce(#[SensitiveParameter] string $password, string $email = ''): void
    {
        $problems = self::problems($password, $email);

        if ($problems !== []) {
            throw new AuthError('Password rejected by policy: ' . count($problems) . ' problem(s)', $problems[0]);
        }
    }

    private static function isWeak(string $lower): bool
    {
        // "Password123!" -> "password": a weak word padded out with digits to
        // reach twelve characters is still that weak word.
        $stripped = preg_replace('/[^a-z]+/', '', $lower) ?? '';

        foreach (self::WEAK as $weak) {
            if ($lower === $weak || $stripped === $weak) {
                return true;
            }

            // The same word repeated ("adminadminadmin").
            if ($stripped !== '' && strlen($stripped) % strlen($weak) === 0
                && str_repeat($weak, intdiv(strlen($stripped), strlen($weak))) === $stripped) {
                return true;
            }
        }

        return false;
    }

    private static function isOneCharacterRepeated(string $lower): bool
    {
        return $lower !== '' && count(array_unique(mb_str_split($lower, 1, 'UTF-8'))) === 1;
    }

    /**
     * A straight run of the alphabet or the number row, eight characters or
     * more: "abcdefghijkl", "123456789012" (which is "123456789" and then a
     * wrap - so the test is for the longest run inside the password, not for
     * the whole password being one), and the same backwards.
     *
     * Eight, not four, because a real passphrase does contain short runs -
     * "rst" turns up in ordinary words - and a rule that rejects those is a
     * rule that sends people back to "Speedat@1".
     */
    private static function isARun(string $lower): bool
    {
        $characters = mb_str_split($lower, 1, 'UTF-8');
        $ascending  = 1;
        $descending = 1;

        for ($i = 1, $n = count($characters); $i < $n; $i++) {
            $step = mb_ord($characters[$i], 'UTF-8') - mb_ord($characters[$i - 1], 'UTF-8');

            $ascending  = $step === 1 ? $ascending + 1 : 1;
            $descending = $step === -1 ? $descending + 1 : 1;

            if ($ascending > self::LONGEST_ALLOWED_RUN || $descending > self::LONGEST_ALLOWED_RUN) {
                return true;
            }
        }

        return false;
    }
}
