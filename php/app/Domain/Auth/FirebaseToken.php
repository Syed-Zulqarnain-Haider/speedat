<?php

declare(strict_types=1);

namespace Speedat\Domain\Auth;

use Closure;

/**
 * Verifies a Firebase ID token in plain PHP - the replacement for
 * firebase-admin's `verifyIdToken()` in src/lib/auth/firebase-admin.ts.
 *
 * WHY THERE IS NO SERVICE-ACCOUNT KEY HERE. The Admin SDK needs one because it
 * also MINTS tokens (session cookies, custom tokens). Checking a signature
 * needs the public half only, and Google publishes it. So the whole of
 * FIREBASE_SERVICE_ACCOUNT_B64 disappears in the PHP port: one less secret on a
 * shared host, and one less thing to rotate.
 *
 * What is lost with it: createSessionCookie() and revokeRefreshTokens(). The
 * PHP app does not need them - it keeps its own `sessions` table (see
 * db/migrations/0001_init.sql), so signing out is a DELETE we control, which is
 * in fact stronger than a five-day Firebase cookie.
 *
 * This class verifies ID TOKENS (iss https://securetoken.google.com/<project>,
 * signed with the securetoken certificates). Firebase SESSION COOKIES are
 * signed by a different key set and are deliberately not supported.
 *
 * THE ORDER OF THE CHECKS IS THE POINT: shape, then algorithm, then signature,
 * then claims. Nothing from the payload is believed - not even read out for a
 * log line - until the signature over it has been verified.
 */
final class FirebaseToken
{
    /** A Firebase ID token is ~1 KB; a megabyte of "token" is an attack, not a login. */
    private const MAX_LENGTH = 8192;

