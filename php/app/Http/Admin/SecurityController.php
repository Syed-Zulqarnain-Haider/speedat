<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

use Speedat\Core\App;
use Speedat\Core\Audit;
use Speedat\Core\Config;
use Speedat\Core\Log;
use Speedat\Core\Response;
use Speedat\Core\Str;
use Speedat\Domain\Auth\AuthError;
use Speedat\Domain\Auth\Password;
use Speedat\Domain\Notify\Notifier;
use Speedat\View\Admin\Routes;
use Speedat\View\Admin\SecurityPage;

/**
 * The Security page: this admin's second factor, who else can sign in, what the
 * deployment is missing, and the last forty things anybody did.
 *
 * ENROLMENT IS A TWO-POST FORM AND THE SECRET LIVES IN THE DATABASE BETWEEN THEM.
 * "Set up" mints a secret, seals it with `SecretBox` and stores it with
 * `totp_enabled` still 0; the QR is drawn from that row for the confirm step.
 * The alternative - a hidden field - would put a working seed in a page, and
 * there is no session-scoped scratch area to use instead. An enrolment that is
 * abandoned therefore leaves a sealed secret that nothing accepts, and pressing
 * "Set up" again replaces it.
 *
 * THREE REFUSALS THIS PAGE OWES THE OWNER:
 *
 *   - it will not enrol over an ENABLED second factor. Overwriting one without
 *     proving you hold the old one is a way to replace somebody's authenticator.
 *   - it will not turn one on until a code from it has been checked, so nobody
 *     can lock themselves out by scanning a QR that did not scan.
 *   - it will not turn one OFF without a current code. "Silently disable" is the
 *     one thing a second factor must never allow, and a stolen session is
 *     exactly the situation where somebody would try.
 *
 * Every one of those three, and every success, rotates the session id: the
 * privileges of the session changed, so the token that carried the old ones is
 * retired (`Core\Session::rotate()`).
 */
final class SecurityController
{
    /** How much of the audit trail the page shows. The React page showed forty. */
    private const ACTIVITY = 40;

    /**
     * Audit actions the scheduled job writes on its own, kept off the activity
     * list. By ACTION, not by actor: `system` also signs notify_failed and
     * notify_skipped, and "the office never got the lead email" is exactly
     * what an admin opens this page to find.
     *
     * @var list<string>
     */
    private const MACHINE_ACTIONS = ['cron_run', 'rates_stale', 'hold_long'];

    /** GET /admin/security */
    public function page(App $app, AdminUser $admin): Response
    {
        $row = Admins::row($admin->email) ?? [];

        $enrolment = null;

        // The QR is only drawn on the request right after "Set up" (?enrol=1).
        // Drawing it on every visit would mean an abandoned enrolment quietly
        // becoming the state of the page for days.
        if (!$admin->totpEnabled && Notice::flag($app->request, 'enrol')) {
            $secret = Admins::secret($row);

            if ($secret !== '') {
                $enrolment = Admins::enrolmentView($admin->email, $secret);
            }
        }

        $page = new SecurityPage(
            viewerEmail: $admin->email,
            totpEnabled: $admin->totpEnabled,
            admins: Admins::all(),
            checks: $this->checks($app, $admin),
            activity: $this->activity(),
            enrolment: $enrolment,
            error: Notice::error($app),
            toast: Notice::toast($app),
        );

        return $app->page('admin/security', [
            'page'  => $page,
            'shell' => Screen::shell($admin, 'security'),
            'theme' => Screen::theme($app),
        ]);
    }

    /** POST /admin/security/totp/begin */
    public function begin(App $app, AdminUser $admin): Response
    {
        if ($admin->totpEnabled) {
            return $this->back($app, ['err' => 'totp_on']);
        }

        try {
            Admins::beginEnrolment($admin->email, Admins::totp()->newSecret());
        } catch (AuthError $e) {
            Log::warn('totp_enrol_refused', ['email' => Str::maskEmail($admin->email), 'reason' => $e->getMessage()]);

            return $this->back($app, ['err' => 'totp_on']);
        }

        Audit::log($admin->email, 'totp_enrol_started', [], $app->request);

        return $this->back($app, ['enrol' => true]);
    }

    /** POST /admin/security/totp/confirm - the code that proves the app scanned it. */
    public function confirm(App $app, AdminUser $admin): Response
    {
        if ($admin->totpEnabled) {
            return $this->back($app, ['err' => 'totp_on']);
        }

        $row = Admins::row($admin->email);

        if ($row === null || Admins::secret($row) === '') {
            return $this->back($app, ['err' => 'noenrol']);
        }

        $step = Admins::checkCode($row, $app->request->post('code', '', 12));

        if ($step === null) {
            // The QR is still on screen for the next attempt: ?enrol=1 stays.
            return $this->back($app, ['err' => 'code', 'enrol' => true]);
        }

        // The step is recorded with the flag in one statement, so the code that
        // switched 2FA on cannot also be used to sign in with.
        Admins::enableTotp($admin->email, $step);
        $app->session()->rotate();

        Audit::log($admin->email, 'totp_enabled', [], $app->request);

        return $this->back($app, ['totpon' => true]);
    }

