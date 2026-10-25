"use strict";

/**
 * The shared helper module for the browser flows.
 *
 * Everything that is not a statement about the product lives here: finding
 * Playwright, deciding whether the site is even up, launching Chrome, making a
 * context per viewport, catching console noise, writing screenshots, measuring
 * the three numbers that decide whether the price "lands" (click-to-price
 * milliseconds, layout shift, scroll movement), and turning a flow's checks
 * into a report with an exit code.
 *
 * Three verdicts, never two:
 *
 *   PASS      the check ran and the contract held.
 *   FAIL      the check ran and the contract is broken. Someone has to fix it.
 *   NOT READY the check could not run because the surface it tests has not
 *             shipped yet, and it says which surface. This is the verdict that
 *             keeps the harness honest while the port is half-built: a flow
 *             that cannot run must never print a green tick.
 *
 * A flow marks NOT READY by throwing NotReady (via t.need / t.notReady). It
 * fails by throwing anything else. That is the whole protocol.
 *
 * No dependencies beyond Playwright, which is deliberately NOT required from
 * this directory - see resolvePlaywright(). Nothing here is installed by the
 * repository and nothing here runs on the Hostinger host.
 */

const fs = require("fs");
const path = require("path");
const http = require("http");
const https = require("https");
const crypto = require("crypto");

const VIEWPORTS = {
  desktop: { width: 1366, height: 768, isMobile: false, hasTouch: false },
  phone: { width: 375, height: 812, isMobile: true, hasTouch: true },
};

const SHOTS_DIR = path.join(__dirname, "shots");

/** The smallest text the quality bar allows anywhere on a page. */
const MIN_FONT_PX = 14;

/* ==================================================================== *
 * Verdicts
 * ==================================================================== */

/**
 * Thrown when a check cannot run yet. Carries the reason in the operator's
 * words ("/contact answers 404 - the contact page has not shipped"), because
 * "skipped" with no reason is how a half-built site gets called finished.
 */
class NotReady extends Error {
  constructor(message) {
    super(message);
    this.name = "NotReady";
    this.notReady = true;
  }
}

/** Thrown by the preflight when the base URL is not answering at all. */
class SiteDown extends Error {
  constructor(message) {
    super(message);
    this.name = "SiteDown";
    this.siteDown = true;
  }
}

/* ==================================================================== *
 * Finding Playwright
 * ==================================================================== */

/**
 * Playwright is a developer tool, not a dependency of this project: php/ has
 * no package.json, no node_modules and no build step, because the host it
 * deploys to has none either. So the harness borrows an existing Playwright
 * install rather than adding one.
 *
 * Search order, first hit wins:
 *   1. $SPEEDAT_PW_HOME            - a directory containing node_modules/playwright
 *   2. $NODE_PATH entries
 *   3. every ancestor of this file  - node_modules/playwright
 *   4. every ancestor of the cwd    - node_modules/playwright
 *   5. a bare require("playwright") - a global install
 */
function resolvePlaywright() {
  const tried = [];

  const candidates = [];
  const pushHome = (dir) => {
    if (dir) candidates.push(path.join(dir, "node_modules", "playwright"));
  };

  pushHome(process.env.SPEEDAT_PW_HOME);
  for (const entry of (process.env.NODE_PATH || "").split(path.delimiter)) {
    if (entry) candidates.push(path.join(entry, "playwright"));
  }
  for (const start of [__dirname, process.cwd()]) {
    let dir = start;
    for (;;) {
      candidates.push(path.join(dir, "node_modules", "playwright"));
      const up = path.dirname(dir);
      if (up === dir) break;
      dir = up;
    }
  }

  for (const candidate of candidates) {
    tried.push(candidate);
    try {
      if (fs.existsSync(path.join(candidate, "package.json"))) {
        // eslint-disable-next-line global-require
        return require(candidate);
      }
    } catch (e) {
      /* keep looking */
    }
  }

  try {
    // eslint-disable-next-line global-require
    return require("playwright");
  } catch (e) {
    const lines = [
      "Playwright was not found.",
      "",
      "This harness borrows an existing Playwright install; php/ deliberately has",
      "no node_modules. Point it at one:",
      "",
      "    SPEEDAT_PW_HOME=/path/to/dir/containing/node_modules  node run.cjs",
      "",
      "or run node from a directory that has node_modules/playwright above it.",
      "See php/docs/browser-tests.md.",
      "",
      "Looked in:",
      ...tried.slice(0, 12).map((t) => "  " + t),
    ];
    throw new Error(lines.join("\n"));
  }
}

