<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

use Speedat\Core\Config;
use Speedat\Core\Str;

/**
 * What the BROWSER half of Google sign-in needs: the four Firebase web values,
 * and the Content-Security-Policy sources the Firebase JS SDK loads from.
 *
 * ONE PLACE FOR BOTH, ON PURPOSE (2026-10-26). The values used to be read in
 * LoginController and the CSP sources typed by hand into security.csp_* from
 * docs/firebase-setup.md. The docs listed only https://www.gstatic.com for
 * scripts, but signInWithPopup also loads https://apis.google.com/js/api.js,
 * so a deployment that followed the docs exactly got a popup that never
 * finished, with nothing on the page and one "Refused to load the script" line
 * in a console the owner never opens. And because csp_* went on EVERY
 * response, the public site trusted Google's script CDN for no reason at all.
 *
 * Now the login page asks for these sources (Response::allowFirebaseAuth()),
 * Core\App adds them to that response only, and only when all four values are
 * set and the auth domain is a hostname. Nothing to copy, nothing to forget,
 * and every other page keeps `script-src 'self' 'nonce-...'`.
 *
 * NONE OF THE FOUR VALUES IS A SECRET - see LoginController::firebaseConfigJson().
 */
final class FirebaseBrowser
{
    /**
     * The SDK modules themselves (gstatic), and the gapi loader the popup
     * flow pulls in (apis.google.com/js/api.js) - the one the docs missed.
     */
    public const SCRIPT_SOURCES = ['https://www.gstatic.com', 'https://apis.google.com'];

    /**
     * Where the SDK posts: the sign-in exchange (identitytoolkit), the token
     * refresh (securetoken), and www.googleapis.com, which older SDK paths
     * still call for the same API.
     */
    public const CONNECT_SOURCES = [
        'https://identitytoolkit.googleapis.com',
        'https://securetoken.googleapis.com',
        'https://www.googleapis.com',
    ];

    /** Framed by the popup flow beside the project's own auth domain. */
    public const GOOGLE_FRAME = 'https://accounts.google.com';

    /**
     * The four web values, trimmed - or null when any one is missing.
     *
     * ALL FOUR OR NOTHING, and the auth domain must be a hostname: it becomes
     * a frame-src source in the policy, and a value with a quote, a semicolon
     * or a path in it is either a typo the popup will not survive or an
     * attempt to write a directive of its own. Both are treated as missing.
     * The two ways people actually paste it - with "https://" in front, or a
     * "/" behind, as the Firebase console shows the URL - are tidied rather
     * than refused, because the SDK wants the bare host either way.
     *
     * @return array{apiKey:string,authDomain:string,projectId:string,appId:string}|null
     */
    public static function config(): ?array
    {
        $config = [
            'apiKey'     => Str::squish(Config::str('auth.firebase_api_key')),
            'authDomain' => self::authDomain(Config::str('auth.firebase_auth_domain')),
            'projectId'  => Str::squish(Config::str('auth.firebase_project_id')),
            'appId'      => Str::squish(Config::str('auth.firebase_app_id')),
        ];

        foreach ($config as $value) {
            if ($value === '') {
                return null;
            }
        }

        return $config;
    }

    /**
     * A bare lower-case hostname, or '' when the value is not one.
     *
     * "speedat-c3b12.firebaseapp.com" and a custom auth domain such as
     * "auth.speedat.com.pk" pass; "localhost", an IP literal, a port, a path,
     * a quote and whitespace inside do not. At least one dot, because Firebase
     * has never issued a single-label auth domain.
     */
    public static function authDomain(?string $raw): string
    {
        $host = strtolower(Str::squish($raw));

        if (str_starts_with($host, 'https://')) {
            $host = substr($host, 8);
        }

        $host = rtrim($host, '/');

        if ($host === '' || strlen($host) > 253) {
            return '';
        }

        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

        if (preg_match('/^' . $label . '(?:\.' . $label . ')+$/', $host) !== 1) {
            return '';
        }

        // The last label is a TLD, and no TLD is all digits - which is what
        // keeps "10.0.0.1" from passing as a hostname.
        if (preg_match('/\.[0-9]+$/', $host) === 1) {
            return '';
        }

        return $host;
    }

    /**
     * The sources the login page's policy gains, by directive - or [] when
     * Google sign-in is not fully configured, so that page keeps the plain
     * 'self' policy every other page has.
     *
     * @return array<string,list<string>> directive name => source expressions
     */
    public static function cspSources(): array
    {
        $config = self::config();

        if ($config === null) {
            return [];
        }

        return [
            'script-src'  => self::SCRIPT_SOURCES,
            'connect-src' => self::CONNECT_SOURCES,
            'frame-src'   => ['https://' . $config['authDomain'], self::GOOGLE_FRAME],
        ];
    }
}
