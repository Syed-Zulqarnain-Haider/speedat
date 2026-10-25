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
 * There are exactly two roles, because the database says so: `admins.role` is
 * `ENUM('owner','editor')`, so an unknown role is refused by MySQL rather than
 * discovered by a permission check later. Anything that is not the string
 * "owner" is read as an editor - the failure direction that withholds a
 * publish rather than granting one.
 */
final class AdminUser
{
    public const OWNER  = 'owner';
    public const EDITOR = 'editor';

    public function __construct(
        public readonly string $email,
        public readonly string $name,
        /** self::OWNER or self::EDITOR. */
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

    /** The name to show, falling back to the address when the row has none. */
    public function label(): string
    {
        return $this->name !== '' ? $this->name : $this->email;
    }
}
