<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * The two ways in: the admin's, at /admin, and the marketers', at /marketing.
 *
 * Two doors to one building. The sessions, the second factor, the rate limits
 * and the `admins` table are the same; what differs is the address, who is let
 * through, where they land and what the page says. Everything that depends on
 * which door somebody is at asks this object, so a URL is in one place and no
 * controller compares paths.
 *
 * A DOOR IS NOT A PERMISSION. It decides who may start a session HERE; what a
 * session may then do is `Http\Admin\Guard`'s, on every request, whichever door
 * it came in by.
 */
final class Door
{
    /** @param list<string> $roles the roles that sign in here */
    private function __construct(
        public readonly string $key,
        public readonly array $roles,
        public readonly string $home,
        public readonly string $login,
        public readonly string $google,
        public readonly string $code,
        public readonly string $logout,
        public readonly string $security,
        /** "" where there is no password form: marketers have no passwords. */
        public readonly string $password,
        /** "admin" or "marketing": the folder the sign-in template lives in. */
        public readonly string $template,
    ) {
    }

    public static function admin(): self
    {
        return new self(
            'admin',
            ['owner', 'editor'],
            Routes::RATES,
            Routes::LOGIN,
            Routes::LOGIN_GOOGLE,
            Routes::LOGIN_CODE,
            Routes::LOGOUT,
            Routes::SECURITY,
            Routes::LOGIN_PASSWORD,
            'admin/login',
        );
    }

    public static function marketing(): self
    {
        return new self(
            'marketing',
            ['marketer'],
            Routes::MARKETING,
            Routes::MARKETING_LOGIN,
            Routes::MARKETING_LOGIN_GOOGLE,
            Routes::MARKETING_LOGIN_CODE,
            Routes::MARKETING_LOGOUT,
            Routes::MARKETING_SECURITY,
            '',
            'marketing/login',
        );
    }

    public static function forRole(string $role): self
    {
        return $role === 'marketer' ? self::marketing() : self::admin();
    }

    /** The door a request path is behind: /marketing and everything under it, or the admin's. */
    public static function forPath(string $path): self
    {
        return $path === Routes::MARKETING || str_starts_with($path, Routes::MARKETING . '/')
            ? self::marketing()
            : self::admin();
    }

    public function admits(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isMarketing(): bool
    {
        return $this->key === 'marketing';
    }
}
