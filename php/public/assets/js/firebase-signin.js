/*!
 * firebase-signin.js - the half of "Continue with Google" that only a browser
 * can do, as plain ES2019 with no build step.
 *
 * The server cannot get an ID token on the owner's behalf: the token is minted
 * by Google for a person who signed in, in a window only the browser can open.
 * So this file opens it, takes the token, puts it in the hidden field on the
 * Google form in app/Views/admin/login.php, and lets the form post as it would
 * have anyway. Everything after that is Http\Admin\LoginController::google(),
 * which verifies the signature against Google's published certificates and then
 * asks the `admins` table whether that address may sign in.
 *
 * THE BUTTON IS HIDDEN UNTIL THIS FILE HAS WORKED. reveal() runs after
 * initializeApp(), not on DOMContentLoaded. A blocked CDN, a CSP that has not
 * been filled in, a browser with JavaScript off - every one of those leaves the
 * sign-in page as the password form alone, which works - or, on a Google-only
 * deployment, as the one sentence saying why there is no button. A visible
 * button that silently does nothing is the one outcome worth this much care: the person in
 * front of it cannot tell it apart from a Google outage, and there is nothing
 * on screen to try instead.
 *
 * NO TOKEN IS STORED. Firebase's default persistence would keep a refresh token
 * in IndexedDB on the office laptop, and this app keeps its own `sessions` table
 * - a second, longer-lived identity is exactly what the PHP port removed. So
 * persistence is inMemory, and signOut() runs the moment the token has been
 * read: Firebase's job ends with one string.
 *
 * WHY THE SDK COMES FROM CONFIG AND NOT FROM HERE. The four values and the SDK
 * version are read out of the <script type="application/json"> block the server
 * printed. None of them is a secret, but a value baked into this file could not
 * differ between the live site and staging, and staging must not be able to sign
 * anybody into the live project.
 */
