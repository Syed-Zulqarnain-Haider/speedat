# Admin sign-in with Google (Firebase)

This guide is for the owner. It switches the admin's sign-in to **Continue with
Google** and, at the very end, switches the email-and-password form off. Every
click is written out. You need: your hPanel login, the Google account you use
for the business, and about an hour.

How it works, in one paragraph: Google checks *who* you are; the website checks
*whether you are an admin*. The server verifies Google's sign-in token itself
against Google's published certificates (`Domain\Auth\FirebaseToken`), so there
is no Admin SDK, no service-account key and no Composer. A Google account whose
email is not in the `admins` table is refused, however valid it is.

> **The one rule.** Keep `'allow_password_login' => true` until Google sign-in
> has worked for you, on this site, at least once. Switching passwords off
> first is how an owner gets locked out. The order below is: Google works
> first, then the 6-digit code, and passwords go off last.

If Google sign-in ever stops working, the way back in is
[`security.md` section 7.7](./security.md#77-google-sign-in-is-broken).
It works even if you never had a password.

---

## Owner checklist

Tick these in order. Each line points at the section with the exact clicks.

```
SPEEDAT - GOOGLE SIGN-IN, OWNER CHECKLIST

Before you start
[ ] 1. hPanel -> File Manager -> speedat_main/php/config/config.php ->
       right-click -> Download. Keep this backup copy on your computer.
[ ] 2. Leave 'allow_password_login' => true in config.php. Do not change it
       until step 15.
[ ] 3. myaccount.google.com -> Security -> turn on 2-Step Verification.
       From now on this Google account is the key to the admin.
[ ] 4. myaccount.google.com -> Personal info -> write down your Google email
       exactly as shown (all lowercase, dots included, no +anything).
[ ] 5. hPanel -> Databases -> phpMyAdmin -> SQL tab -> run:
         SELECT email, role FROM admins;
       Your email from step 4 must be there, letter for letter, as 'owner'.
       If it is not, run (with your email):
         INSERT INTO admins (email, name, role, added_by)
         VALUES ('your.name@gmail.com', 'Owner', 'owner', 'manual');
       Add a new row; do not edit the old one.            (section 0)

Firebase console (console.firebase.google.com)
[ ] 6. Create a project named "speedat". Analytics and Gemini can stay off.
       Gear icon -> Project settings -> General: write down the Project ID
       (lowercase, like speedat-ab12c - not the display name).   (1.1)
[ ] 7. Build -> Authentication -> Get started -> Sign-in method ->
       Add new provider -> Google -> Enable -> public name "Speedat",
       pick your support email -> Save. No other provider enabled. (1.2)
[ ] 8. Authentication -> Settings -> Authorized domains -> Add domain:
       speedat.co, then again: www.speedat.co.                    (1.3)
[ ] 9. Gear icon -> Project settings -> General -> Your apps -> the </> (Web)
       icon -> nickname "speedat admin", do NOT tick Firebase Hosting ->
       Register app. Copy apiKey, authDomain, projectId and appId.  (1.4)

config.php (hPanel File Manager)
[ ] 10. In the 'auth' => [ ... ] part of config.php: fill in
        'firebase_project_id' and ADD three new lines:
        'firebase_api_key', 'firebase_auth_domain', 'firebase_app_id'.
        All four, or the Google button does not appear.           (2.1)
[ ] 11. In 'app' => [ ... ] check 'url' => 'https://speedat.co' and
        'env' => 'production'. Save the file.                     (2.2)
        You do NOT need to type any csp_ values: the login page
        adds Google's addresses by itself.                        (2.3)
[ ] 12. Wait 5 minutes (the server caches PHP files), or reset the cache.
                                                                    (3)

Prove Google works - passwords still ON
[ ] 13. Normal Chrome window (not incognito, not inside WhatsApp, Instagram
        or Facebook): https://speedat.co/admin/login -> Continue with Google
        -> pick the account from step 4 -> you reach the dashboard.
        If not: section 4 says what each message means.            (4)

Second factor, then passwords off
[ ] 14. Admin -> Security -> turn on two-factor with an authenticator app.
        Sign out, sign in again with Google + the 6-digit code. Try it once
        on your phone too. Then make sure your emergency password works:
        sign out, sign in once with email + password + code. No password?
        Set one now (section 6).                                (5 and 6)
[ ] 15. config.php: change 'allow_password_login' => true to false. Save.
        Wait 5 minutes / reset the cache. Reload /admin/login: only the
        Google button shows. Sign in once more with Google + code.   (7)

Afterwards
[ ] 16. Optional: restrict the browser API key to speedat.co.        (8)
[ ] 17. Print docs/security.md section 7.7 (Google sign-in is broken) and
        keep it OUTSIDE the website, with your hPanel login.
```

---

## 0. Before you start

1. **Back up the config.** hPanel → **Files → File Manager** → open
   `speedat_main/php/config/` → right-click `config.php` → **Download**. If
   anything in this guide goes wrong, uploading that copy back puts the site
   exactly as it was.
2. **Protect your Google account.** <https://myaccount.google.com> →
   **Security** → **2-Step Verification** → turn it on. Once passwords are off,
   whoever controls this Google account controls the admin.
3. **Write down your exact Google email.** <https://myaccount.google.com> →
   **Personal info** → **Email**. Copy it exactly: lowercase, every dot, and no
   `+something` added.
4. **Check that this email is an admin.** hPanel → **Databases → phpMyAdmin** →
   **Enter phpMyAdmin** next to the site's database → **SQL** tab → paste and
   press **Go**:
   ```sql
   SELECT email, role FROM admins;
   ```
   The address from step 3 must be in the list, character for character, with
   role `owner`. Sign-in compares the whole address, so `asif.khan@gmail.com`
   and `asifkhan@gmail.com` are different people as far as the site is
   concerned. If yours is missing or different, add a new owner row (adding is
   safer than changing the old one):
   ```sql
   INSERT INTO admins (email, name, role, added_by)
   VALUES ('your.name@gmail.com', 'Owner', 'owner', 'manual');
   ```
   The other way to add it is a one-off hPanel cron entry running
   `bin/seed.php --owner=<your email>`
   ([`go-live-checklist.md`](./go-live-checklist.md) item 15).

---

## 1. Create the Firebase project

Open <https://console.firebase.google.com> and sign in with the business Google
account.

1. **Create the project.** Click **Create a project** (on a first visit it may
   say **Get started with a Firebase project**). Name it `speedat` →
   **Continue**. Gemini in Firebase and Google Analytics are not needed: leave
   them off → **Create project** → **Continue**.
   Then click the **gear icon** next to *Project Overview* (top left) →
   **Project settings** → **General** tab, and write down the **Project ID**.
   It is lowercase and often has a few extra characters, like `speedat-ab12c`.
   It is *not* the display name.
2. **Turn on Google sign-in, and nothing else.** Left menu → **Build →
   Authentication** (or type "Authentication" in the console's search box) →
   **Get started** → **Sign-in method** tab → **Add new provider** → **Google**
   → switch **Enable** on. Set **Public-facing name** to `Speedat`, choose your
   email as **Support email** → **Save**.
   Leave every other sign-in provider disabled: no Email/Password, Phone,
   Anonymous, Microsoft, GitHub, Apple or any other. Google must be the only
   line in the list that says *Enabled*.
3. **Allow the website's address.** Still in Authentication → **Settings** tab
   → **Authorized domains** → **Add domain** → type `speedat.co` → **Add**.
   Click **Add domain** again → `www.speedat.co` → **Add**. Leave
   `<your-project-id>.firebaseapp.com` in the list; Google's sign-in window
   runs on it. (`localhost` is there for testing; see section 8.)
   While you are on the Settings tab: under **User account linking** keep
   *Link accounts that use the same email*, and make sure **Email enumeration
   protection** is on.
4. **Register the website as a "web app".** Gear icon → **Project settings** →
   **General** → scroll down to **Your apps** → click the **`</>`** (Web) icon.
   App nickname `speedat admin`. Do **not** tick *Also set up Firebase
   Hosting* → **Register app**. The page shows a block of code containing
   `const firebaseConfig = { ... }`. Copy these four values out of it:

   | In `firebaseConfig` | Looks like | Goes into `config.php` as |
   |---|---|---|
   | `apiKey` | `AIzaSy...` (39 characters) | `firebase_api_key` |
   | `authDomain` | `speedat-ab12c.firebaseapp.com` | `firebase_auth_domain` |
   | `projectId` | `speedat-ab12c` | `firebase_project_id` |
   | `appId` | `1:123456789012:web:abc123...` | `firebase_app_id` |

   You can see them again later: Project settings → General → Your apps →
   *speedat admin* → **SDK setup and configuration** → **Config**.

None of those four values is a secret: Firebase sends them to every visitor's
browser. They live in `config.php` rather than in the code only so that a
staging copy can never sign anybody into the live project. Still, do not paste
them into public chats; there is no need to.

---

## 2. config.php

### 2.1 The four Firebase lines (three of them are new)

hPanel → File Manager → `speedat_main/php/config/config.php` → right-click →
**Edit**.

Find the part that starts with `'auth' => [`. The live file today has **only one**
Firebase line in it, `'firebase_project_id' => '',`. The other three do not
exist yet: you must **add** them, inside the same `'auth' => [ ... ],` block,
directly under the project id. It should end up looking like this (your own
values, not these examples):

```php
    'auth' => [
        'firebase_project_id'  => 'speedat-ab12c',
        'firebase_api_key'     => 'AIzaSy...your-api-key...',
        'firebase_auth_domain' => 'speedat-ab12c.firebaseapp.com',
        'firebase_app_id'      => '1:123456789012:web:abc123...',

        // Keep this true until section 7. It is your way back in.
        'allow_password_login' => true,

        // ... the lines that were already there (session_lifetime and so on)
        //     stay exactly as they are.
    ],
```

Check, character by character:

- every value is inside single quotes `'...'` and every line ends with a comma;
- `firebase_auth_domain` is just the name, **no** `https://` in front and no
  `/` at the end;
- the new lines are *inside* `'auth' => [` ... `],`, not after it and not in
  the `'security'` part.

All four or nothing: if any one of the four is empty or missing, the
**Continue with Google** button is not drawn at all
(`LoginController::firebaseConfigJson()`), because a button that cannot reach
Google would look exactly like a Google outage.

### 2.2 Two `app` values to check

In the `'app' => [` part, check:

```php
        'url' => 'https://speedat.co',
        'env' => 'production',
```

`production` is what makes the sign-in cookies *Secure*.

**Save** the file.

### 2.3 You do not need to set any `csp_` value

The website's Content-Security-Policy normally lets the browser load scripts and
talk only to speedat.co itself. When all four `firebase_*` values are set, the
**login page** (and only the login page) adds Google's addresses by itself:

- scripts from `https://www.gstatic.com` and `https://apis.google.com`;
- connections to `https://identitytoolkit.googleapis.com`,
  `https://securetoken.googleapis.com` and `https://www.googleapis.com`;
- the sign-in window from `https://<firebase_auth_domain>` and
  `https://accounts.google.com`.

Every other page keeps the strict policy. So **leave `csp_script`,
`csp_connect` and `csp_frame` as they are** (empty, or missing - `csp_frame`
is not in the live file, and that is fine). They are optional extras for a
developer who one day adds another outside service; they are not part of the
Google set-up.

### 2.4 Optional keys you can ignore

- `'firebase_sdk_version'` — leave it out. The site uses the tested Firebase
  version `10.12.2`. Only set it (to three numbers, like `'10.12.2'`) if a
  developer tells you to.
- `'setup_token'` — only for a brand-new install with an empty `admins` table.
  You already have an owner, so you do not need it.

---

## 3. Make the change take effect

The server keeps a cached copy of PHP files (OPcache) for a few minutes. After
saving `config.php`, either:

- **wait five minutes** and reload the page; or
- reset the cache as described in
  [`deploy-hostinger.md` section 14](./deploy-hostinger.md#14-after-every-deploy-reset-opcache).
  It needs the `tokens.cron` value from `config.php`. If someone helps you
  with a terminal, they use the `curl` command there. On your own, you can
  open `https://speedat.co/ops/opcache-reset?token=<your cron token>` in the
  browser. That works, but it writes the token into the server's access log,
  so ask your developer to change the cron token afterwards
  ([`security.md` 7.2](./security.md#72-a-token-leaked-cron-metrics-inbound_email)).

If nothing has changed after fifteen minutes, check that you edited the file in
`speedat_main/php/config/`, not a copy somewhere else.

---

## 4. Test Google sign-in, with passwords still on

1. Use a **normal Chrome window** on a computer. Not incognito/private (if you
   must use one, allow third-party cookies in it), and **not** the browser
   inside WhatsApp, Instagram or Facebook — Google refuses sign-in there.
2. Open `https://speedat.co/admin/login`. You should see **Continue with
   Google** above the email-and-password form.
3. Click it, choose the Google account from section 0 step 3. If two-factor is
   already on for your admin account, the 6-digit code is asked next.
4. You reach the dashboard. Done — this is the proof everything else depends on.
5. In File Manager, `speedat_main/php/storage/cache/google-certs/` now holds a
   file named `firebase-certs-....json`. That is Google's certificates, saved
   by the server; it proves the server can reach Google.

### If it did not work

| What you see | What it means | Fix |
|---|---|---|
| No Google button at all | one of the four `firebase_*` values is empty or missing, or the cache has not caught up | section 2.1, then section 3 |
| "This website is not on the Firebase project's list of authorized domains..." | the domain is not in Authorized domains | section 1 step 3 |
| "Your browser blocked the Google sign-in window..." | pop-up blocker | allow pop-ups for speedat.co, try again |
| "...private windows and in-app browsers do this..." | wrong kind of browser window | normal Chrome or Safari window |
| The Google window opens and closes, nothing happens | a Firebase value is wrong | press **F12 → Console** and read below |
| "That Google sign-in was refused." | Google said yes, the server said no | read the log, below |
| A message that sign-in is temporarily unavailable on the server's side | the server could not get Google's certificates | read the log (`login_google_unavailable`), below |

**The browser console (F12 → Console).** A red line starting
**`Refused to load`**, **`Refused to connect`** or **`Refused to frame`** means
one of your Firebase values is wrong — almost always `firebase_auth_domain`
(typo, or `https://` left in front) or a value copied from a different
Firebase project. Compare all four with the Firebase console (section 1
step 4) and fix them in `config.php`. You do not fix this by typing `csp_`
values.

**The server log.** File Manager →
`speedat_main/php/storage/logs/app-<today's date>.log` → open it and search for
`login_google_refused` (and, on newer releases, `login_google_unavailable`,
which is written when the server could not fetch Google's certificates). The
`reason` says why:

| `reason` contains | Meaning | Fix |
|---|---|---|
| `not_an_admin` | the Google email is not in `admins` exactly | section 0 step 4 |
| `audience` or `issuer` | the token was for another project | `firebase_project_id` is wrong (it must be the Project ID, not the name) |
| `Could not reach Google` | the server cannot reach Google over HTTPS | ask Hostinger support to check outgoing HTTPS from your hosting plan |
| `cURL is unavailable` | the PHP `curl` extension is off | hPanel → Advanced → PHP Configuration → PHP extensions → tick `curl` |

---

## 5. Turn on the site's own 6-digit code (recommended)

While signed in: **Admin → Security** → turn on two-factor → scan the QR code
with an authenticator app (Google Authenticator, Microsoft Authenticator,
Authy) → type the code it shows. Store the app's backup (or your phone's
backup) somewhere safe.

Then sign out, and sign in again with Google **plus** the 6-digit code. Also
try it once on your phone, in Safari or Chrome.

---

## 6. Make sure the emergency password works

The email-and-password form is your way back in if Google ever fails. Before
switching it off, prove it works: sign out → sign in with your **email and
password** (plus the 6-digit code) → you reach the dashboard.

**If your account has no password** (for example, it was first set up through
Google), set one now with the steps in
[`security.md` section 7.7](./security.md#77-google-sign-in-is-broken), part B.
At least 12 characters; write it in your password manager. Never remove your
password from the database: once passwords are switched off nobody can use it
to sign in, and it is the spare key for the day Google breaks.

---

## 7. Switch passwords off

Only after sections 4, 5 and 6 have all worked:

1. `config.php` → in `'auth' => [`, change
   `'allow_password_login' => true,` to `'allow_password_login' => false,` →
   **Save**.
2. Wait five minutes, or reset the cache (section 3).
3. Reload `https://speedat.co/admin/login`. Only **Continue with Google**
   shows; the email-and-password form is gone.
4. Sign in once more with Google plus the code.

To undo, set it back to `true`. Nothing in the database changes either way.

---

## 8. Optional hardening

**Lock the public API key to your website.** <https://console.cloud.google.com>
→ project picker at the top → choose the same project → **APIs & Services →
Credentials** → click **Browser key (auto created by Firebase)**.

- **Application restrictions:** **Websites**, then add
  `https://speedat.co/*`, `https://www.speedat.co/*` and
  `https://<your-project-id>.firebaseapp.com/*`.
- **API restrictions:** **Restrict key**, tick **Identity Toolkit API** and
  **Token Service API**.
- **Save**, wait five minutes, and repeat the test in section 4. If sign-in
  breaks, set both back to *None* / *Don't restrict key*.

**Remove `localhost`** from Authentication → Settings → Authorized domains on
this live project, then test again. Developers should test against a separate
Firebase project.

---

## 9. If Google sign-in stops working

Follow [`security.md` section 7.7](./security.md#77-google-sign-in-is-broken).
In short: switch passwords back on in `config.php`, sign in with your password
and code, fix Firebase, switch passwords off again. If you have no password, the
same section shows how to set one without a terminal. If you lost the phone with
the code app, see [`security.md` section 7.5](./security.md#75-a-totp-secret-leaked-or-a-phone-was-lost).
