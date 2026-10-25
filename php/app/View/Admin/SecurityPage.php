<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

use Speedat\Domain\Pricing\Format;

/**
 * The Security page: this admin's second factor, who else can sign in, what
 * the deployment is missing, and the last forty things anyone did. Port of
 * app/admin/security/page.tsx.
 *
 * The deployment checks are handed in rather than computed here, because
 * "is SMTP configured" is a question for the configuration layer and a view
 * model that reads secrets is a view model that can leak them. THE LIST NO
 * LONGER CONTAINS A SHEETS-WEBHOOK CHECK: nothing arrives by email any more,
 * so a red light for a secret nothing reads would be an alarm with no off
 * switch.
 */
final class SecurityPage
{
    /**
     * @param list<array{email:string,role:string,totp:bool,addedAt:string}> $admins
     * @param list<array{label:string,ok:bool,note:string}>                  $checks
     * @param list<array{at:string,action:string,actor:string,ip:string}>    $activity newest first
     * @param array{uri:string,qrDataUrl:string,secret:string}|null          $enrolment the QR, while enrolling
     */
    public function __construct(
        public readonly string $viewerEmail,
        public readonly bool $totpEnabled,
        public readonly array $admins = [],
        public readonly array $checks = [],
        public readonly array $activity = [],
        public readonly ?array $enrolment = null,
        /** Why the last attempt was refused, "" otherwise. */
        public readonly string $error = '',
        /** A one-off confirmation, e.g. "Two-factor sign-in is on". */
        public readonly string $toast = '',
    ) {
    }

    public function eyebrow(): string
    {
        return "04 - Security";
    }

    public function passing(): int
    {
        $n = 0;
        foreach ($this->checks as $check) {
            if ($check['ok']) {
                $n++;
            }
        }

        return $n;
    }

    /** "all checks pass" or "2 checks need attention" - the pill beside the heading. */
    public function checksPill(): string
    {
        $bad = count($this->checks) - $this->passing();

        if ($bad <= 0) {
            return 'all checks pass';
        }

        return $bad === 1 ? '1 check needs attention' : $bad . ' checks need attention';
    }

    public function checksPass(): bool
    {
        return count($this->checks) > 0 && $this->passing() === count($this->checks);
    }

    /** "3 admins - signed in as boss@speedat.pk". */
    public function adminsMeta(): string
    {
        $n = count($this->admins);

        return $n . ' admin' . ($n === 1 ? '' : 's') . " \u{b7} signed in as " . $this->viewerEmail;
    }

    /** What the state line under the heading says, on or off. */
    public function totpSentence(): string
    {
        return $this->totpEnabled
            ? 'After your Google or password sign-in, a 6-digit code from your authenticator app is required.'
            : 'Your account signs in with Google or a password only.';
    }

    public function enrolling(): bool
    {
        return !$this->totpEnabled && $this->enrolment !== null;
    }

    /** The admins list with the times already formatted. */
    /** @return list<array{email:string,role:string,totp:bool,added:string}> */
    public function adminRows(): array
    {
        $out = [];
        foreach ($this->admins as $admin) {
            $out[] = [
                'email' => $admin['email'],
                'role'  => $admin['role'],
                'totp'  => $admin['totp'],
                'added' => Format::fmtDateTime($admin['addedAt']),
            ];
        }

        return $out;
    }

    /** @return list<array{at:string,action:string,actor:string,ip:string}> */
    public function activityRows(): array
    {
        $out = [];
        foreach ($this->activity as $row) {
            $out[] = [
                'at'     => Format::fmtDateTime($row['at']),
                'action' => $row['action'],
                'actor'  => $row['actor'],
                'ip'     => $row['ip'],
            ];
        }

        return $out;
    }
}