(function () {
  "use strict";

  var CONFIG_ID = "firebase-config";
  var CDN = "https://www.gstatic.com/firebasejs/";

  var IN_APP =
    "Google does not allow sign-in inside apps like WhatsApp, Facebook or Instagram. " +
    "Open this page in Chrome or Safari: tap the app's menu (the three dots or the share button) " +
    "and choose Open in browser, then press Continue with Google there.";

  var wrapper = document.querySelector("[data-google-signin]");
  var form = wrapper && wrapper.querySelector("[data-google-form]");
  var field = form && form.querySelector("[data-google-token]");
  var button = form && form.querySelector("button");
  var configEl = document.getElementById(CONFIG_ID);

  if (!wrapper || !form || !field || !button || !configEl) return;

  var config = read(configEl);
  if (!config) return;

  /* A dynamic import() is what lets one classic <script defer> file pull in the
     modular SDK with no bundler. Both modules come from the same pinned version
     so their internals cannot disagree. */
  var base = CDN + config.sdkVersion + "/firebase-";

  Promise.all([import(base + "app.js"), import(base + "auth.js")])
    .then(function (mods) {
      var app = mods[0];
      var auth = mods[1];

      var instance = auth.initializeAuth(app.initializeApp(config), {
        persistence: auth.inMemoryPersistence,
        popupRedirectResolver: auth.browserPopupRedirectResolver,
      });

      var provider = new auth.GoogleAuthProvider();
      /* Always ask which account. The owner's browser is signed in to a personal
         Google account too, and silently reusing it produces a refusal worded
         exactly like a wrong password, with no clue that the wrong address went. */
      provider.setCustomParameters({ prompt: "select_account" });

      form.addEventListener("submit", function (event) {
        if (field.value) return; /* the real post, started below */

        event.preventDefault();
        busy(true);
        clearNotice();

        auth
          .signInWithPopup(instance, provider)
          .then(function (result) {
            return result.user.getIdToken();
          })
          .then(function (token) {
            field.value = token;
            /* Hand Firebase's copy back before the post. The server is about to
               mint the only session that matters and this one is now noise. */
            return auth.signOut(instance).catch(noop).then(function () {
              form.submit();
            });
          })
          .catch(function (error) {
            busy(false);
            field.value = "";
            explain(error);
          });
      });

      reveal();

      /* Said up front, not after a failed try. Google refuses sign-in inside
         an app's built-in browser (Facebook, Instagram, an Android web view),
         and it does so on its own page inside the popup, so all this file
         ever hears back is "the window was closed". The owner opens admin
         links from WhatsApp on a phone (2026-10-26); without this the button
         looks broken. The button stays, because the guess can be wrong. */
      if (inAppBrowser()) notify(IN_APP);
    })
    .catch(function (err) {
      /* Left hidden on purpose. See the note at the top of this file. Logged
         because it was silent: a CSP missing gstatic, a mistyped sdkVersion
         and a blocked CDN all looked the same from the outside, and the
         console is the first place anyone helping the owner will look
         (review, 2026-10-26). */
      if (window.console && console.error) console.error("firebase-signin: SDK load failed", err);
    });

  /* Back from the next page restores this one from the browser's page cache
     exactly as it was left: button disabled and reading "Waiting for Google",
     and the token still in the field, so the next press would skip the popup
     and post a stale token (review, 2026-10-26). `persisted` is true only
     for that restore; a normal load has nothing to undo. */
  window.addEventListener("pageshow", function (event) {
    if (!event.persisted) return;
    field.value = "";
    busy(false);
    clearNotice();
    if (inAppBrowser() && !wrapper.hasAttribute("hidden")) notify(IN_APP);
  });

  /* ------------------------------------------------------------------ */

  function read(el) {
    var parsed;

    try {
      parsed = JSON.parse(el.textContent || "");
    } catch (e) {
      return null;
    }

    if (!parsed || !parsed.apiKey || !parsed.authDomain || !parsed.projectId || !parsed.appId) {
      return null;
    }

    /* The version becomes part of a script URL, so it is checked here as well as
       in PHP: this file must be safe to serve even if something else ever writes
       that JSON block. */
    if (!/^[0-9]+\.[0-9]+\.[0-9]+$/.test(String(parsed.sdkVersion || ""))) {
      return null;
    }

    return parsed;
  }

  function reveal() {
    wrapper.removeAttribute("hidden");
    /* On a Google-only deployment the page carries a sentence for the case where
       this button never appears. It has now appeared, so the sentence is false. */
    var fallback = document.querySelector("[data-google-fallback]");
    if (fallback) fallback.setAttribute("hidden", "");
  }

  /* The password form is rendered only when the deployment allows passwords, so
     its presence is the answer. Asked at the moment of the error rather than
     cached, in case anything ever re-renders the card. */
  function passwordsOffered() {
    return !!document.querySelector("[data-password-form]");
  }

  function busy(on) {
    button.disabled = !!on;
    button.textContent = on ? "Waiting for Google…" : "Continue with Google";
  }

  /* A guess from the user-agent string, and only used to show advice early.
     FBAN/FBAV/FB_IAB is Facebook and Messenger, "; wv)" is any Android app's
     embedded web view (WhatsApp's own viewer among them when Custom Tabs are
     off), and the rest are the in-app browsers people forward links through. */
  function inAppBrowser() {
    var ua = (navigator && navigator.userAgent) || "";
    return /FBAN|FBAV|FB_IAB|FBIOS|Instagram|WhatsApp|Line\/|MicroMessenger|Snapchat|musical_ly|TikTok|; wv\)/i.test(ua);
  }

  function clearNotice() {
    var existing = wrapper.querySelector("[data-google-notice]");
    if (existing && existing.parentNode) existing.parentNode.removeChild(existing);
  }

  /**
   * What went wrong, in words the owner can act on.
   *
   * Only the codes a person actually hits are named. The catch-all deliberately
   * does NOT print error.message: those messages name Firebase internals and the
   * project, and the one thing that always helps is the password form below -
   * when there is one. A Google-only deployment has no form to point at, so
   * those sentences end at "try again" instead.
   */
  function explain(error) {
    var code = (error && error.code) || "";
    var orPassword = passwordsOffered();
    var says;

    if (inAppBrowser() && (code === "auth/popup-closed-by-user" || code === "auth/popup-blocked")) {
      /* Inside an app's browser a closed or blocked popup is almost always
         Google's own refusal page, so "try again" or "allow pop-ups" would
         send the owner round in a circle. */
      says = IN_APP;
    } else if (code === "auth/popup-closed-by-user" || code === "auth/cancelled-popup-request") {
      says = "That sign-in window was closed before it finished. Try again.";
    } else if (code === "auth/popup-blocked") {
      says = orPassword
        ? "Your browser blocked the Google sign-in window. Allow pop-ups for this site, or sign in with your email and password below."
        : "Your browser blocked the Google sign-in window. Allow pop-ups for this site and try again.";
    } else if (code === "auth/web-storage-unsupported" || code === "auth/operation-not-supported-in-this-environment") {
      /* Firebase needs browser storage to carry the popup's answer back, and
         private windows and in-app browsers refuse it. These two codes used to
         fall through to "did not finish. Try again", which can never work
         (review, 2026-10-26). The fix is a different browser, so say that. */
      says = inAppBrowser()
        ? IN_APP
        : "This browser is blocking what Google sign-in needs (private windows and in-app browsers do this). Open this page in a normal Chrome or Safari window.";
    } else if (code === "auth/unauthorized-domain") {
      /* The one misconfiguration that is invisible from the server: this
         domain is not in the Firebase project's Authorized domains list.
         docs/firebase-setup.md section 1, step 3 is the fix, and naming it
         here is the difference between a five-minute fix and an afternoon.
         It used to point at a sixth step that does not exist (review, 2026-10-26),
         and it now names the console path and the exact host to add. */
      says = "This website is not on the Firebase project's list of authorized domains, so Google refused the sign-in. An administrator has to add it: Firebase console -> Authentication -> Settings -> Authorized domains: add " + location.hostname + " (docs/firebase-setup.md section 1, step 3).";
    } else if (code === "auth/network-request-failed") {
      says = orPassword
        ? "Google could not be reached. Check the connection, or sign in with your email and password below."
        : "Google could not be reached. Check the connection and try again.";
    } else {
      says = orPassword
        ? "That Google sign-in did not finish. Try again, or sign in with your email and password below."
        : "That Google sign-in did not finish. Try again.";
    }

    notify(says);
  }

  /* One notice at a time, under the button: a second failure replaces the
     first instead of stacking. */
  function notify(says) {
    clearNotice();
    var notice = document.createElement("div");
    notice.className = "notice err";
    notice.setAttribute("role", "alert");
    notice.setAttribute("data-google-notice", "");
    notice.textContent = says;
    wrapper.appendChild(notice);
  }

  function noop() {}
})();