    /**
     * @param string       $projectId Firebase project, e.g. "speedat-c3b12"
     * @param Closure|null $clock     fn(): int returning unix seconds; injected by tests
     * @param int          $leeway    clock skew allowed on exp/iat/auth_time, in seconds.
     *                                Shared hosts are not NTP-disciplined; 60 seconds is
     *                                enough for that and far too little to be useful to
     *                                anyone replaying an expired token.
     */
    public function __construct(
        private readonly string $projectId,
        private readonly CertificateSource $certificates,
        private readonly int $leeway = 60,
        private readonly ?Closure $clock = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{3,62}$/', $projectId) !== 1) {
            throw new FirebaseTokenError('Firebase project id is missing or malformed', 'Sign-in is not configured');
        }
    }

    /**
     * @throws FirebaseTokenError when the token is not a currently valid ID token
     *                            for this project
     */
    public function verify(string $idToken): FirebaseIdentity
    {
        $token = trim($idToken);

        if ($token === '' || strlen($token) > self::MAX_LENGTH) {
            throw new FirebaseTokenError('Token is empty or implausibly long');
        }

        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new FirebaseTokenError('Token is not three dot-separated segments');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeSegment($encodedHeader, 'header');

        // ---- algorithm -----------------------------------------------------
        // "none" and a swap to HS256 (where the "signature" is an HMAC with a
        // key the attacker chooses) are the two classic JWT forgeries. Both die
        // here, before any key is looked up.
        $alg = $header['alg'] ?? null;

        if ($alg !== 'RS256') {
            throw new FirebaseTokenError('Token algorithm is ' . (is_string($alg) ? $alg : 'missing') . ', not RS256');
        }

        $typ = $header['typ'] ?? 'JWT';

        if ($typ !== 'JWT') {
            throw new FirebaseTokenError('Token type is not JWT');
        }

        $kid = $header['kid'] ?? null;

        if (!is_string($kid) || $kid === '') {
            throw new FirebaseTokenError('Token header has no key id');
        }

        // ---- signature -----------------------------------------------------
        // Decoded only now: an "alg":"none" token carries an empty signature,
        // and it should be refused for the algorithm it names rather than for
        // the shape of the segment it left behind.
        $signature = self::base64UrlDecode($encodedSignature);

        if ($signature === null || $signature === '') {
            throw new FirebaseTokenError('Signature segment is not valid base64url');
        }

        $keys = $this->certificates->keys();

        if (!isset($keys[$kid])) {
            // Maybe Google rotated after our copy was cached (2026-10-26):
            // ask once more. The source, not this class, decides whether that
            // is worth a request - see CertificateSource::refresh() - so a
            // token with a made-up kid cannot be used to hammer Google.
            $keys = $this->certificates->refresh();
        }

        if (!isset($keys[$kid])) {
            throw new FirebaseTokenError('Token was signed with an unknown key id');
        }

        $publicKey = openssl_pkey_get_public($keys[$kid]);

        if ($publicKey === false) {
            throw new FirebaseTokenError('Signing certificate for key id could not be read');
        }

        $verified = openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );

        if ($verified !== 1) {
            throw new FirebaseTokenError('Token signature does not match');
        }

        // ---- claims (only now is the payload worth reading) ----------------
        $payload = $this->decodeSegment($encodedPayload, 'payload');
        $now     = $this->now();

        $issuer = $payload['iss'] ?? null;

        if ($issuer !== 'https://securetoken.google.com/' . $this->projectId) {
            throw new FirebaseTokenError('Token issuer is not this Firebase project');
        }

        $audience = $payload['aud'] ?? null;

        if ($audience !== $this->projectId) {
            throw new FirebaseTokenError('Token audience is not this Firebase project');
        }

        $expiresAt = self::timestamp($payload['exp'] ?? null);

        if ($expiresAt === null) {
            throw new FirebaseTokenError('Token has no usable exp claim');
        }

        if ($now > $expiresAt + $this->leeway) {
            throw new FirebaseTokenError('Token has expired');
        }

        $issuedAt = self::timestamp($payload['iat'] ?? null);

        if ($issuedAt === null) {
            throw new FirebaseTokenError('Token has no usable iat claim');
        }

        if ($issuedAt > $now + $this->leeway) {
            throw new FirebaseTokenError('Token was issued in the future');
        }

        // Firebase always sets auth_time, so a token without one is not one
        // Firebase minted - and it is REQUIRED now (2026-10-26), because
        // LoginController refuses a sign-in that happened more than five
        // minutes ago, and a check on a claim that may be absent is a check
        // an attacker opts out of by leaving it out. A token claiming the
        // person authenticated later than now is refused for the same reason.
        $authTime = self::timestamp($payload['auth_time'] ?? null);

        if ($authTime === null) {
            throw new FirebaseTokenError('Token has no usable auth_time claim');
        }

        if ($authTime > $now + $this->leeway) {
            throw new FirebaseTokenError('Token says authentication happened in the future');
        }

        $subject = $payload['sub'] ?? null;

        if (!is_string($subject) || $subject === '' || strlen($subject) > 128) {
            throw new FirebaseTokenError('Token subject is missing or malformed');
        }

        $email = $payload['email'] ?? null;

        if ($email !== null && !is_string($email)) {
            throw new FirebaseTokenError('Token email claim is not a string');
        }

        $provider = 'unknown';

        if (is_array($payload['firebase'] ?? null) && is_string($payload['firebase']['sign_in_provider'] ?? null)) {
            $provider = $payload['firebase']['sign_in_provider'];
        }

        return new FirebaseIdentity(
            uid: $subject,
            email: $email,
            // Only a literal true counts. Some providers send "true" as a
            // string, and a loose check would let an unverified address in.
            emailVerified: ($payload['email_verified'] ?? null) === true,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            authTime: $authTime,
            signInProvider: $provider,
            claims: $payload,
        );
    }

    /**
     * Verify, and additionally require a verified email address - the check
     * src/app/api/auth/session/route.ts makes before it looks in `admins`.
     *
     * @throws FirebaseTokenError with the TypeScript's own visible wording
     */
    public function verifiedEmailFrom(string $idToken): string
    {
        $identity = $this->verify($idToken);
        $email    = $identity->verifiedEmail();

        if ($email === null) {
            throw new FirebaseTokenError(
                'Token has no verified email address',
                'Verify your email address first',
            );
        }

        return $email;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeSegment(string $segment, string $which): array
    {
        $json = self::base64UrlDecode($segment);

        if ($json === null) {
            throw new FirebaseTokenError('Token ' . $which . ' is not valid base64url');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 16);

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new FirebaseTokenError('Token ' . $which . ' is not a JSON object');
        }

        /** @var array<string,mixed> $decoded */
        return $decoded;
    }

    /** Strict base64url: padding is absent and any stray character is a refusal. */
    private static function base64UrlDecode(string $segment): ?string
    {
        if ($segment === '' || preg_match('/^[A-Za-z0-9_-]+$/', $segment) !== 1) {
            return null;
        }

        $padded  = strtr($segment, '-_', '+/');
        $padding = strlen($padded) % 4;

        if ($padding === 1) {
            return null; // Not a length base64 can produce.
        }

        if ($padding > 0) {
            $padded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    /** A timestamp claim must be a whole number of seconds, not "1699999999". */
    private static function timestamp(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        // JSON numbers land as float when they are written 1699999999.0.
        if (is_float($value) && $value === floor($value) && abs($value) < 1.0e15) {
            return (int) $value;
        }

        return null;
    }

    private function now(): int
    {
        $clock = $this->clock;

        return $clock === null ? time() : (int) $clock();
    }
}