/* ==================================================================== *
 * Base URL and preflight
 * ==================================================================== */

/**
 * Where to point the harness. An argument wins over the environment, the
 * environment wins over the local dev server, and there is no silent default
 * that could send a staging run at localhost.
 */
function resolveBaseUrl(explicit) {
  const raw =
    explicit ||
    process.env.SPEEDAT_BASE_URL ||
    process.env.BASE_URL ||
    "http://127.0.0.1:8081";

  let url = String(raw).trim();
  if (!/^https?:\/\//i.test(url)) url = "http://" + url;
  return url.replace(/\/+$/, "");
}

/** A single HTTP request with no dependencies. Never throws; reports instead. */
function fetchRaw(url, { method = "GET", timeout = 15000, headers = {}, body = null } = {}) {
  return new Promise((resolve) => {
    let target;
    try {
      target = new URL(url);
    } catch (e) {
      resolve({ ok: false, error: "not a URL: " + url });
      return;
    }
    const mod = target.protocol === "https:" ? https : http;
    const req = mod.request(
      target,
      {
        method,
        headers,
        // Staging is reached over an SSH tunnel and may present whatever
        // certificate the container has. This is a test harness talking to a
        // host the operator chose on the command line, not a browser.
        rejectUnauthorized: false,
        timeout,
      },
      (res) => {
        const chunks = [];
        let bytes = 0;
        res.on("data", (c) => {
          bytes += c.length;
          // 4 MB is far more than any page here, and stops a runaway body from
          // eating the runner.
          if (bytes <= 4 * 1024 * 1024) chunks.push(c);
        });
        res.on("end", () =>
          resolve({
            ok: true,
            status: res.statusCode || 0,
            headers: res.headers,
            body: Buffer.concat(chunks).toString("utf8"),
          }),
        );
      },
    );
    req.on("timeout", () => {
      req.destroy();
      resolve({ ok: false, error: "timed out after " + timeout + "ms" });
    });
    req.on("error", (e) => resolve({ ok: false, error: e.code ? e.code + " " + e.message : e.message }));
    if (body !== null) req.write(body);
    req.end();
  });
}

/**
 * Is the site up at all, and which parts of it exist?
 *
 * This runs before Chrome launches, for two reasons. It is the difference
 * between "the site is down" and "the site is wrong", which the operator must
 * never have to guess at; and a dead base URL should cost a second, not the
 * thirty it takes to start a browser and time out twelve flows.
 */
async function preflight(base) {
  const root = await fetchRaw(base + "/", { timeout: 20000 });

  if (!root.ok) {
    throw new SiteDown(
      [
        "THE SITE IS NOT UP.",
        "",
        "  base URL : " + base,
        "  error    : " + root.error,
        "",
        "Nothing was tested. Start the site first:",
        "",
        "  local    : powershell -ExecutionPolicy Bypass -File php/bin/local/serve.ps1",
        "             (serves http://127.0.0.1:8081)",
        "  staging  : ssh -N -L 8085:127.0.0.1:8085 admin_tf@110.93.205.230",
        "             then --base http://127.0.0.1:8085",
      ].join("\n"),
    );
  }

  // A server that answers, but answers 5xx to its own home page, is down as
  // far as a flow is concerned.
  if (root.status >= 500) {
    throw new SiteDown(
      [
        "THE SITE IS ANSWERING " + root.status + " ON ITS OWN HOME PAGE.",
        "",
        "  base URL : " + base,
        "",
        "That is a broken deployment, not a failing test. Nothing was tested.",
        root.body ? "First 400 bytes of the answer:\n" + root.body.slice(0, 400) : "",
      ].join("\n"),
    );
  }

  const paths = [
    "/",
    "/services",
    "/about",
    "/contact",
    "/faq",
    "/sitemap.xml",
    "/robots.txt",
    "/admin",
    "/admin/login",
    "/api/quotes",
    "/api/metrics",
    "/health",
  ];

  const status = {};
  const bodies = {};
  await Promise.all(
    paths.map(async (p) => {
      const res = p === "/" ? root : await fetchRaw(base + p, { timeout: 15000 });
      status[p] = res.ok ? res.status : 0;
      bodies[p] = res.ok ? res.body : "";
    }),
  );

  const home = bodies["/"] || "";

  return {
    base,
    status,
    bodies,
    /**
     * Markers: the cheapest honest signal that a surface has shipped. Each one
     * is a thing the finished page must contain and the placeholder cannot
     * accidentally contain.
     */
    has: {
      calculator: /\[?data-calculator/.test(home) || /data-calculator/.test(home),
      holdPanel: /data-hold-panel/.test(home),
      // The SEO partial prints all of these together; any one of them means it
      // shipped and the rest are then real assertions rather than absences.
      seo: /rel=["']canonical["']/.test(home) || /property=["']og:title["']/.test(home) || /application\/ld\+json/.test(home),
      pricingJs: /assets\/js\/pricing\.js/.test(home),
      calculatorJs: /assets\/js\/calculator\.js/.test(home),
    },
    ready(p) {
      return status[p] === 200;
    },
  };
}

/* ==================================================================== *
 * The flow context
 * ==================================================================== */

/**
 * What a flow is handed. One per flow, holding its browser context, its page,
 * its console log and its results.
 */
class FlowContext {
  constructor({ flow, base, browser, probe, options }) {
    this.flow = flow;
    this.base = base;
    this.browser = browser;
    this.probe = probe;
    this.options = options;
    this.results = [];
    this.notes = [];
    this.console = [];
    this.pageErrors = [];
    this.failedRequests = [];
    this.badResponses = [];
    // 4xx/5xx on a NAVIGATION, kept apart from badResponses - see the response
    // listener below for why they are explained rather than failed on.
    this.documentResponses = [];
    this.context = null;
    this.page = null;
    this._shotCount = 0;
  }

  /** An operator-facing note that is not a pass or a fail. */
  log(message) {
    this.notes.push(String(message));
    if (this.options.verbose) process.stdout.write("      · " + message + "\n");
  }

  url(p) {
    if (/^https?:\/\//i.test(p)) return p;
    return this.base + (p.startsWith("/") ? p : "/" + p);
  }

  /**
   * A plain HTTP request, outside the browser. This is what the SEO checks
   * read: the bytes the server sent, before any script could add a canonical
   * tag that a crawler would never see.
   */
  fetch(p, options) {
    return fetchRaw(this.url(p), options);
  }

  /* ---- browser plumbing ------------------------------------------- */

  /**
   * Open a context at one of the two viewports the product is designed for and
   * start listening. Console errors and page errors are collected for the
   * whole flow, because "no console error" is a contract, not a check you
   * remember to make at the end.
   */
  async openContext(viewportName, extra = {}) {
    const vp = VIEWPORTS[viewportName];
    if (!vp) throw new Error("unknown viewport: " + viewportName);

    this.context = await this.browser.newContext({
      viewport: { width: vp.width, height: vp.height },
      isMobile: vp.isMobile,
      hasTouch: vp.hasTouch,
      deviceScaleFactor: 1,
      ignoreHTTPSErrors: true,
      baseURL: this.base,
      ...extra,
    });
    this.viewportName = viewportName;
    this.viewport = vp;

    this.page = await this.context.newPage();
    this.watch(this.page);
    return this.page;
  }

  /** Attach the listeners. Every page in the flow gets them, not just the first. */
  watch(page) {
    page.on("console", (msg) => {
      const type = msg.type();
      if (type === "error" || type === "warning") {
        this.console.push({ type, text: msg.text(), url: page.url() });
      }
    });
    page.on("pageerror", (err) => {
      this.pageErrors.push({ text: String(err && err.message ? err.message : err), url: page.url() });
    });
    page.on("requestfailed", (req) => {
      // Aborted navigations to WhatsApp are expected and uninteresting; a
      // failed asset on our own origin is not.
      const u = req.url();
      if (!u.startsWith(this.base)) return;

      const failure = (req.failure() || {}).errorText || "failed";
      // ERR_ABORTED means the browser cancelled the request itself, which is
      // what happens to an in-flight favicon when a harness navigates as fast
      // as this one does. A genuinely missing file answers 404, and the
      // "every asset exists" check catches that over plain HTTP.
      if (/ERR_ABORTED/i.test(failure)) return;

      this.failedRequests.push({ url: u, failure });
    });

    /**
     * Chrome's console says "Failed to load resource: 404" and does NOT say
     * which resource, which makes the console alone useless for fixing it. So
     * the responses are recorded here with their URLs. Navigations are excluded:
     * a flow visiting a page that is not built yet is the flow working.
     */
    page.on("response", (res) => {
      const u = res.url();
      if (!u.startsWith(this.base)) return;
      if (res.status() < 400) return;
      let type = "";
      try {
        type = res.request().resourceType();
      } catch (e) {
        /* nothing */
      }
      if (type === "document") {
        /*
         * KEPT, SEPARATELY, AND NOT COUNTED AS A SITE PROBLEM (2026-09-29).
         *
         * Chrome logs "Failed to load resource: the server responded with a
         * status of N" for a NAVIGATION too, and never says which URL. Those
         * anonymous lines were the last red check in three flows:
         *
         *   contact-form            three 422s, from the submissions the flow
         *                           deliberately makes the validator refuse
         *   admin-hold              one 405, from a form post the flow makes
         *   admin-settings-content  two 404s, from probing /admin/sections and
         *                           /admin/fields to find which one exists
         *
         * Every one of those navigations is the FLOW's own, and every one is
         * already asserted where it happens: the flow reads the status it got
         * and fails there if it is wrong. Counting them a second time at the
         * end of the flow, with no URL attached, reports the same event in the
         * one place nobody can act on it - which is the definition of a check
         * that cries wolf.
         *
         * So they are recorded WITH their URL and method and handed to
         * contract.noConsoleErrors(), which uses them to explain the anonymous
         * console lines rather than to fail on them, and prints them in its
         * note so they stay visible.
         */
        let method = "";
        try {
          method = res.request().method();
        } catch (e) {
          /* nothing */
        }
        this.documentResponses.push({ url: u, status: res.status(), method: method || "?" });
        return;
      }
      this.badResponses.push({ url: u, status: res.status(), type: type || "?" });
    });
  }

  /**
   * Go to a path. Fails loudly on a 5xx and marks NOT READY on a 404, so a
   * flow never has to write that logic twice.
   */
  async open(p, { expect = 200, waitUntil = "domcontentloaded" } = {}) {
    const res = await this.page.goto(this.url(p), { waitUntil, timeout: 30000 });
    const status = res ? res.status() : 0;
    if (status >= 500) {
      throw new Error(p + " answered " + status + " - the server is erroring, not merely wrong");
    }
    if (expect !== null && status !== expect) {
      if (status === 404) {
        throw new NotReady(p + " answers 404 - that page has not shipped yet");
      }
      throw new Error(p + " answered " + status + ", expected " + expect);
    }
    return res;
  }

  /** A screenshot in php/tests/browser/shots/, named so a failure is findable. */
  async shot(name, { fullPage = false, page = null } = {}) {
    if (this.options.noShots) return null;
    const target = page || this.page;
    if (!target) return null;
    fs.mkdirSync(SHOTS_DIR, { recursive: true });
    this._shotCount += 1;
    const n = String(this._shotCount).padStart(2, "0");
    const file = path.join(
      SHOTS_DIR,
      `${this.flow.name}--${n}-${String(name).replace(/[^a-z0-9_-]+/gi, "-")}.png`,
    );
    try {
      await target.screenshot({ path: file, fullPage });
      return file;
    } catch (e) {
      this.log("screenshot failed (" + name + "): " + e.message);
      return null;
    }
  }

  /* ---- verdicts ---------------------------------------------------- */

  /**
   * One named statement about the product. This is the only way a flow reports
   * anything: every line in the output is a check(), so a flow that forgot to
   * assert something shows as a flow with too few checks rather than a pass.
   */
  async check(name, fn) {
    const started = Date.now();
    try {
      const detail = await fn();
      this.results.push({ name, verdict: "PASS", ms: Date.now() - started, detail: detail || "" });
      return true;
    } catch (e) {
      const ms = Date.now() - started;
      if (e && e.notReady) {
        this.results.push({ name, verdict: "NOT READY", ms, detail: e.message });
        return false;
      }
      this.results.push({
        name,
        verdict: "FAIL",
        ms,
        detail: (e && e.message ? e.message : String(e)).split("\n").slice(0, 12).join("\n"),
      });
      // A failing check inside a flow does not stop the flow: the next check
      // may be about a different surface, and one screenshot's worth of
      // information is worth less than the whole picture.
      await this.shot("FAIL-" + name);
      return false;
    }
  }

  /** Assert a precondition: false means NOT READY, with the reason. */
  need(condition, reason) {
    if (!condition) throw new NotReady(reason);
    return true;
  }

  notReady(reason) {
    throw new NotReady(reason);
  }

  /* ---- measurement -------------------------------------------------- */

  /**
   * Instrument the page, then hand back a reader.
   *
   * Installed BEFORE the interaction; read AFTER it. Between those two moments
   * the page records, in the page's own clock so the number is the visitor's
   * and not Playwright's round trip:
   *
   *   ms      milliseconds from the interaction to the moment the watched
   *           element first held text (click-to-price)
   *   values  every distinct text the watched element held, in order. A price
   *           that counts up produces several; the contract is exactly one.
   *   cls     summed layout-shift score for shifts the visitor did not cause
   *   scroll  how far the page moved the viewport by itself, and the largest
   *           excursion on the way
   *
   * @param {string} watchSelector the element whose text is "the price"
   */
  async measure(watchSelector) {
    const page = this.page;
    await page.evaluate((sel) => {
      const seen = [];
      const state = {
        sel,
        t0: performance.now(),
        firstAt: null,
        values: seen,
        cls: 0,
        shifts: [],
        scrollStart: window.scrollY,
        scrollNow: window.scrollY,
        scrollMax: window.scrollY,
        scrollMin: window.scrollY,
      };
      window.__speedatMeasure = state;

      const read = () => {
        const el = document.querySelector(sel);
        if (!el) return;
        const text = (el.textContent || "").replace(/\s+/g, " ").trim();
        if (!text) return;
        if (seen.length === 0) state.firstAt = performance.now();
        if (seen[seen.length - 1] !== text) seen.push(text);
      };

      state.stop = () => {
        try {
          if (state.mo) state.mo.disconnect();
        } catch (e) {
          /* nothing */
        }
        try {
          if (state.po) state.po.disconnect();
        } catch (e) {
          /* nothing */
        }
      };

      read();
      state.mo = new MutationObserver(read);
      state.mo.observe(document.documentElement, {
        childList: true,
        subtree: true,
        characterData: true,
        attributes: true,
      });

      try {
        state.po = new PerformanceObserver((list) => {
          for (const entry of list.getEntries()) {
            // Only shifts that happened after this observer started, and only
            // ones the visitor did not cause by scrolling or typing.
            if (entry.startTime < state.t0) continue;
            if (entry.hadRecentInput) continue;
            state.cls += entry.value;
            state.shifts.push({ at: Math.round(entry.startTime), value: Math.round(entry.value * 10000) / 10000 });
          }
        });
        // buffered:false on purpose. With buffered:true the observer is handed
        // every shift since navigation, so the page's own load-time reflow gets
        // charged to the tap that produced the price - which is how you end up
        // "fixing" a result slot that was never moving.
        state.po.observe({ type: "layout-shift", buffered: false });
      } catch (e) {
        state.clsUnavailable = true;
      }

      window.addEventListener(
        "scroll",
        () => {
          state.scrollNow = window.scrollY;
          if (window.scrollY > state.scrollMax) state.scrollMax = window.scrollY;
          if (window.scrollY < state.scrollMin) state.scrollMin = window.scrollY;
        },
        { passive: true },
      );
    }, watchSelector);

    const self = this;
    return {
      /** Wait for the watched element to hold text, then read the numbers. */
      async settle({ timeout = 5000, quiet = 350 } = {}) {
        let timedOut = false;
        try {
          await page.waitForFunction(
            () => window.__speedatMeasure && window.__speedatMeasure.firstAt !== null,
            null,
            { timeout },
          );
        } catch (e) {
          timedOut = true;
        }
        // Let a count-up, a re-render or a late shift happen before reading:
        // the point of `values` is to catch exactly that.
        await page.waitForTimeout(quiet);
        const m = await page.evaluate(() => {
          const s = window.__speedatMeasure;
          if (!s) return null;
          if (s.stop) s.stop();
          return {
            ms: s.firstAt === null ? null : Math.round((s.firstAt - s.t0) * 10) / 10,
            values: s.values.slice(),
            cls: Math.round(s.cls * 10000) / 10000,
            shifts: s.shifts.slice(0, 8),
            clsUnavailable: !!s.clsUnavailable,
            scrollStart: s.scrollStart,
            scrollEnd: s.scrollNow,
            scrollMax: s.scrollMax,
            scrollMin: s.scrollMin,
          };
        });
        if (!m) throw new Error("measure(): the instrumentation was lost (did the page navigate?)");
        m.timedOut = timedOut;
        m.scrollDelta = m.scrollEnd - m.scrollStart;
        m.scrollExcursion = Math.max(m.scrollMax - m.scrollStart, m.scrollStart - m.scrollMin);
        m.summary =
          (m.ms === null ? "no text ever appeared" : m.ms + "ms to text") +
          ", " +
          m.values.length +
          " distinct value" +
          (m.values.length === 1 ? "" : "s") +
          ", CLS " +
          (m.clsUnavailable ? "n/a" : m.cls + (m.shifts.length ? " over " + m.shifts.length + " shift(s)" : "")) +
          ", scroll " +
          (m.scrollDelta === 0 ? "unmoved" : m.scrollDelta + "px");
        self.log("measure(" + watchSelector + "): " + m.summary);
        return m;
      },
    };
  }

  async close() {
    if (this.context) {
      try {
        await this.context.close();
      } catch (e) {
        /* nothing */
      }
      this.context = null;
      this.page = null;
    }
  }
}

/* ==================================================================== *
 * The runner
 * ==================================================================== */

function loadFlows() {
  const dir = path.join(__dirname, "flows");
  if (!fs.existsSync(dir)) return [];
  return fs
    .readdirSync(dir)
    .filter((f) => f.endsWith(".cjs"))
    .sort()
    .map((f) => {
      // eslint-disable-next-line global-require
      const mod = require(path.join(dir, f));
      const name = mod.name || f.replace(/\.cjs$/, "");
      if (typeof mod.run !== "function") {
        throw new Error("flows/" + f + " does not export a run() function");
      }
      return { order: 99, ...mod, name, file: "flows/" + f };
    })
    // Explicit order, not alphabetical: the cheap checks that tell you whether
    // the deployment is sane at all run first, so a broken host is obvious
    // before twelve flows time out proving it.
    .sort((a, b) => a.order - b.order || a.name.localeCompare(b.name));
}

/* ==================================================================== *
 * Second factor
 * ==================================================================== */

/**
 * A TOTP code (RFC 6238), from node's own crypto. The admin login needs one and
 * this harness is not allowed a dependency to get it - which is fine, because
 * it is thirty lines and Domain\Auth computes the same thing the same way.
 *
 * @param {string} base32Secret the shared secret as the admin's authenticator holds it
 * @param {number} atMs         the moment to generate for (default: now)
 */
function totp(base32Secret, { atMs = Date.now(), step = 30, digits = 6 } = {}) {
  const clean = String(base32Secret).toUpperCase().replace(/[^A-Z2-7]/g, "");
  if (!clean) throw new Error("totp(): the secret is empty");

  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  let bits = "";
  for (const ch of clean) {
    const idx = alphabet.indexOf(ch);
    if (idx < 0) throw new Error("totp(): " + ch + " is not base32");
    bits += idx.toString(2).padStart(5, "0");
  }
  const bytes = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  const key = Buffer.from(bytes);

  const counter = Math.floor(atMs / 1000 / step);
  const msg = Buffer.alloc(8);
  msg.writeUInt32BE(Math.floor(counter / 0x100000000), 0);
  msg.writeUInt32BE(counter % 0x100000000, 4);

  const hmac = crypto.createHmac("sha1", key).update(msg).digest();
  const offset = hmac[hmac.length - 1] & 0x0f;
  const code =
    ((hmac[offset] & 0x7f) << 24) | ((hmac[offset + 1] & 0xff) << 16) | ((hmac[offset + 2] & 0xff) << 8) | (hmac[offset + 3] & 0xff);

  return String(code % 10 ** digits).padStart(digits, "0");
}

/**
 * The last 30-second step this harness handed a code out for, across every
 * flow in the run. -1 means "none yet".
 */
let lastIssuedStep = -1;

/**
 * A code the server will actually accept, waiting for the next 30-second step
 * when the last one is already spent.
 *
 * WHY THIS EXISTS. The site's replay guard is correct and strict: a step is
 * spent by the sign-in that used it, and `totp_last_step < :step` refuses
 * anything not strictly newer (Admins::spendStep). The flows behind
 * admin-login sign in one after another and take about a second each, so all
 * four landed inside ONE 30-second step, generated the SAME six digits, and
 * the second, third and fourth were refused as replays - "login did not
 * produce a session cookie", four flows dead, in a release gate that is
 * supposed to run start to finish in one go. Measured on 2026-09-28: four
 * consecutive `login_code_refused` at 11:42:39, :40, :41 and :42.
 *
 * Nothing was wrong with the product. A person with an authenticator app waits
 * for the next code without thinking about it; this is that wait, made
 * explicit. The cost is at most one step (30 s) per sign-in that collides, and
 * only when it collides.
 *
 * @param {string} secret the base32 shared secret
 */
async function freshTotp(secret, { step = 30 } = {}) {
  const stepAt = (ms) => Math.floor(ms / 1000 / step);

  let now = Date.now();
  if (stepAt(now) === lastIssuedStep) {
    // +400 ms past the boundary: the server's clock is not this process's, and
    // a code generated one millisecond into a new step is a coin toss.
    const nextBoundary = (lastIssuedStep + 1) * step * 1000 + 400;
    await new Promise((resolve) => setTimeout(resolve, Math.max(0, nextBoundary - now)));
    now = Date.now();
  }

  lastIssuedStep = stepAt(now);
  return totp(secret, { atMs: now });
}

/* ==================================================================== *
 * Getting into the admin
 * ==================================================================== */

/**
 * The credentials the admin flows need, from the environment only.
 *
 * Never from a file in the repository, never a default, never a guess. A
 * harness that ships a working password is a harness that ships a working
 * password to production.
 */
function adminCredentials() {
  return {
    email: process.env.SPEEDAT_ADMIN_EMAIL || "",
    password: process.env.SPEEDAT_ADMIN_PASSWORD || "",
    totpSecret: process.env.SPEEDAT_ADMIN_TOTP_SECRET || "",
    metricsToken: process.env.SPEEDAT_METRICS_TOKEN || "",
    cronToken: process.env.SPEEDAT_CRON_TOKEN || "",
  };
}

/**
 * ONE SIGN-IN PER RUN, REUSED BY EVERY FLOW AFTER IT.
 *
 * WHY THIS EXISTS (2026-09-29). Five flows signed in separately, and
 * admin-login's own "repeated failures are rate limited" check spends six
 * attempts on purpose. LoginController limits per IP as well as per address -
 * `RateLimit::enforce('login', ..., accountAttempts() * 4, 900)` - and every
 * flow comes from 127.0.0.1, so a full run plus anything run before it inside
 * the same fifteen-minute window exhausted the bucket and three flows died
 * with "login did not produce a session cookie". That is the site's brute-force
 * defence working, reported as three crashed flows, and it changed from run to
 * run depending on how recently the suite had last been run - which is worse
 * than a steady failure, because it teaches people the suite is unreliable.
 *
 * Module scope is exactly one run of run.cjs, which is the right lifetime: the
 * cookies never outlive the process and a fresh run always signs in once.
 */
let cachedAdminCookies = null;

/**
 * Sign in with the password path and the second factor, and leave the flow's
 * context holding the session.
 *
 * Reports NOT READY - never FAIL - when the login page has not shipped, the
 * credentials are not in the environment, or the login rate limit has been
 * spent, because none of those is a defect in the product.
 */
async function adminSession(t, { path: loginPath = "/admin/login" } = {}) {
  const creds = adminCredentials();
  t.need(creds.email && creds.password, "set SPEEDAT_ADMIN_EMAIL and SPEEDAT_ADMIN_PASSWORD to exercise the admin");

  // The session from an earlier flow, if it is still good. Verified against a
  // real admin page rather than assumed: a flow that signs out, or a sweep that
  // expires sessions, must send this back through the login.
  if (cachedAdminCookies) {
    await t.context.addCookies(cachedAdminCookies).catch(() => {});
    const still = await t.page
      .goto(t.url("/admin"), { waitUntil: "domcontentloaded", timeout: 30000 })
      .catch(() => null);
    const onLogin = still ? /\/admin\/login/.test(t.page.url()) : true;
    if (still && still.status() === 200 && !onLogin) {
      t.log("reused the admin session signed in earlier in this run (no second login, no rate-limit spend)");
      return { cookies: cachedAdminCookies, usedTotp: false, reused: true };
    }
    cachedAdminCookies = null;
  }

  const probe = await t.fetch(loginPath);
  t.need(probe.ok, "could not reach " + loginPath + ": " + probe.error);
  t.need(probe.status !== 404, loginPath + " answers 404 - the admin login has not shipped");

  const page = t.page;
  await page.goto(t.url(loginPath), { waitUntil: "domcontentloaded", timeout: 30000 });

  const email = await page.$('input[type=email], input[name=email]');
  const password = await page.$('input[type=password], input[name=password]');
  t.need(email && password, "no email/password fields on " + loginPath + " yet");

  await email.fill(creds.email);
  await password.fill(creds.password);
  await page.click('button[type=submit], input[type=submit]');
  await page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {});

  // The second factor, if the site asked for it.
  const code = await page.$('input[name=code], input[autocomplete="one-time-code"], input[inputmode=numeric]');
  if (code) {
    t.need(creds.totpSecret, "the site asked for a 6-digit code; set SPEEDAT_ADMIN_TOTP_SECRET");
    // freshTotp, not totp: four flows signing in one after another all land in
    // the same 30-second step, and the site's replay guard refuses the second
    // and every one after it. See freshTotp above.
    await code.fill(await freshTotp(creds.totpSecret));
    await page.click('button[type=submit], input[type=submit]');
    await page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {});
  }

  const cookies = await t.context.cookies();
  const session = cookies.find((c) => c.name === "speedat_sid");
  if (!session) {
    const body = await page.content();
    const problem = (body.match(/class=["'][^"']*(error|alert)[^"']*["'][^>]*>([^<]{3,120})/i) || [])[2];

    /*
     * RATE-LIMITED IS NOT BROKEN. LoginController limits sign-in attempts per
     * IP over a fifteen-minute window, and admin-login's own rate-limit check
     * spends six of them on purpose. When the bucket is empty the login page
     * refuses correctly and this used to crash the flow with "login did not
     * produce a session cookie" - the defence working, reported as the admin
     * being down. It is NOT READY, and it says how long to wait.
     */
    if (/too many|try again|rate|429|slow down/i.test(body)) {
      t.notReady(
        "the login rate limit is spent, so this flow could not sign in. That is "
          + "LoginController's per-IP window (15 minutes) doing its job - admin-login's own "
          + "rate-limit check deliberately spends six attempts. Wait out the window and run again"
          + (problem ? '; the page says: "' + problem.trim() + '"' : "") + ".",
      );
    }

    throw new Error("login did not produce a session cookie" + (problem ? " - the page says: " + problem.trim() : ""));
  }

  cachedAdminCookies = cookies;
  t.log("signed in as " + creds.email + (code ? " with a 6-digit code" : " (no second factor was asked for)"));
  return { cookies, usedTotp: !!code };
}

const COLOUR =
  process.stdout.isTTY && !process.env.NO_COLOR
    ? { pass: "\x1b[32m", fail: "\x1b[31m", skip: "\x1b[33m", dim: "\x1b[90m", bold: "\x1b[1m", off: "\x1b[0m" }
    : { pass: "", fail: "", skip: "", dim: "", bold: "", off: "" };

function mark(verdict) {
  if (verdict === "PASS") return COLOUR.pass + "  ok  " + COLOUR.off;
  if (verdict === "FAIL") return COLOUR.fail + " FAIL " + COLOUR.off;
  return COLOUR.skip + " n/r  " + COLOUR.off;
}

async function runFlows({ flows, base, probe, browser, options }) {
  const report = [];

  for (const flow of flows) {
    process.stdout.write("\n" + COLOUR.bold + flow.name + COLOUR.off + "  " + COLOUR.dim + (flow.title || "") + COLOUR.off + "\n");

    const t = new FlowContext({ flow, base, browser, probe, options });
    const started = Date.now();
    let fatal = null;

    try {
      await t.run_(flow);
    } catch (e) {
      if (e && e.notReady) {
        // The whole flow could not start. One NOT READY line, with the reason.
        t.results.push({ name: "flow preconditions", verdict: "NOT READY", ms: 0, detail: e.message });
      } else {
        fatal = e;
        t.results.push({
          name: "flow crashed",
          verdict: "FAIL",
          ms: 0,
          detail: (e && e.stack ? e.stack : String(e)).split("\n").slice(0, 8).join("\n"),
        });
      }
    } finally {
      await t.close();
    }

    for (const r of t.results) {
      process.stdout.write(
        mark(r.verdict) + " " + r.name + (r.detail && r.verdict === "PASS" ? COLOUR.dim + "  " + r.detail + COLOUR.off : "") + "\n",
      );
      if (r.detail && r.verdict !== "PASS") {
        for (const line of String(r.detail).split("\n")) {
          process.stdout.write("        " + line + "\n");
        }
      }
    }

    report.push({
      name: flow.name,
      title: flow.title || "",
      ms: Date.now() - started,
      results: t.results,
      console: t.console,
      pageErrors: t.pageErrors,
      failedRequests: t.failedRequests,
      fatal: fatal ? String(fatal.message || fatal) : null,
    });
  }

  return report;
}

/**
 * The flow body runs with the context as `this`-free argument; this indirection
 * exists only so a flow can declare a viewport and have it opened for it.
 */
FlowContext.prototype.run_ = async function run_(flow) {
  if (flow.viewport) await this.openContext(flow.viewport);
  await flow.run(this);
};

function summarise(report) {
  let pass = 0;
  let fail = 0;
  let notReady = 0;
  for (const flow of report) {
    for (const r of flow.results) {
      if (r.verdict === "PASS") pass += 1;
      else if (r.verdict === "FAIL") fail += 1;
      else notReady += 1;
    }
  }
  return { pass, fail, notReady, total: pass + fail + notReady };
}

module.exports = {
  VIEWPORTS,
  MIN_FONT_PX,
  SHOTS_DIR,
  NotReady,
  SiteDown,
  FlowContext,
  resolvePlaywright,
  resolveBaseUrl,
  fetchRaw,
  preflight,
  loadFlows,
  runFlows,
  summarise,
  totp,
  freshTotp,
  adminCredentials,
  adminSession,
  COLOUR,
};
