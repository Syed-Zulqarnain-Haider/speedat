<?php

declare(strict_types=1);

namespace Speedat\Http\Admin;

/**
 * What `admin/setup` prints: one screen, one decision, no state.
 *
 * WHY THIS IS IN `Http\Admin` AND NOT IN `View\Admin` beside `LoginPage`. It
 * belongs there and should move there the day somebody is touching both. It is
 * here because `app/View/**` is another track's tree in this change and a page
 * that cannot ship until two trees agree is a page nobody can review. Nothing
 * about it is different from its neighbours: it is a readonly bag of already-
 * decided strings, and the template does no work.
 *
 * NOTHING SECRET IS ON IT. The setup token is never read into this object and
 * never printed - the operator types it in from `config/config.php`, and a page
 * that echoed it back would put the one secret guarding this door into the
 * browser cache of whoever loaded the page.
 */
final class SetupPage
{
    public const METHOD_PASSWORD = 'password';
    public const METHOD_GOOGLE   = 'google';
    public const METHOD_NONE     = 'none';

    /**
     * @param string $method   one of the METHOD_* constants: which sign-in this
     *                         deployment is configured for, which is what
     *                         decides whether the form asks for a password
     * @param string $error    the one sentence a refused attempt prints, or ""
     * @param string $email    what was typed, echoed back so a refusal does not
     *                         empty the form
     * @param string $minChars the password floor, as the policy spells it
     */
    public function __construct(
        public readonly string $companyName,
        public readonly string $tagline,
        public readonly string $method,
        public readonly string $error = '',
        public readonly string $email = '',
        public readonly string $name = '',
        public readonly int $minChars = 12,
    ) {
    }

    public function wantsPassword(): bool
    {
        return $this->method === self::METHOD_PASSWORD;
    }

    /** No sign-in method is switched on, so there is nothing worth creating yet. */
    public function blocked(): bool
    {
        return $this->method === self::METHOD_NONE;
    }

    /**
     * The sentence at the top, which is the whole instruction.
     *
     * It names the file and the key, because the person reading it is standing
     * in hPanel's File Manager with no shell and no way to ask anybody.
     */
    public function lede(): string
    {
        if ($this->blocked()) {
            return 'This deployment has no way to sign in yet. Open config/config.php and either set '
                . 'auth.firebase_project_id to your Firebase project, or set auth.allow_password_login to true. '
                . 'Then reload this page.';
        }

        if ($this->wantsPassword()) {
            return 'Nobody can sign in to this site yet. Create the first owner here, once. '
                . 'The set-up key is the auth.setup_token line in config/config.php.';
        }

        return 'Nobody can sign in to this site yet. Create the first owner here, once. This deployment signs in '
            . 'with Google, so the address below must be the Google account that will own the site - it is created '
            . 'without a password. The set-up key is the auth.setup_token line in config/config.php.';
    }
}
