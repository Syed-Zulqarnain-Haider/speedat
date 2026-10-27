<?php

declare(strict_types=1);

/**
 * Speedat - configuration template.
 *
 * Copy this file to config/config.php and fill it in. config.php is NEVER
 * committed and NEVER placed inside public_html: this directory sits one level
 * above the web root, so even a misconfigured Apache cannot serve it.
 *
 * Returning an array (rather than defining constants or reading a .env file)
 * means the file is plain PHP: a web server that has PHP enabled executes it
 * and can never dump it as text, which is exactly what happens to a .env.
 *
 * On Hostinger:
 *   /home/uXXXXXXXX/domains/<domain>/config/config.php   <- this file, chmod 600
 *   /home/uXXXXXXXX/domains/<domain>/public_html/        <- the contents of php/public
 *
 * Every value is read through Speedat\Core\Config. Anything required and empty
 * stops the application with a message naming the key - never the value.
 */

return [
    'app' => [
        /**
         * 32 random bytes, base64-encoded. THE one secret: it encrypts TOTP
         * secrets at rest and derives the CSRF signing key and the IP-hash
         * salt. Generate with:
         *   php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
         *
         * Rotating it invalidates every stored TOTP secret, every open form
         * and every rate-limit counter. There is no way to recover them.
         */
        'key' => '',

        /** Absolute, no trailing slash, e.g. https://speedat.co */
        'url' => '',

        /** 'production' | 'local'. Anything but 'production' relaxes error output. */
        'env' => 'production',

        /** The zone customers read times in. The database always runs in UTC. */
        'timezone' => 'Asia/Karachi',

        /**
         * Show PHP errors in the browser. Ignored unless env is also not
         * 'production' - two locks, because a config file does get copied from
         * a laptop to a server.
         */
        'debug' => false,

        /**
         * Believe X-Forwarded-For / CF-Connecting-IP.
         *
         * ONLY turn this on when a proxy you control (Cloudflare, a load
         * balancer) is actually in front of the site. Without one the header is
         * attacker-controlled, and rate limiting stops working.
         */
        'trust_proxy' => false,

        /*
         * The proxies whose X-Forwarded-For header may be believed, as IPs or
         * CIDR ranges. trust_proxy does NOTHING without this list: an empty
         * list means believe nobody, which is the safe default. Fill it with
         * the addresses of YOUR proxy only - never a public range you do not
         * control, or anyone can forge a client address and dodge the limits.
         *
         * 'proxy_ips' => ['10.0.0.0/8', '172.16.0.0/12'],
         */
        'proxy_ips' => [],
    ],

    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => '',
        'user'    => '',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    'auth' => [
        /**
         * Firebase project used for admin sign-in. ID tokens are verified
         * locally against Google's published certificates - no Admin SDK, no
         * Composer.
         */
        'firebase_project_id' => '',

        /**
         * The rest of the Firebase web app's config, as the Firebase console
         * prints it (Project settings -> Your apps -> Web app -> SDK setup and
         * configuration). The "Continue with Google" button needs all four;
         * with any one empty it is not drawn. auth_domain is a bare host name
         * such as 'your-project-id.firebaseapp.com' - no https://, no slash.
         * None of these is a secret - Firebase ships them to every browser -
         * but they belong here, beside the project id, not in a template.
         *
         * With all four set, the LOGIN PAGE adds Google's script, API and
         * popup origins to its own Content-Security-Policy. Nothing needs to
         * be typed into security.csp_* for Firebase. Step-by-step set-up, in
         * the safe order: docs/firebase-setup.md.
         */
        'firebase_api_key'     => '',
        'firebase_auth_domain' => '',
        'firebase_app_id'      => '',

        /*
         * The Firebase JS SDK version loaded from gstatic. Leave unset: it
         * defaults to the tested 10.12.2. Must be three numbers if set.
         *
         * 'firebase_sdk_version' => '10.12.2',
         */

        /*
         * One-time key for /admin/setup on a FRESH install whose admins table
         * is empty: 24+ random characters, e.g.
         *   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
         * Remove the line once the first owner exists.
         *
         * 'setup_token' => '',
         */

        /**
         * Allow password sign-in (plus the six-digit code if that admin has
         * two-factor on) alongside Google. Keep it TRUE until a Google sign-in
         * has worked on this site; switch it off last. Setting it back to true
         * is the break-glass when Google sign-in breaks
         * (docs/security.md section 7.7). With it false and Firebase not
         * configured, the login page has no way in at all.
         */
        'allow_password_login' => false,

        /** Absolute session life, in seconds. 5 days, matching the Next.js app. */
        'session_lifetime' => 432000,

        /** Idle timeout, in seconds. 8 hours: a shift, not a week. */
        'session_idle' => 28800,
    ],

    /**
     * Shared secrets for the endpoints no human visits. Each is compared with
     * hash_equals. Generate with:
     *   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
     */
    'tokens' => [
        /** hPanel's cron entry calls bin/cron.php with this. */
        'cron' => '',

        /** Unlocks the detail in /health for uptime monitoring. */
        'metrics' => '',
    ],

    /**
     * Outgoing email.
     *
     * THERE IS NO 'mail' TRANSPORT. PHP's mail() used to be the default here
     * and it is gone on purpose: Hostinger caps it at TEN messages a minute and
     * ONE HUNDRED a rolling day and then blocks it SILENTLY
     * (docs/hostinger-constraints.md section 9). An alert dropped without an
     * error is worse than one that fails loudly - the office's only warning
     * about a problem would be the message that went missing. Setting 'mail'
     * here is logged and downgraded to sending nothing at all.
     *
     * Every message - a new lead, an operational alert, an admin invite - goes
     * through the hand-written SMTP client in app/Vendor/Smtp. No Composer, no
     * PHPMailer, no HTTP API.
     */
    'mail' => [
        /**
         * 'smtp' (production) | 'log' (record the subject and the size in
         * storage/logs and send nothing: local development and tests).
         */
        'transport' => 'smtp',

        /** Envelope sender. Must be an address on this domain or the host will refuse it. */
        'from'      => '',
        'from_name' => 'Speedat International',

        /**
         * Where a new lead and every operational alert is reported. Empty means
         * nothing is sent, and each attempt leaves an audit row saying so.
         */
        'office' => '',

        'smtp' => [
            /** Hostinger: smtp.hostinger.com. Never localhost - that is the capped MTA again. */
            'host'   => '',

            /**
             * 465 with 'ssl' (implicit TLS) or 587 with 'tls' (STARTTLS). Prefer
             * 465: the connection is encrypted from the first byte, so a server
             * that has quietly stopped offering STARTTLS cannot end up carrying
             * the password in the clear.
             */
            'port'   => 465,

            /**
             * 'ssl' (implicit TLS, port 465) | 'tls' (STARTTLS, port 587)
             * | 'none' (no encryption at all; a local test server only -
             * credentials are REFUSED over it rather than sent in the clear).
             */
            'secure' => 'ssl',

            /** The full mailbox address, not just the local part. */
            'user'   => '',
            'pass'   => '',

            /**
             * Seconds to wait for the connection and for every reply; clamped to
             * 1..30. Keep it short. A page must not sit waiting on a dead mail
             * server, and a lead is saved whether or not the office hears about it.
             */
            'timeout' => 10,
        ],
    ],

    /**
     * Rate limits: hits allowed per IP per window. Counters live in MySQL,
     * because shared hosting has no Redis. Raise them rather than remove them.
     */
    'limits' => [
        'quote_per_hour'      => 120,
        'lead_per_hour'       => 10,
        'contact_per_hour'    => 10,
        'login_per_15min'     => 10,
        'admin_write_per_min' => 60,
        'track_per_hour'      => 120,
    ],

    /**
     * Parcel tracking (/track and the hourly cron job). Leave api_key empty
     * and nothing is sent anywhere: /track still shows the statuses the office
     * sets by hand.
     *
     * To switch it on: sign up at https://api.17track.net, open Settings ->
     * Security -> Access key, and paste the key between the quotes below. The
     * key is a password - it lives in this file on the server and nowhere
     * else, never in the repository or a zip.
     */
    'tracking' => [
        'provider' => '17track',
        'api_key'  => '',
        // How many shipments one cron run looks at (one 17TRACK call holds 40).
        'per_run'  => 40,
    ],

    'security' => [
        /**
         * HSTS. Leave OFF until the PHP site IS the live https site: a browser
         * remembers this for a year and will refuse plain http to the domain
         * for that long.
         */
        'hsts' => false,

        /**
         * OPTIONAL extra Content-Security-Policy sources, space separated, for
         * a third-party script or API added in future. NOT needed for Firebase:
         * the login page adds Google's origins itself when all four
         * auth.firebase_* values are set. Empty is the safe default. A
         * 'Refused to load / connect / frame' line in the browser console on
         * /admin/login means a Firebase value is wrong, not that these are
         * empty (docs/firebase-setup.md section 4).
         */
        'csp_script'  => '',
        'csp_connect' => '',
        'csp_frame'   => '',
    ],

    /**
     * Absolute paths OUTSIDE the web root, if the defaults do not suit the
     * host. Empty means ../storage and its subdirectories.
     */
    'paths' => [
        'storage' => '',
        'cache'   => '',
        'logs'    => '',
        'tmp'     => '',
        'uploads' => '',
    ],
];
