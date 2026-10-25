# Admin sign-in with Google (Firebase)

The admin can be signed into with Google, through a Firebase project, and with
nothing else. The server checks Google's ID token itself against Google's
published certificates (`Domain\Auth\FirebaseToken`), so there is no Admin SDK,
no service-account key and no Composer.

Who may sign in is still decided here, not by Google: the Google account's
email must be in the `admins` table (Admin -> Security, or `bin/seed.php
--owner=`). A Google account that is not on the list is refused.

---

## 1. Create the Firebase project

1. <https://console.firebase.google.com> -> **Add project**. Analytics is not
   needed.
2. **Build -> Authentication -> Get started -> Sign-in method -> Google ->
   Enable.** Pick the support email and save.
3. **Authentication -> Settings -> Authorized domains**: add `speedat.co`
   (and `www.speedat.co` if it is ever served). `localhost` is there by default
   for testing.
4. **Project settings (gear) -> Your apps -> Web (`</>`)**: register an app
   (no Hosting). The console prints a `firebaseConfig` with `apiKey`,
   `authDomain`, `projectId` and `appId`.

None of those four values is a secret. Firebase prints them into every page that
uses it. They go in `config.php` rather than a template only so that a staging
copy cannot sign anybody into the live project.

---

## 2. config.php

On the server, `speedat_main/php/config/config.php`:

```php
'auth' => [
    'firebase_project_id'  => 'your-project-id',
    'firebase_api_key'     => 'AIza...',
    'firebase_auth_domain' => 'your-project-id.firebaseapp.com',
    'firebase_app_id'      => '1:1234567890:web:abc123',

    // Google only. Turn this off once a Google sign-in has worked, so there is
    // no password to guess. Leave it on until then, or you can lock yourself out.
    'allow_password_login' => false,
    ...
],

'security' => [
    ...
    // Firebase loads its SDK from gstatic, talks to two Google APIs and opens
    // its popup through the project's auth domain.
    'csp_script'  => 'https://www.gstatic.com',
    'csp_connect' => 'https://identitytoolkit.googleapis.com https://securetoken.googleapis.com https://www.googleapis.com',
    'csp_frame'   => 'https://your-project-id.firebaseapp.com https://accounts.google.com',
],
```

All four `firebase_*` values or none: with any one missing the "Sign in with
Google" button is not drawn at all (`LoginController::firebaseConfigJson()`),
because a button that cannot reach Google looks exactly like a Google outage.

---

## 3. Check it

1. Open `/admin/login` in a private window. The Google button is there.
2. Sign in with the owner's Google account. If the account has a second factor
   switched on (Admin -> Security), the six-digit code is asked next.
3. If the popup opens and closes with nothing happening, open the browser
   console: a `Refused to frame` or `Refused to connect` line names the origin
   missing from `csp_frame` / `csp_connect` above.
4. Only when a Google sign-in has worked, set `allow_password_login` to
   `false`. The email/password form then disappears from the login page.

To undo: set `allow_password_login` back to `true` in `config.php`. Nothing in
the database changes either way.
