<?php

declare(strict_types=1);

namespace Speedat\View\Admin;

/**
 * The sign-in screen. Port of app/admin/login/page.tsx and LoginForm.tsx -
 * the same two ways in (Google, or email and password), the same second step,
 * the same words.
 *
 * The React form held the Firebase token in memory between the two steps.
 * This one cannot and must not: the first step's result is kept server-side
 * against the session and the browser gets nothing but a cookie, so a token
 * good enough to sign in never sits in a page the owner might leave open.
 */
final class LoginPage
{
    public const STEP_CREDENTIALS = 'credentials';
    public const STEP_CODE        = 'code';

    public function __construct(
        public readonly string $companyName = 'Speedat International Courier',
        public readonly string $tagline = 'Speed Against Time',
        /** credentials | code */
        public readonly string $step = self::STEP_CREDENTIALS,
        /** Refusals are worded the same whichever half failed, so nothing enumerates accounts. */
        public readonly string $error = '',
        /** The email the owner typed, so the second attempt does not start empty. */
        public readonly string $email = '',
        /** False when Google sign-in is not configured on this deployment. */
        public readonly bool $googleReady = true,
        /** A warning about the deployment itself, shown in place of the form. */
        public readonly string $configWarning = '',
    ) {
    }

    public function onCodeStep(): bool
    {
        return $this->step === self::STEP_CODE;
    }

    /** The one sentence above the code box. */
    public function codePrompt(): string
    {
        return 'Enter the 6-digit code from your authenticator app.';
    }

    public function lede(): string
    {
        return 'Sign in with the account that was added as an admin.';
    }

    /** The form is replaced by the warning when the deployment cannot sign anyone in. */
    public function blocked(): bool
    {
        return $this->configWarning !== '';
    }
}