    /** POST /admin/security/totp/disable - and only with a current code. */
    public function disable(App $app, AdminUser $admin): Response
    {
        if (!$admin->totpEnabled) {
            return $this->back($app, ['err' => 'totp_off']);
        }

        $row = Admins::row($admin->email);

        if ($row === null) {
            return $this->back($app, ['err' => 'totp_off']);
        }

        if (!Admins::verifyCode($row, $app->request->post('code', '', 12))) {
            Audit::log($admin->email, 'totp_disable_refused', [], $app->request);

            return $this->back($app, ['err' => 'code']);
        }

        Admins::disableTotp($admin->email);
        $app->session()->rotate();

        Audit::log($admin->email, 'totp_disabled', [], $app->request);

        return $this->back($app, ['totpoff' => true]);
    }

    /* ------------------------------------------------------------ internals */

    /**
     * What this deployment has and has not got.
     *
     * THERE IS NO SHEETS-WEBHOOK CHECK. Nothing arrives by email any more (the
     * rate-sheet feature was removed from the product on 2026-09-27), so a red
     * light for a secret nothing reads would be an alarm with no off switch.
     *
     * Nothing here prints a secret, a host or a path: each line is a label, a
     * boolean and a note somebody can act on.
     *
     * @return list<array{label:string,ok:bool,note:string}>
     */
    private function checks(App $app, AdminUser $admin): array
    {
        $google    = Str::squish(Config::str('auth.firebase_project_id')) !== '';
        $passwords = Config::bool('auth.allow_password_login', false);
        // The notifier itself rather than $app->mail(): Core\Mail answers the
        // same configured(), but only the notifier can say WHY it is false -
        // and "not configured" over a log transport with both addresses set
        // sends the owner looking at the wrong setting.
        $mail      = Notifier::fromConfig();
        $cron      = Config::str('tokens.cron') !== '';
        $metrics   = Config::str('tokens.metrics') !== '' || $cron;
        $admins    = Admins::count();

        return [
            ...self::signInChecks($google, $passwords),
            [
                'label' => 'Two-factor on your account',
                'ok'    => $admin->totpEnabled,
                'note'  => $admin->totpEnabled ? 'on' : 'off - set it up on this page',
            ],
            [
                'label' => 'Email alerts (SMTP)',
                'ok'    => $mail->configured(),
                'note'  => $mail->configured()
                    ? 'sending through ' . $mail->transportName()
                    : 'not configured - ' . $mail->notConfiguredReason() . ', so alerts are not sent',
            ],
            [
                'label' => 'Cron secret',
                'ok'    => $cron,
                'note'  => $cron ? 'set' : 'not set - the scheduled job and the opcache reset refuse everybody',
            ],
            [
                'label' => 'Metrics token',
                'ok'    => $metrics,
                'note'  => $metrics ? 'set' : 'not set - /api/metrics and the health detail are locked',
            ],
            [
                'label' => 'Admins listed',
                'ok'    => $admins > 0,
                'note'  => $admins === 1 ? '1 account can sign in' : $admins . ' accounts can sign in',
            ],
        ];
    }

    /**
     * The two sign-in lines of the deployment list. Public and static so a test
     * can hold them to the rule below without a database or a config file.
     *
     * PASSWORD SIGN-IN OFF IS NOT A FAILURE WHEN GOOGLE IS THERE. Google-only is
     * the intended setup (`auth.allow_password_login` false is the sample
     * config's default), and the line used to go red for it, so the page never
     * said "all checks pass" on a correctly configured site and the owner
     * learned to ignore the pill (review, 2026-10-26). The line fails only when
     * there is no way in at all.
     *
     * @return list<array{label:string,ok:bool,note:string}>
     */
    public static function signInChecks(bool $google, bool $passwords): array
    {
        if ($passwords) {
            $passwordNote = 'on, hashed with ' . Password::algorithm();
        } elseif ($google) {
            $passwordNote = 'off - Google only (break-glass: docs/security.md 7.7)';
        } else {
            $passwordNote = 'off - and no Firebase project is set, so nobody can sign in';
        }

        return [
            [
                'label' => 'Admin sign-in (Google)',
                'ok'    => $google,
                'note'  => $google ? 'configured' : ($passwords ? 'no Firebase project set - password sign-in only' : 'no Firebase project set'),
            ],
            [
                'label' => 'Password sign-in',
                'ok'    => $passwords || $google,
                'note'  => $passwordNote,
            ],
        ];
    }

    /**
     * The audit trail, newest first.
     *
     * The stored value is an IP HASH, never an address (`Core\Ip`), and only its
     * first six characters are shown: enough to see that two entries came from
     * the same place, which is the only question anybody asks of it.
     *
     * @return list<array{at:string,action:string,actor:string,ip:string}>
     */
    private function activity(): array
    {
        $out = [];

        // People, not machinery. The hourly cron writes a cron_run row every
        // run, and rates_stale / hold_long while the condition lasts; unfiltered,
        // forty rows was under two days of cron, and yesterday's sign-in or a
        // second factor being switched off had scrolled off the panel built to
        // show it (found 2026-10-25). The rows are still in the table.
        foreach (Audit::recent(self::ACTIVITY, 0, self::MACHINE_ACTIONS) as $row) {
            $hash = is_string($row['ip_hash'] ?? null) ? $row['ip_hash'] : '';

            $out[] = [
                'at'     => Iso::of($row['at'] ?? null),
                'action' => (string) ($row['action'] ?? ''),
                'actor'  => (string) ($row['actor'] ?? ''),
                'ip'     => $hash === '' ? '' : substr($hash, 0, 6),
            ];
        }

        return $out;
    }

    /** @param array<string,int|string|bool> $codes */
    private function back(App $app, array $codes): Response
    {
        return Response::redirect($app->request->basePath . Notice::url(Routes::SECURITY, $codes), 303)->noStore();
    }
}
