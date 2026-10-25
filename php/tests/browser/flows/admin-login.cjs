"use strict";

/**
 * Getting in: both sign-in paths and the second factor.
 *
 * Login is the surface an attacker gets unlimited attempts at, so most of this
 * flow is about what the page refuses to do:
 *
 *   no enumeration     an unknown address and a wrong password produce the
 *                      same answer, so nobody can harvest the admin list.
 *   rate limited       per IP and per account, with the same wording.
 *   second factor      required on BOTH paths, and a code is single-use, so a
 *                      code read over someone's shoulder is already spent.
 *   real session       the cookie is HttpOnly and SameSite, the id rotates on
 *                      sign-in, and logging out actually revokes it.
 *   no JavaScript      the owner signs in from a phone on a bad connection.
 *
 * The Google path cannot be driven end to end from here - that needs a real
 * Google account in a real consent screen - so what is asserted instead is that
 * the button is there and that a forged token is refused. The successful Google
 * sign-in is the one thing in this harness that stays a manual check, and it
 * says so rather than pretending.
 */

const contract = require("../contract.cjs");
const { adminCredentials, freshTotp } = require("../harness.cjs");

module.exports = {
  name: "admin-login",
  /*
   * LAST AMONG THE ADMIN FLOWS, AND THAT IS NOT A PREFERENCE (2026-09-29).
   *
   * This flow spends the sign-in budget on purpose: "repeated failures are
   * rate limited" makes six failed attempts, and `LoginController` limits per
   * IP as well as per address over a FIFTEEN-MINUTE window. Every flow arrives
   * from 127.0.0.1. At order 20 it ran before admin-rates-publish,
   * admin-hold, admin-inbox-shipments and admin-settings-content and emptied
   * the bucket under them - four flows that could not sign in, reported as
   * four crashed flows, and none of it about the product.
   *
   * At order 29 the four flows that need a session get one first (they now
   * share a single cached sign-in - see harness.adminSession), and this flow
   * spends what is left, which nothing after it needs. The same run twice in
   * a quarter of an hour still exhausts it eventually; that is the site's
   * brute-force defence working, and adminSession reports it as NOT READY
   * with the reason rather than crashing.
   */
  order: 29,
  title: "password and Google, the 6-digit code, and everything login refuses",
  viewport: "desktop",

  async run(t) {
    const creds = adminCredentials();

    t.need(t.probe.status["/admin/login"] !== 404, "/admin/login answers 404 - the admin has not shipped");

    /* ---- the door is shut ------------------------------------------- */

    await t.check("/admin is not reachable without a session", async () => {
      const res = await t.fetch("/admin");
      if (!res.ok) throw new Error("no answer: " + res.error);
      if (res.status === 200 && !/sign in|log in|password/i.test(res.body)) {
        throw new Error("/admin answered 200 to an anonymous request, with what looks like the admin itself");
      }
      if (![301, 302, 303, 307, 401, 403, 404].includes(res.status) && res.status !== 200) {
        throw new Error("/admin answered " + res.status);
      }
      return res.status + (res.headers && res.headers.location ? " → " + res.headers.location : "");
    });

    await t.check("a few admin pages, each unreachable without a session", async () => {
      const open = [];
      for (const p of ["/admin/rates/review", "/admin/inbox", "/admin/shipments", "/admin/security", "/admin/sections"]) {
        const res = await t.fetch(p);
        if (res.ok && res.status === 200 && !/sign in|log in|password/i.test(res.body)) open.push(p);
      }
      if (open.length) throw new Error("these answered 200 to an anonymous request: " + open.join(", "));
      return "5 admin paths, none of them open";
    });

    /* ---- the login page itself -------------------------------------- */

    await t.check("the login page loads and carries a CSRF token", async () => {
      await t.open("/admin/login");
      await t.page.waitForTimeout(250);
      await t.shot("login");
      return contract.everyFormHasCsrf(t.page);
    });

    await t.check("the login page is not indexable", async () => {
      const res = await t.fetch("/admin/login");
      const meta = (res.body.match(/<meta[^>]+name=["']robots["'][^>]*content=["']([^"']*)["']/i) || [])[1] || "";
      const header = String((res.headers || {})["x-robots-tag"] || "");
      if (!/noindex/i.test(meta) && !/noindex/i.test(header)) {
        throw new Error("neither a robots meta nor an X-Robots-Tag says noindex on the login page");
      }
      return "noindex via " + (/noindex/i.test(meta) ? "meta" : "X-Robots-Tag");
    });

    await t.check("both sign-in methods are offered", async () => {
      const found = await t.page.evaluate(() => ({
        password: !!document.querySelector('input[type=password]'),
        email: !!document.querySelector('input[type=email], input[name=email]'),
        google: !!Array.from(document.querySelectorAll("a, button, form")).find((el) =>
          /google/i.test((el.textContent || "") + " " + (el.getAttribute("action") || "") + " " + (el.getAttribute("href") || "")),
        ),
      }));
      /*
       * PASSWORD SIGN-IN IS THE SITE. GOOGLE IS A CONFIGURATION (2026-09-29).
       *
       * The Google button is printed only when `auth.firebase_project_id` is
       * set: Http\Admin\LoginController builds $googleReady from it and
       * app/Views/admin/login.php omits the whole block otherwise. A
       * development box has no Firebase project, so this check failed on every
       * local run with "the login page offers no google" - which is not a
       * defect. It is a host that was never given a Firebase project saying so
       * correctly, and a red check nobody can act on is how the next red check
       * gets scrolled past.
       *
       * A MISSING EMAIL OR PASSWORD FIELD IS STILL A HARD FAILURE: that is the
       * sign-in every admin uses and no configuration takes it away. A missing
       * Google button is NOT READY with the reason named, so this still speaks
       * up on a host where Google IS configured and the button has vanished -
       * which is the defect it was written for.
       */
      const missing = ["email", "password"].filter((k) => !found[k]);
      if (missing.length) throw new Error("the login page offers no " + missing.join(", "));

      t.need(
        found.google,
        "no Google button on the login page, which is exactly what a host with "
          + "auth.firebase_project_id unset is supposed to render. Set it in config/config.php to "
          + "exercise this path. Email and password sign-in is present and is checked below.",
      );
      return "email + password, and a Google button";
    });

    /* ---- what it refuses -------------------------------------------- */

    await t.check("an unknown address and a wrong password read identically", async () => {
      const attempt = async (email, password) => {
        const page = await t.context.newPage();
        t.watch(page);
        await page.goto(t.url("/admin/login"), { waitUntil: "domcontentloaded" });
        await page.fill('input[type=email], input[name=email]', email);
        await page.fill("input[type=password]", password);
        await page.click("button[type=submit], input[type=submit]");
        await page.waitForLoadState("domcontentloaded", { timeout: 15000 }).catch(() => {});
        await page.waitForTimeout(300);
        const said = await page.evaluate(() =>
          Array.from(document.querySelectorAll(".error, .alert, [role=alert], .field-error"))
            .map((e) => (e.textContent || "").replace(/\s+/g, " ").trim())
            .filter(Boolean)
            .join(" | "),
        );
        const status = page.url();
        await page.close();
        return { said, status };
      };

      const unknown = await attempt("nobody-" + Date.now() + "@example.invalid", "whatever-long-enough");
      const wrong = await attempt(creds.email || "admin@example.invalid", "definitely-not-the-password-" + Date.now());

      if (!unknown.said && !wrong.said) {
        throw new Error("a failed sign-in says nothing at all, so a person cannot tell a typo from an outage");
      }
      const normalise = (s) => s.replace(/\d+/g, "N").toLowerCase();
      if (normalise(unknown.said) !== normalise(wrong.said)) {
        throw new Error(
          [
            "the two failures read differently, which lets anyone test whether an address is an admin:",
            "    unknown address : " + JSON.stringify(unknown.said),
            "    wrong password  : " + JSON.stringify(wrong.said),
          ].join("\n"),
        );
      }
      return "both say " + JSON.stringify(unknown.said.slice(0, 80));
    });

    await t.check("a forged Google token is refused", async () => {
      // Three base64url segments that parse as a JWT and are signed by nobody.
      const b64 = (o) => Buffer.from(JSON.stringify(o)).toString("base64url");
      const forged =
        b64({ alg: "RS256", kid: "not-a-google-key" }) +
        "." +
        b64({ iss: "https://accounts.google.com", aud: "speedat", email: creds.email || "admin@example.invalid", exp: Math.floor(Date.now() / 1000) + 3600 }) +
        ".bm90LWEtc2lnbmF0dXJl";

      let answered = null;
      for (const path of ["/admin/login/google", "/api/auth/session"]) {
        // eslint-disable-next-line no-await-in-loop
        const res = await t.fetch(path, {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ idToken: forged, credential: forged }),
        });
        if (res.ok && res.status !== 404) {
          answered = { path, status: res.status, body: res.body.slice(0, 160) };
          if (res.status >= 200 && res.status < 300) {
            throw new Error(path + " accepted a token signed by nobody (" + res.status + "): " + answered.body);
          }
          if (/set-cookie/i.test(Object.keys(res.headers || {}).join(","))) {
            throw new Error(path + " set a cookie for a forged token");
          }
          break;
        }
      }
      t.need(answered, "neither /admin/login/google nor /api/auth/session answers - the Google path has not shipped");
      return answered.path + " refused a forged token with " + answered.status;
    });

    /* ---- and what it allows ----------------------------------------- */

    await t.check("the password path asks for a 6-digit code", async () => {
      t.need(creds.email && creds.password, "set SPEEDAT_ADMIN_EMAIL and SPEEDAT_ADMIN_PASSWORD to sign in");
      const page = t.page;
      await page.goto(t.url("/admin/login"), { waitUntil: "domcontentloaded" });
      await page.fill('input[type=email], input[name=email]', creds.email);
      await page.fill("input[type=password]", creds.password);
      await page.click("button[type=submit], input[type=submit]");
      await page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {});
      await page.waitForTimeout(400);

      const code = await page.$('input[name=code], input[autocomplete="one-time-code"], input[inputmode=numeric]');
      const cookies = await t.context.cookies();
      if (!code) {
        if (cookies.find((c) => c.name === "speedat_sid")) {
          /*
           * TWO-FACTOR IS PER ACCOUNT, NOT PER SITE (fixed 2026-09-29).
           *
           * This threw "the second factor is required on both paths, and it
           * was not asked for" whenever the account it signed in with had 2FA
           * off - which is every account until somebody turns it on under
           * Security. Http\Admin\LoginController asks for a code when
           * `admins.totp_enabled` is 1 for that row and completes the sign-in
           * when it is not; both are correct behaviour, and the harness cannot
           * tell which account it was given.
           *
           * So an account without 2FA is NOT READY with the reason named, and
           * the three checks after this one already say "the previous check did
           * not get that far". A code prompt that appears and then does not
           * behave is still a hard failure, below.
           */
          t.notReady(
            "this account signed in with a password alone, so admins.totp_enabled is 0 for it. "
              + "That is the documented behaviour, not a defect - turn two-factor on for "
              + (creds.email || "the account") + " under Security, set SPEEDAT_ADMIN_TOTP_SECRET, "
              + "and this check and the three after it will run.",
          );
        }
        throw new Error("the correct password produced neither a code prompt nor a session; check SPEEDAT_ADMIN_PASSWORD");
      }
      const max = await code.getAttribute("maxlength");
      if (max && Number(max) !== 6) t.log("the code field's maxlength is " + max + ", not 6");
      await t.shot("code-prompt");
      return "a code is required after the password";
    });

    await t.check("a wrong code is refused", async () => {
      const page = t.page;
      const code = await page.$('input[name=code], input[autocomplete="one-time-code"], input[inputmode=numeric]');
      t.need(code, "no code prompt on the page (the previous check did not get that far)");
      await code.fill("000000");
      await page.click("button[type=submit], input[type=submit]");
      await page.waitForLoadState("domcontentloaded", { timeout: 15000 }).catch(() => {});
      await page.waitForTimeout(400);
      const cookies = await t.context.cookies();
      if (cookies.find((c) => c.name === "speedat_sid")) throw new Error('the code "000000" was accepted');
      return "refused";
    });

    let firstCode = null;

    await t.check("the right code signs in, and the session cookie is safe", async () => {
      t.need(creds.totpSecret, "set SPEEDAT_ADMIN_TOTP_SECRET to finish the sign-in");
      const page = t.page;

      // The wrong code may have sent us back to the start.
      let code = await page.$('input[name=code], input[autocomplete="one-time-code"], input[inputmode=numeric]');
      if (!code) {
        await page.goto(t.url("/admin/login"), { waitUntil: "domcontentloaded" });
        await page.fill('input[type=email], input[name=email]', creds.email);
        await page.fill("input[type=password]", creds.password);
        await page.click("button[type=submit], input[type=submit]");
        await page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {});
        code = await page.$('input[name=code], input[autocomplete="one-time-code"], input[inputmode=numeric]');
      }
      t.need(code, "could not get back to the code prompt");

      // freshTotp, not totp: the "wrong code" check above may have sent us
      // round again, and everything after this flow signs in too. A code from a
      // step this account has already spent is refused, correctly, and reads
      // as "the right code did not produce a session".
      firstCode = await freshTotp(creds.totpSecret);
      await code.fill(firstCode);
      await page.click("button[type=submit], input[type=submit]");
      await page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {});
      await page.waitForTimeout(500);

      const cookies = await t.context.cookies();
      const session = cookies.find((c) => c.name === "speedat_sid");
      if (!session) {
        const said = await page.evaluate(() =>
          Array.from(document.querySelectorAll(".error, .alert, [role=alert]")).map((e) => (e.textContent || "").trim()).join(" | "),
        );
        throw new Error("the right code did not produce a session" + (said ? " - the page says: " + said : "") + " (is the server clock right?)");
      }

      const problems = [];
      if (!session.httpOnly) problems.push("the session cookie is not HttpOnly, so any script can read it");
      if (!/Lax|Strict/i.test(String(session.sameSite))) problems.push("SameSite is " + session.sameSite);
      if (t.base.startsWith("https://") && !session.secure) problems.push("the cookie is not Secure on an HTTPS site");
      if (problems.length) throw new Error(["the session cookie:", ...problems.map((p) => "    " + p)].join("\n"));

      await t.shot("signed-in");
      return "signed in; cookie HttpOnly, SameSite=" + session.sameSite + (session.secure ? ", Secure" : "");
    });

    await t.check("the same code cannot be used twice", async () => {
      t.need(firstCode, "no code was used successfully, so there is nothing to replay");
      const fresh = await t.browser.newContext({ ignoreHTTPSErrors: true });
      try {
        const page = await fresh.newPage();
        await page.goto(t.url("/admin/login"), { waitUntil: "domcontentloaded" });
        await page.fill('input[type=email], input[name=email]', creds.email);
        await page.fill("input[type=password]", creds.password);
        await page.click("button[type=submit], input[type=submit]");
        await page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {});
        const code = await page.$('input[name=code], input[autocomplete="one-time-code"], input[inputmode=numeric]');
        t.need(code, "the second sign-in never reached a code prompt");
        await code.fill(firstCode);
        await page.click("button[type=submit], input[type=submit]");
        await page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {});
        await page.waitForTimeout(400);
        const cookies = await fresh.cookies();
        if (cookies.find((c) => c.name === "speedat_sid")) {
          throw new Error("the code " + firstCode + " worked a second time - there is no replay guard");
        }
        return "a spent code is refused";
      } finally {
        await fresh.close();
      }
    });

    await t.check("the admin is now reachable, and says who is signed in", async () => {
      const res = await t.page.goto(t.url("/admin"), { waitUntil: "domcontentloaded" });
      if (!res || res.status() !== 200) throw new Error("/admin answered " + (res ? res.status() : "nothing") + " with a session");
      const body = await t.page.content();
      if (/sign in|password/i.test(await t.page.title())) throw new Error("/admin still shows the login page");
      if (creds.email && !body.includes(creds.email.split("@")[0])) {
        t.log("the admin page does not name the signed-in user; not a failure, but worth a look");
      }
      await t.shot("admin-home");
      return "/admin renders for the signed-in admin";
    });

    await t.check("the login form works with JavaScript off", async () =>
      contract.submitsWithoutJavaScript(t, "/admin/login", { formSelector: "form[method=post]" }),
    );

    await t.check("logging out revokes the session", async () => {
      const page = t.page;
      const cookiesBefore = await t.context.cookies();
      const sid = (cookiesBefore.find((c) => c.name === "speedat_sid") || {}).value;
      t.need(sid, "no session to log out of");

      const logout = page.locator("a, button").filter({ hasText: /log ?out|sign ?out/i }).first();
      if (await logout.count()) {
        await logout.click();
        await page.waitForLoadState("domcontentloaded", { timeout: 15000 }).catch(() => {});
      } else {
        const token = await page.getAttribute('input[name="_token"]', "value").catch(() => null);
        const res = await t.fetch("/admin/logout", {
          method: "POST",
          headers: {
            "content-type": "application/x-www-form-urlencoded",
            cookie: cookiesBefore.map((c) => c.name + "=" + c.value).join("; "),
          },
          body: new URLSearchParams({ _token: token || "" }).toString(),
        });
        t.need(res.ok && res.status !== 404, "no log out link and /admin/logout answers 404");
      }

      // The old cookie value must not work any more, even if the browser kept it.
      const res = await t.fetch("/admin", { headers: { cookie: "speedat_sid=" + sid } });
      if (res.ok && res.status === 200 && !/sign in|password/i.test(res.body)) {
        throw new Error("the old session id still opens /admin after logging out - the session was dropped client side only");
      }
      return "the old session id no longer opens /admin";
    });

    /* ---- last, on purpose ------------------------------------------- *
     *
     * THIS CHECK SPENDS THE SIGN-IN BUDGET EVERYTHING ELSE NEEDS, so it runs
     * after every check that has to sign in - here, and in the four admin
     * flows behind this one (orders 21 to 24).
     *
     * It deliberately fails a sign-in eleven times. The per-account bucket is
     * ten attempts per fifteen minutes and the per-address bucket is four
     * times that, so run this in the middle of the flow and the checks after
     * it are not testing the product any more, they are testing a 429. That
     * is exactly what the release gate cannot afford: `node run.cjs --strict`
     * is meant to walk the whole site in one pass.
     *
     * It gets its own throwaway address, so the burst lands in a bucket
     * nothing else uses; only the per-address bucket is shared, and this
     * spends about eleven of its forty. Measured on 2026-09-28: a complete
     * twelve-flow run reaches 22 of 40. That is one run's worth of headroom
     * and no more, so do not run the whole gate twice inside fifteen minutes
     * and expect the second one to mean anything.
     *
     * If you add a check that signs in, add it ABOVE this one.
     */
    await t.check("repeated failures are rate limited", async () => {
      const email = "ratelimit-probe-" + Date.now() + "@example.invalid";
      let limited = null;
      let spent = 0;
      for (let i = 0; i < 12; i += 1) {
        // eslint-disable-next-line no-await-in-loop
        const page = await t.context.newPage();
        // eslint-disable-next-line no-await-in-loop
        await page.goto(t.url("/admin/login"), { waitUntil: "domcontentloaded" });
        // eslint-disable-next-line no-await-in-loop
        const token = await page.getAttribute('input[name="_token"]', "value").catch(() => null);
        // eslint-disable-next-line no-await-in-loop
        const cookies = await t.context.cookies();
        // eslint-disable-next-line no-await-in-loop
        await page.close();

        spent += 1;
        // eslint-disable-next-line no-await-in-loop
        const res = await t.fetch("/admin/login/password", {
          method: "POST",
          headers: {
            "content-type": "application/x-www-form-urlencoded",
            cookie: cookies.map((c) => c.name + "=" + c.value).join("; "),
          },
          body: new URLSearchParams({ _token: token || "", email, password: "wrong-" + i }).toString(),
        });
        if (!res.ok) continue;
        if (res.status === 429 || /too many|try again|slow down|locked/i.test(res.body)) {
          limited = { at: i + 1, status: res.status };
          break;
        }
        if (res.status === 404) t.notReady("/admin/login/password answers 404 - the password path has a different URL than assumed");
      }
      if (!limited) {
        throw new Error("twelve wrong passwords in a row drew no rate limit; the login is a free brute-force target");
      }
      return "limited after " + limited.at + " attempts (" + limited.status + "), " + spent + " spent from the per-address bucket";
    });

    await t.check("signing in through Google end to end", async () => {
      t.notReady(
        "a real Google sign-in needs a real Google account in a real consent screen, which this harness deliberately does not automate. " +
          "Forged tokens are refused (checked above); the successful path stays a manual check before release.",
      );
    });

    await t.check("the browser stayed quiet over the whole flow", async () =>
      contract.noConsoleErrors(t, { allow: [/401|403|429/] }),
    );
  },
};
