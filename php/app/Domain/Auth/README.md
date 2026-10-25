# app/Domain/Auth

The libraries behind the two ways the owner signs in, both followed by a
six-digit code. **No screens here** — phase 3 builds those and wires these
classes to the database. Nothing in this folder touches `$_GET`, `$_POST`,
`$_SERVER`, `echo`, or a `PDO`; every class takes values and returns values, so
all of it is testable without a request or a database.

Ported from `src/lib/auth/{firebase-admin,session,totp}.ts` and
`src/app/api/auth/session/route.ts`. Where the TypeScript's wording is visible
to a person, it is kept exactly.

| File | What it is |
|------|------------|
| `FirebaseToken.php` | Verifies a Firebase ID token in plain PHP: RS256 over Google's published certificates, then `iss` / `aud` / `exp` / `iat` / `auth_time` / `sub`. Replaces `firebase-admin`. |
| `FirebaseIdentity.php` | What a verified token says — uid, email, `email_verified`, the sign-in provider, every claim. |
| `CertificateSource.php` | Where the public keys come from. An interface so the tests can sign their own tokens. |
| `GoogleCertificates.php` | The real source: cURL + an on-disk cache that honours `max-age`, with a grace window for when Google is unreachable. |
| `Totp.php` | RFC 6238. SHA-1, six digits, thirty seconds, ±1 step, and the replay guard. |
| `Base32.php` | RFC 4648 base32, unpadded out, forgiving in. |
| `SecretBox.php` | AES-256-GCM for the TOTP seed at rest, in the same format the TypeScript wrote, with key rotation as an argument. |
| `Password.php` | argon2id where the host has it, bcrypt cost 12 where it does not; `needs_rehash` on the way in. |
| `PasswordPolicy.php` | Twelve characters, not the email, not a known-weak password. |
| `PasswordSetupToken.php` | The "set your password" link: 32 random bytes, SHA-256 at rest, single use, sixty minutes. |
| `PasswordSetupStatus.php` | Which of those four ways it was refused. |
| `Qr.php` | A QR encoder (byte mode, level M, versions 1–10) for the enrolment URI, as SVG or PNG, with a text fallback. |
| `AuthError.php`, `FirebaseTokenError.php` | Two messages per failure: the reason for the log, and what the visitor may read. |

## The decisions worth knowing before you change anything

**There is no Firebase service-account key any more.** The Admin SDK needed one
because it also mints tokens; verifying a signature needs only the public half,
which Google publishes. So `FIREBASE_SERVICE_ACCOUNT_B64` disappears in the PHP
port — one fewer secret on a shared host. `config/config.php` needs only
`auth.firebase_project_id`.

**Session cookies are gone with it.** `createSessionCookie()` and
`revokeRefreshTokens()` were Admin-SDK features. The PHP app keeps its own
`sessions` table (see `db/migrations/0001_init.sql`), so signing out is a
`DELETE` we control rather than waiting out a five-day Firebase cookie. This
namespace therefore verifies **ID tokens only**.

**Encrypted TOTP secrets survive the port.** `SecretBox` writes
`base64(iv).base64(ciphertext).base64(tag)` with the key derived as
`sha256(app key)` — byte-for-byte what `src/lib/auth/totp.ts` produced. A secret
enrolled in the Next.js app still decrypts here, so cut-over night does not mean
re-enrolling the owner's authenticator app. `tests/Auth/SecretBoxTest.php` pins
this against a value produced by Node.

**The replay guard needs one atomic statement in phase 3.** `Totp::verify()`
returns the step it accepted and refuses any step at or below the one it is
handed, but it cannot stop two simultaneous requests spending the same code.
The write must be

```sql
UPDATE admins SET totp_last_step = :step
 WHERE email = :email AND (totp_last_step IS NULL OR totp_last_step < :step)
```

and the sign-in only succeeds if that touched a row. Same for the set-password
link: spend it with `WHERE used_at IS NULL AND expires_at > NOW()` and check the
affected row count.

**Where the certificate cache goes.** Anywhere outside the web root that PHP can
write — `storage/cache/` is the obvious place. `GoogleCertificates` refuses a
path with a `public`, `public_html`, `htdocs` or `www` segment in it, because a
writable directory under the document root is one upload bug away from being a
web shell.

**Key rotation is an argument.** `new SecretBox($new, [$old])` — the first key
encrypts, all of them decrypt, and `encryptedWithCurrentKey()` says which rows
still need re-writing. Never a constant in a file.

## What phase 3 still has to add

- A `password_setup` table (`token_hash CHAR(64) PRIMARY KEY`, `email`,
  `expires_at DATETIME`, `used_at DATETIME NULL`) — `PasswordSetupToken` defines
  the behaviour but owns no SQL.
- The repository that reads and writes `admins.password_hash`,
  `totp_secret_enc`, `totp_enabled` and `totp_last_step`.
- Rate limiting on the sign-in and the code step (`Core\RateLimit` already
  exists; the TypeScript used 20 attempts per 15 minutes per IP).
- Audit entries: `login`, `login_denied`, `login_mfa_failed`, `logout`,
  `totp_enrol_started`, `totp_enabled`, `totp_disabled`.
