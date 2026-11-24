<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Domain\Pricing\Format;

/** The logins list: who may sign in, and what each of them may do. */
final class UsersPage
{
    /** @param list<array{email:string,role:string,totp:bool,addedAt:string}> $users */
    public function __construct(
        public readonly string $viewerEmail,
        public readonly array $users = [],
        public readonly bool $googleReady = false,
        public readonly bool $passwordsOn = false,
        public readonly string $error = '',
        public readonly string $toast = '',
    ) {
    }

    /** @return list<array{email:string,role:string,label:string,totp:bool,added:string,removable:bool}> */
    public function rows(): array
    {
        $out = [];
        foreach ($this->users as $user) {
            $out[] = [
                'email'     => $user['email'],
                'role'      => $user['role'],
                'label'     => Shell::roleName($user['role']),
                'totp'      => $user['totp'],
                'added'     => Format::fmtDateTime($user['addedAt']),
                'removable' => $user['role'] === Shell::MARKETER,
            ];
        }

        return $out;
    }
}
