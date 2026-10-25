"use strict";

/**
 * The machine endpoints and the things that must never be reachable.
 *
 * This flow runs first and needs no page to have shipped: it is the answer to
 * "is this deployment sane?". It uses plain HTTP rather than the browser for
 * most checks, because what matters is the bytes the server sends, not what
 * Chrome makes of them.
 *
 * Owner's bar: "should not have any security loopholes or leakages." Each check
 * below is one way that promise gets broken in practice.
 */

const contract = require("../contract.cjs");
const { adminCredentials } = require("../harness.cjs");

/** Paths that must never return a file, whatever .htaccess does or does not do. */
const MUST_NOT_SERVE = [
  "/.env",
  "/.env.local",
  "/router.php",
  "/config/config.php",
  "/../config/config.php",
  "/app/Core/Db.php",
  "/storage/logs/app.log",
  "/db/migrations/0001_init.sql",
  "/.git/config",
  "/composer.json",
  "/README.md",
];

module.exports = {
  name: "ops-endpoints",
  order: 1,
  title: "health, metrics, headers, and everything that must stay unreachable",
  viewport: "desktop",

  async run(t) {
    const creds = adminCredentials();

    /* ---- health ---------------------------------------------------- */

    await t.check("GET /health answers ok and says nothing else", async () => {
      const res = await t.fetch("/health");
      if (!res.ok) throw new Error("/health did not answer: " + res.error);
      if (res.status !== 200) throw new Error("/health answered " + res.status + ", expected 200");

      let body;
      try {
        body = JSON.parse(res.body);
      } catch (e) {
        throw new Error("/health did not answer JSON: " + res.body.slice(0, 120));
      }
      if (body.ok !== true) throw new Error('/health did not say {"ok":true}: ' + res.body.slice(0, 120));

      // A health endpoint that names the broken component is a map for whoever
      // is deciding what to attack. The detail belongs behind the token.
      const leaked = Object.keys(body).filter((k) => k !== "ok");
      if (leaked.length) {
        throw new Error("/health leaks " + leaked.join(", ") + " to anyone who asks: " + res.body.slice(0, 200));
      }
      return "200, {\"ok\":true}, nothing more";
    });

    await t.check("/health is never cached", async () => {
      const res = await t.fetch("/health");
      const cc = String((res.headers || {})["cache-control"] || "");
      if (!/no-store/i.test(cc)) throw new Error("Cache-Control on /health is " + JSON.stringify(cc) + " - a cached health check is not a health check");
      return cc;
    });

    await t.check("a wrong metrics token still gets the thin answer", async () => {
      const res = await t.fetch("/health?token=" + encodeURIComponent("not-the-token-" + Date.now()));
      if (!res.ok) throw new Error("/health did not answer: " + res.error);
      if (res.status !== 200) throw new Error("answered " + res.status);
      const body = JSON.parse(res.body);
      const leaked = Object.keys(body).filter((k) => k !== "ok");
      if (leaked.length) throw new Error("a wrong token still revealed " + leaked.join(", "));
      return "wrong token reveals nothing, and does not say it was wrong";
    });

    await t.check("the right metrics token reveals the detail", async () => {
      t.need(creds.metricsToken, "set SPEEDAT_METRICS_TOKEN to check the detailed health answer");
      const res = await t.fetch("/health?token=" + encodeURIComponent(creds.metricsToken));
      if (!res.ok) throw new Error("/health did not answer: " + res.error);
      const body = JSON.parse(res.body);
      for (const key of ["php", "env", "database", "time"]) {
        if (!(key in body)) throw new Error("the detailed answer has no " + key + ": " + res.body.slice(0, 200));
      }
      if (body.database !== "up") throw new Error("the database is " + body.database);
      return "php " + body.php + ", env " + body.env + ", database " + body.database;
    });

    /* ---- metrics and cron ------------------------------------------- */

    await t.check("/api/metrics refuses an anonymous caller", async () => {
      const res = await t.fetch("/api/metrics");
      if (!res.ok) throw new Error("no answer: " + res.error);
      t.need(res.status !== 404, "/api/metrics answers 404 - the metrics endpoint has not shipped");
      if (res.status === 200) {
        throw new Error("/api/metrics answered 200 with no bearer token, body starts: " + res.body.slice(0, 160));
      }
      if (![401, 403].includes(res.status)) {
        throw new Error("/api/metrics answered " + res.status + "; expected 401 or 403");
      }
      return res.status + " without a bearer token";
    });

    await t.check("/api/metrics refuses a wrong bearer token", async () => {
      const probe = await t.fetch("/api/metrics");
      t.need(probe.ok && probe.status !== 404, "/api/metrics has not shipped");
      const res = await t.fetch("/api/metrics", { headers: { authorization: "Bearer wrong-" + Date.now() } });
      if (res.status === 200) throw new Error("a wrong bearer token was accepted");
      return res.status + " for a wrong token";
    });

    await t.check("/ops/opcache-reset refuses an anonymous caller", async () => {
      const res = await t.fetch("/ops/opcache-reset");
      if (!res.ok) throw new Error("no answer: " + res.error);
      t.need(res.status !== 404, "/ops/opcache-reset answers 404 - it arrives with the admin phase");
      if (res.status === 200) throw new Error("the opcache reset ran for an anonymous caller");
      return res.status + " without a token";
    });

    /* ---- headers ---------------------------------------------------- */

    await t.check("the security headers are on the home page", async () => {
      const res = await t.fetch("/");
      if (!res.ok) throw new Error("no answer: " + res.error);
      const h = res.headers || {};
      const problems = [];

      if (!/nosniff/i.test(String(h["x-content-type-options"] || ""))) problems.push("no X-Content-Type-Options: nosniff");

      const frame = String(h["x-frame-options"] || "");
      const csp = String(h["content-security-policy"] || "");
      if (!frame && !/frame-ancestors/i.test(csp)) problems.push("neither X-Frame-Options nor CSP frame-ancestors");

      if (!/strict-origin/i.test(String(h["referrer-policy"] || ""))) problems.push("Referrer-Policy is " + JSON.stringify(String(h["referrer-policy"] || "")));

      const pp = String(h["permissions-policy"] || "");
      for (const feature of ["geolocation", "microphone", "camera"]) {
        if (!new RegExp(feature + "\\s*=\\s*\\(\\s*\\)").test(pp)) problems.push(feature + " is not denied by Permissions-Policy");
      }

      if (!csp) problems.push("no Content-Security-Policy");
      else {
        if (!/default-src\s+'self'/.test(csp)) problems.push("CSP has no default-src 'self'");
        if (/script-src[^;]*'unsafe-inline'/.test(csp)) problems.push("CSP allows unsafe-inline scripts");
        if (!/'nonce-/.test(csp)) problems.push("CSP has no per-request nonce");
      }

      if (h["x-powered-by"]) problems.push("X-Powered-By: " + h["x-powered-by"] + " - expose_php is on");
      if (/PHP\/\d/.test(String(h.server || ""))) problems.push("Server: " + h.server + " names the PHP version");

      if (problems.length) throw new Error(["the home page's headers:", ...problems.map((p) => "    " + p)].join("\n"));
      return "nosniff, frame-ancestors, referrer, permissions, CSP with a nonce, no version banner";
    });

    await t.check("the CSP nonce is fresh on every request", async () => {
      const a = await t.fetch("/");
      const b = await t.fetch("/");
      const nonce = (s) => ((String((s.headers || {})["content-security-policy"] || "").match(/'nonce-([^']+)'/) || [])[1] || "");
      const one = nonce(a);
      const two = nonce(b);
      t.need(one, "no nonce in the CSP to compare");
      if (one === two) throw new Error("two requests got the same nonce (" + one + ") - a reused nonce is no nonce");
      return "two requests, two nonces";
    });

    /* ---- what must not be served ------------------------------------ */

    await t.check("nothing above the web root is reachable", async () => {
      const served = [];
      for (const p of MUST_NOT_SERVE) {
        const res = await t.fetch(p);
        if (!res.ok) continue;
        // 200 is the finding. A 403 or 404 is correct; a 301 to the home page
        // is acceptable; anything that returns the file is not.
        if (res.status === 200 && res.body.length > 0) {
          served.push("    " + p + " → 200, " + res.body.length + " bytes: " + JSON.stringify(res.body.slice(0, 60)));
        }
      }
      if (served.length) {
        throw new Error(["STOP EVERYTHING - these are being served:", ...served].join("\n"));
      }
      return MUST_NOT_SERVE.length + " paths, none of them served";
    });

    await t.check("a directory does not list itself", async () => {
      for (const dir of ["/assets/", "/assets/css/", "/assets/js/"]) {
        const res = await t.fetch(dir);
        if (res.ok && res.status === 200 && /<title>Index of|Parent Directory/i.test(res.body)) {
          throw new Error(dir + " returns a directory listing");
        }
      }
      return "no directory listings";
    });

    /* ---- the 404 itself --------------------------------------------- */

    await t.check("a missing page returns 404, not 200", async () => {
      const res = await t.fetch("/definitely-not-a-page-" + Date.now());
      if (!res.ok) throw new Error("no answer: " + res.error);
      if (res.status !== 404) {
        throw new Error(
          "a missing page answered " + res.status + ". A soft 404 gets the whole site indexed as duplicate content.",
        );
      }
      if (/stack trace|Fatal error|SQLSTATE|\/var\/www|D:\\\\/i.test(res.body)) {
        throw new Error("the 404 page leaks a path or a trace");
      }
      return "404, and it leaks nothing";
    });

    await t.check("the 404 page wears the site's own layout", async () => {
      const res = await t.fetch("/definitely-not-a-page-" + Date.now());
      t.need(res.ok && res.status === 404, "no 404 to look at");
      const bare = res.body.length < 400 && !/<header|site-header|<nav/i.test(res.body);
      if (bare) {
        t.notReady("the 404 is the server's default, not the site's - the error views arrive with the site pages");
      }
      if (!/<html/i.test(res.body)) throw new Error("the 404 is not even HTML: " + res.body.slice(0, 120));
      return "the site's layout, " + res.body.length + " bytes";
    });

    /* ---- the browser's view ----------------------------------------- */

    await t.check("the home page loads in Chrome with no console error", async () => {
      await t.open("/");
      await t.page.waitForTimeout(500);
      await t.shot("home");
      return contract.noConsoleErrors(t);
    });

    await t.check("the browser's implicit favicon request is answered", async () => {
      // Chrome asks for /favicon.ico whether or not the page mentions one, and
      // logs a console error when it 404s. Either declare an icon or serve the
      // file at the root; leaving it is a 404 on every single page view.
      const declared = await t.page.evaluate(() => {
        const el = document.querySelector("link[rel~='icon']");
        return el ? el.getAttribute("href") : null;
      });
      const root = await t.fetch("/favicon.ico");
      if (declared) {
        const res = await t.fetch(declared);
        if (!res.ok || res.status !== 200) throw new Error("the page declares " + declared + " and it answers " + (res.ok ? res.status : res.error));
        return "declared: " + declared;
      }
      if (!root.ok || root.status !== 200) {
        throw new Error(
          "the page declares no <link rel=\"icon\"> and /favicon.ico answers " + (root.ok ? root.status : root.error) +
            ", so every page view logs a 404 in the console",
        );
      }
      return "/favicon.ico is served at the root";
    });

    await t.check("every asset the page asks for actually exists", async () => {
      const refs = await t.page.evaluate(() =>
        Array.from(document.querySelectorAll("link[href], script[src], img[src]"))
          .map((el) => el.getAttribute("href") || el.getAttribute("src"))
          .filter((u) => u && !/^(data|https?):/i.test(u)),
      );
      t.need(refs.length, "the page references no local assets yet");
      const bad = [];
      for (const ref of refs.slice(0, 40)) {
        const res = await t.fetch(ref);
        if (!res.ok || res.status !== 200) bad.push("    " + ref + " → " + (res.ok ? res.status : res.error));
      }
      if (bad.length) throw new Error(["assets the page asks for and does not get:", ...bad].join("\n"));
      return refs.length + " local assets, all 200";
    });

    await t.check("assets are cache-busted", async () => {
      const refs = await t.page.evaluate(() =>
        Array.from(document.querySelectorAll("link[rel=stylesheet][href], script[src]"))
          .map((el) => el.getAttribute("href") || el.getAttribute("src"))
          .filter((u) => u && !/^https?:/i.test(u)),
      );
      t.need(refs.length, "no stylesheets or scripts on the page yet");
      const unbusted = refs.filter((u) => !/[?&]v=\d+/.test(u));
      if (unbusted.length) {
        throw new Error(
          ["these assets have no ?v= fingerprint, so a deploy will serve stale files:", ...unbusted.map((u) => "    " + u)].join("\n"),
        );
      }
      return refs.length + " assets, every one fingerprinted";
    });
  },
};
