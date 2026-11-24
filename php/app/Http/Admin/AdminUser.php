<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

/**
 * Who is signed in, and what they are allowed to be trusted with. Port of
 * `AdminUser` in src/lib/auth/session.ts.
 *
 * IDENTITY AND AUTHORISATION ARE TWO DIFFERENT QUESTIONS, and this object is
 * the answer to the second. `Core\Session` says which address the browser
 * holds a session for; this exists only when that address is a row in
 * `admins`, so a valid Google account (or a session minted before somebody was
 * removed from the table) gets nothing.
 *
 * There are exactly three roles, because the database says so: `admins.role` is
 * `ENUM('owner','editor','marketer')`, so an unknown role is refused by MySQL
 * rather than discovered by a permission check later.
 *
 *   owner     everything. The screens call this role "Admin".
 *   editor    the day's work - rates draft, inbox, shipments - but no publish.
 *   marketer  the words and search titles on the public pages, and nothing else.
 *
 * Anything that is neither "owner" nor "editor" is read as a MARKETER, the role
 * that can do least. It used to be read as an editor, which was the least then.
 */
final class AdminUser
{
    public const OWNER  = 'owner';
    public const EDITOR = 'editor';
    public const MARKETER = 'marketer';

    /** Who works in the back office: every screen that is not told otherwise. */
    public const STAFF = [self::OWNER, self::EDITOR];

    /** The marketers' area. The back office has screens of its own for everything in it. */
    public const MARKETERS = [self::MARKETER];

    public function __construct(
        public readonly string $email,
        public readonly string $name,
        /** self::OWNER, self::EDITOR or self::MARKETER. */
        public readonly string $role,
        /** Whether this admin has a confirmed second factor. */
        public readonly bool $totpEnabled = false,
    ) {
    }

    /**
     * Publishing, resuming prices and managing admins. Everything else an
     * editor may do.
     */
    public function isOwner(): bool
    {
        return $this->role === self::OWNER;
    }

    /** A role as the table spells it, with anything unknown read as the least there is. */
    public static function role(mixed $stored): string
    {
        return in_array($stored, self::STAFF, true) ? (string) $stored : self::MARKETER;
    }

    public function isMarketer(): bool
    {
        return $this->role === self::MARKETER;
    }

    /** The name to show, falling back to the address when the row has none. */
    public function label(): string
    {
        return $this->name !== '' ? $this->name : $this->email;
    }
}
