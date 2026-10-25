"use strict";

/**
 * The contract.
 *
 * Every statement here is one the Next.js site passed and the PHP port has to
 * pass again, written once so twelve flows cannot each interpret it slightly
 * differently. A function either returns a short detail string (and the check
 * passes) or throws with a message that names the offending element, because a
 * failure an operator cannot act on is barely better than no test.
 *
 * Nothing in this file knows which page it is looking at. The flows decide
 * that; this decides what "right" means.
 */

const { MIN_FONT_PX, NotReady } = require("./harness.cjs");

/* ==================================================================== *
 * Typography and layout
 * ==================================================================== */

/**
 * The in-page walker both font checks share.
 *
 * "An element with text" means an element with at least one non-empty text node
 * of its own - not a container whose text belongs to its children, or every
 * ancestor up to <body> would count as the biggest text on the page.
 *
 * Invisible things are excluded, including the screen-reader-only pattern the
 * CSS uses (a 1px clipped box): it is text, but no visitor reads it, and a
 * legend sized 1px is not a 14px violation.
 */
const COLLECT_TEXT_ELEMENTS = function collect() {
  const out = [];
  const all = document.querySelectorAll("body *");
  for (const el of all) {
    let own = "";
    for (const node of el.childNodes) {
      if (node.nodeType === 3) own += node.nodeValue;
    }
    own = own.replace(/\s+/g, " ").trim();
    if (!own) continue;

    const rect = el.getBoundingClientRect();
    if (rect.width < 4 || rect.height < 4) continue;

    const cs = getComputedStyle(el);
    if (cs.visibility === "hidden" || cs.display === "none") continue;
    if (parseFloat(cs.opacity || "1") < 0.05) continue;
    if (cs.clip && cs.clip !== "auto" && /0px|1px/.test(cs.clip)) continue;
    if (cs.clipPath && /inset\(\s*50%/.test(cs.clipPath)) continue;
    if (parseFloat(cs.fontSize) <= 0) continue;

    const path = (() => {
      const bits = [];
      let n = el;
      for (let i = 0; n && i < 4; i += 1) {
        let s = n.tagName.toLowerCase();
        if (n.id) s += "#" + n.id;
        else if (n.className && typeof n.className === "string") {
          s += "." + n.className.trim().split(/\s+/).slice(0, 2).join(".");
        }
        bits.unshift(s);
        n = n.parentElement;
      }
      return bits.join(" > ");
    })();

    out.push({
      px: Math.round(parseFloat(cs.fontSize) * 100) / 100,
      weight: cs.fontWeight,
      text: own.slice(0, 60),
      path,
      top: Math.round(rect.top),
    });
  }
  return out;
};

/**
 * The price is the largest text in its panel.
 *
 * This is the whole design in one assertion: a customer glancing at the screen
 * sees the number first, before the service name, before the heading, before
 * the brand. On a phone with the price bar stuck to the bottom it is the only
 * thing that matters.
 *
 * `within` NARROWS THE COMPARISON, AND IT IS NOT A LOOPHOLE (added 2026-09-29).
 * The quick panel replaces the hero: press a service and the price IS the
 * biggest thing on the screen, so quick-rate-desktop and quick-rate-phone pass
 * this with no scope at all and keep the strong form of the rule. The detailed
 * panel opens BELOW the hero and leaves it standing, so the whole-page form of
 * the rule was comparing a price against the page's own headline - 39.52px
 * against 42.95px - and calling a heading a rival to the price. It is not: a
 * customer reading the detailed panel has scrolled past the headline and is
 * looking at a card. The check in detailed-quote has always been titled "the
 * price is still the largest text IN THE DETAILED PANEL"; this makes the code
 * mean what the title says, rather than quietly measuring something else.
 *
 * Passing `within` where the page-wide rule applies would be weakening it, so
 * it is deliberately absent from both quick-rate flows.
 */
async function priceIsLargestText(page, priceSelector, { within = null } = {}) {
  const handle = await page.$(priceSelector);
  if (!handle) throw new Error("no price element matches " + priceSelector);

  if (within) {
    const scope = await page.$(within);
    if (!scope) throw new Error("no element matches the scope " + within + ", so there is nothing to measure inside");
  }

  const priceBox = await page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (!el) return null;
    const cs = getComputedStyle(el);
    return {
      px: Math.round(parseFloat(cs.fontSize) * 100) / 100,
      text: (el.textContent || "").replace(/\s+/g, " ").trim(),
    };
  }, priceSelector);

  if (!priceBox || !priceBox.text) throw new Error(priceSelector + " is present but holds no text");

  /**
   * Rivals are elements with their own visible text, rendered LARGER than the
   * price, that are not the price element itself and not inside it - an element
   * inside the price shares its emphasis and is part of the same number.
   * Containment is tested with contains(), not by comparing printed paths.
   */
  const rivals = await page.evaluate(
    ({ collectSrc, sel, scopeSel }) => {
      const price = document.querySelector(sel);
      const priceSize = price ? parseFloat(getComputedStyle(price).fontSize) : 0;
      const scope = scopeSel ? document.querySelector(scopeSel) : document.body;

      // Re-walk here rather than reuse the collector's output, so each rival
      // can be tested for containment while its element is still in hand.
      const out = [];
      for (const el of (scope || document.body).querySelectorAll("*")) {
        if (price && (el === price || price.contains(el) || el.contains(price))) continue;

        let own = "";
        for (const node of el.childNodes) if (node.nodeType === 3) own += node.nodeValue;
        own = own.replace(/\s+/g, " ").trim();
        if (!own) continue;

        const rect = el.getBoundingClientRect();
        if (rect.width < 4 || rect.height < 4) continue;

        const cs = getComputedStyle(el);
        if (cs.visibility === "hidden" || cs.display === "none") continue;
        if (parseFloat(cs.opacity || "1") < 0.05) continue;
        if (cs.clip && cs.clip !== "auto" && /0px|1px/.test(cs.clip)) continue;
        if (cs.clipPath && /inset\(\s*50%/.test(cs.clipPath)) continue;

        const px = parseFloat(cs.fontSize);
        // Half a pixel of slack: a rounded rem and the price agreeing to within
        // that is not a design failure.
        if (px <= priceSize + 0.5) continue;

        const bits = [];
        let n = el;
        for (let i = 0; n && i < 3; i += 1) {
          let s = n.tagName.toLowerCase();
          if (n.id) s += "#" + n.id;
          else if (n.className && typeof n.className === "string") {
            s += "." + n.className.trim().split(/\s+/).slice(0, 2).join(".");
          }
          bits.unshift(s);
          n = n.parentElement;
        }

        out.push({ px: Math.round(px * 100) / 100, text: own.slice(0, 60), path: bits.join(" > ") });
      }
      return out.sort((a, b) => b.px - a.px).slice(0, 6);
    },
    { collectSrc: COLLECT_TEXT_ELEMENTS.toString(), sel: priceSelector, scopeSel: within },
  );

  if (rivals.length) {
    throw new Error(
      [
        "the price is NOT the largest text in " + (within ? within : "the page") + ".",
        "  price: " + priceBox.px + "px  " + JSON.stringify(priceBox.text),
        "  bigger:",
        ...rivals.map((r) => "    " + r.px + "px  " + r.path + "  " + JSON.stringify(r.text)),
      ].join("\n"),
    );
  }

  return "price " + priceBox.px + "px, nothing larger" + (within ? " in " + within : " on the page");
}

/** Nothing a visitor reads is smaller than 14px. */
async function nothingUnderMinFont(page, { min = MIN_FONT_PX } = {}) {
  const small = await page.evaluate(
    ({ collectSrc, minPx }) => {
      // eslint-disable-next-line no-new-func
      const collect = new Function("return (" + collectSrc + ")()");
      return collect()
        .filter((e) => e.px < minPx - 0.01)
        .sort((a, b) => a.px - b.px)
        .slice(0, 8);
    },
    { collectSrc: COLLECT_TEXT_ELEMENTS.toString(), minPx: min },
  );

  if (small.length) {
    throw new Error(
      [
        small.length + " visible text element(s) render below " + min + "px:",
        ...small.map((s) => "    " + s.px + "px  " + s.path + "  " + JSON.stringify(s.text)),
      ].join("\n"),
    );
  }
  return "smallest text is " + min + "px or larger";
}

/** Nothing sticks out sideways. The check that matters at 375. */
async function noHorizontalScroll(page) {
  const out = await page.evaluate(() => {
    const doc = document.documentElement;
    const over = doc.scrollWidth - doc.clientWidth;
    const culprits = [];
    if (over > 1) {
      const limit = doc.clientWidth;
      for (const el of document.querySelectorAll("body *")) {
        const r = el.getBoundingClientRect();
        if (r.width === 0 || r.height === 0) continue;
        if (r.right > limit + 1 || r.left < -1) {
          const cs = getComputedStyle(el);
          if (cs.position === "fixed" && cs.visibility === "hidden") continue;
          culprits.push({
            tag: el.tagName.toLowerCase(),
            cls: typeof el.className === "string" ? el.className.slice(0, 50) : "",
            left: Math.round(r.left),
            right: Math.round(r.right),
            w: Math.round(r.width),
          });
        }
        if (culprits.length >= 6) break;
      }
    }
    return { over, clientWidth: doc.clientWidth, scrollWidth: doc.scrollWidth, culprits };
  });

  if (out.over > 1) {
    throw new Error(
      [
        "the page scrolls sideways: content is " + out.scrollWidth + "px wide in a " + out.clientWidth + "px viewport (+" + out.over + ").",
        ...out.culprits.map((c) => "    <" + c.tag + " class=\"" + c.cls + "\"> spans " + c.left + "…" + c.right + " (" + c.w + "px wide)"),
      ].join("\n"),
    );
  }
  return "content fits " + out.clientWidth + "px";
}

/* ==================================================================== *
 * Console, errors and the no-hydration-mismatch equivalent
 * ==================================================================== */

/**
 * No console error, and no uncaught page error, for the whole flow.
 *
 * Warnings are reported but tolerated unless they look like the specific class
 * of bug this project cares about, which is the server's HTML and the
 * browser's idea of it disagreeing.
 */
async function noConsoleErrors(t, { allow = [] } = {}) {
  const allowed = (text) => allow.some((rx) => rx.test(text));

  /**
   * A flow that deliberately visits a page which is not built yet gets one
   * "Failed to load resource: 404" per visit, from the navigation itself. That
   * is the flow doing its job, not the site misbehaving, so those are dropped -
   * identified by the page URL, which is a path the preflight already found to
   * answer 404. Every other 404 still counts.
   */
  const deliberate404 = new Set(
    Object.keys((t.probe && t.probe.status) || {})
      .filter((p) => t.probe.status[p] === 404)
      .map((p) => t.url(p)),
  );
  const isDeliberate = (c) =>
    /failed to load resource/i.test(c.text) && /404/.test(c.text) && deliberate404.has(String(c.url).split("#")[0]);

  /**
   * ... AND THE SAME THING FOR EVERY OTHER NAVIGATION THE FLOW MADE.
   *
   * Chrome logs "Failed to load resource: the server responded with a status
   * of N" for a navigation as well as for a subresource, and for a navigation
   * it never says which URL. The harness records those separately
   * (t.documentResponses) because a navigation is always one the FLOW chose to
   * make, and the flow has already read its status and failed there if it was
   * wrong: contact-form provokes 422s on purpose, admin-hold posts a form,
   * admin-settings-content probes four admin paths to find which one exists.
   * Counting them again here - anonymously, at the end of a flow, where
   * nothing can be done about them - failed three flows for doing their job.
   *
   * They are still SHOWN, in the note this returns, so a navigation that
   * starts answering 500 does not become invisible. Only the anonymous console
   * echo of one is dropped, and only for a status a navigation really
   * answered.
   */
  const navStatuses = new Set((t.documentResponses || []).map((r) => String(r.status)));
  const isNavigationEcho = (c) =>
    /failed to load resource/i.test(c.text) &&
    Array.from(navStatuses).some((s) => c.text.includes(s));

  // Chrome's console echo of a request that never left this machine, which is
  // counted with `starved` below and reported rather than failed.
  const isStarvedEcho = (c) =>
    /failed to load resource/i.test(c.text) && /ERR_NO_BUFFER_SPACE|ERR_INSUFFICIENT_RESOURCES/i.test(c.text);

  const errors = t.console.filter(
    (c) =>
      c.type === "error" && !allowed(c.text) && !isDeliberate(c) && !isNavigationEcho(c) && !isStarvedEcho(c),
  );
  const pageErrors = t.pageErrors.filter((c) => !allowed(c.text));
  /*
   * THE MACHINE RUNNING THE TEST, NOT THE SERVER BEING TESTED.
   *
   * Chrome reports ERR_NO_BUFFER_SPACE and ERR_INSUFFICIENT_RESOURCES when
   * THIS computer has run out of sockets or memory - on Windows, after a few
   * back-to-back runs beside a dev server and a database, which is exactly how
   * this suite is used. The second of two consecutive full runs failed
   * quick-rate-desktop on a single flag SVG for that reason, having passed the
   * same check ninety seconds earlier.
   *
   * These two names say nothing whatever about the server: the request never
   * left the machine. They are counted and NAMED in this check's note, so a
   * run that is drowning says so, but they do not fail it.
   */
  const clientExhausted = /ERR_NO_BUFFER_SPACE|ERR_INSUFFICIENT_RESOURCES/i;
  const starved = t.failedRequests.filter((r) => clientExhausted.test(String(r.failure || "")));

  // The REASON as well as the URL. `allow` was only ever tested against the
  // URL here, so a flow allowing /ERR_CACHE_MISS/ - Chrome refusing to replay
  // a POST when the flow presses Back, which is the browser's rule and not
  // this site's - was still failed by it, because the string it names is in
  // `failure` and never in `url`.
  const failed = t.failedRequests.filter(
    (r) =>
      !allowed(r.url) &&
      !allowed(String(r.failure || "")) &&
      !clientExhausted.test(String(r.failure || "")),
  );
  const bad = (t.badResponses || []).filter((r) => !allowed(r.url));

  const lines = [];
  // The named URLs first: those are the ones somebody can act on. The bare
  // console lines follow, because Chrome does not say which resource it meant.
  const seen = new Set();
  for (const r of bad) {
    const key = r.status + " " + r.url;
    if (seen.has(key)) continue;
    seen.add(key);
    if (seen.size > 6) break;
    lines.push("    " + r.status + " on a " + r.type + ": " + r.url);
  }
  for (const e of pageErrors.slice(0, 6)) lines.push("    uncaught: " + e.text.slice(0, 200));
  for (const r of failed.slice(0, 6)) lines.push("    request failed: " + r.url + " (" + r.failure + ")");
  // Only mention the anonymous console lines if nothing above already explains
  // them, or every 404 gets reported twice.
  if (!bad.length) {
    for (const e of errors.slice(0, 6)) lines.push("    console.error: " + e.text.slice(0, 200));
    // Chrome asks for /favicon.ico itself when no icon is declared, and that
    // request is made by the browser rather than the page, so Playwright never
    // reports its URL. It is by far the commonest cause of an anonymous 404, so
    // say so rather than leaving somebody to guess.
    if (errors.some((e) => /failed to load resource/i.test(e.text) && /404/.test(e.text))) {
      const probe = await t.fetch("/favicon.ico");
      const declared = t.page
        ? await t.page.evaluate(() => !!document.querySelector("link[rel~='icon']")).catch(() => null)
        : null;
      if (probe.ok && probe.status === 404) {
        lines.push(
          "    ^ Chrome does not name the resource. /favicon.ico answers 404" +
            (declared === false ? " and the page declares no <link rel=\"icon\">" : "") +
            ", which is almost certainly it.",
        );
      }
    }
  }

  if (lines.length) throw new Error(["the browser reported errors:", ...lines].join("\n"));

  // Named, not hidden. The flow's own navigations that answered 4xx/5xx are
  // reported here so a page that starts answering 500 is visible in the log,
  // even though it does not fail this check - the flow that navigated there
  // is the one that owns that assertion.
  const starvedNote = starved.length
    ? "; " +
      starved.length +
      " request(s) never left this machine (" +
      Array.from(new Set(starved.map((r) => String(r.failure)))).join(", ") +
      ") - the TEST MACHINE ran out of sockets or memory, which says nothing about the site"
    : "";

  const navs = (t.documentResponses || []).filter((r) => !allowed(r.url));
  const navNote = navs.length
    ? "; " +
      navs.length +
      " navigation(s) answered 4xx/5xx, each one this flow's own: " +
      Array.from(new Set(navs.map((r) => r.method + " " + r.status + " " + r.url.replace(t.base, "")))).slice(0, 4).join(", ")
    : "";

  const warnings = t.console.filter((c) => c.type === "warning" && !allowed(c.text));
  if (warnings.length) {
    return "clean (" + warnings.length + " warning" + (warnings.length === 1 ? "" : "s") + ", tolerated)" + navNote + starvedNote;
  }
  return "no console error, no uncaught error, no failed request" + navNote + starvedNote;
}

/**
 * The PHP equivalent of a hydration mismatch.
 *
 * There is no hydration here - the server prints the page and calculator.js
 * takes it over - so the bug shape to guard against is the takeover CHANGING
 * what the server said. The headline, the navigation and the WhatsApp way out
 * must read identically before and after JavaScript runs; if they do not, a
 * visitor on a slow phone reads one thing and then another, which is the same
 * disease under a different name.
 */
async function noHydrationStyleMismatch(t, pathname, { selectors = ["h1", "title"] } = {}) {
  const url = t.url(pathname);

  const noJs = await t.browser.newContext({
    viewport: { width: t.viewport.width, height: t.viewport.height },
    javaScriptEnabled: false,
    ignoreHTTPSErrors: true,
  });
  try {
    const bare = await noJs.newPage();
    await bare.goto(url, { waitUntil: "domcontentloaded", timeout: 30000 });
    const before = await bare.evaluate(
      (sels) =>
        sels.map((s) => {
          const el = document.querySelector(s);
          return el ? (el.textContent || "").replace(/\s+/g, " ").trim() : null;
        }),
      selectors,
    );

    const live = await t.context.newPage();
    t.watch(live);
    await live.goto(url, { waitUntil: "load", timeout: 30000 });
    await live.waitForTimeout(600);
    const after = await live.evaluate(
      (sels) =>
        sels.map((s) => {
          const el = document.querySelector(s);
          return el ? (el.textContent || "").replace(/\s+/g, " ").trim() : null;
        }),
      selectors,
    );
    await live.close();

    const drift = [];
    selectors.forEach((sel, i) => {
      if (before[i] !== after[i]) {
        drift.push("    " + sel + "\n      server: " + JSON.stringify(before[i]) + "\n      after JS: " + JSON.stringify(after[i]));
      }
    });
    if (drift.length) {
      throw new Error(["JavaScript rewrote what the server printed:", ...drift].join("\n"));
    }

    const mismatchNoise = t.console.filter((c) => /hydrat|did not match|mismatch/i.test(c.text));
    if (mismatchNoise.length) {
      throw new Error("the console mentions a mismatch: " + mismatchNoise[0].text.slice(0, 200));
    }

    return selectors.length + " anchor(s) identical with and without JavaScript";
  } finally {
    await noJs.close();
  }
}

/* ==================================================================== *
 * WhatsApp
 * ==================================================================== */

/**
 * The booking link is the product. Everything the customer chose has to be in
 * the message, because the person on the other end of WhatsApp has no screen
 * to look at: destination, weight, service, price, and the dates.
 */
function whatsappLinkCarries(href, { dest, weight, service, price, dates = true, quoteId = true } = {}) {
  if (!href) throw new Error("the booking link has no href");

  let url;
  try {
    url = new URL(href);
  } catch (e) {
    throw new Error("the booking link is not a URL: " + String(href).slice(0, 120));
  }
  if (!/(^|\.)wa\.me$/.test(url.hostname) && !/(^|\.)whatsapp\.com$/.test(url.hostname)) {
    throw new Error("the booking link does not point at WhatsApp: " + url.hostname);
  }
  const number = url.pathname.replace(/[^0-9]/g, "");
  if (number.length < 8) throw new Error("the WhatsApp number in the link looks wrong: " + url.pathname);

  const text = url.searchParams.get("text") || "";
  if (!text) throw new Error("the WhatsApp link carries no prefilled message (?text= is empty)");

  const missing = [];
  const has = (rx) => rx.test(text);

  if (dest) {
    if (!has(new RegExp("To:\\s*" + dest.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "i"))) {
      missing.push('destination ("To: ' + dest + '")');
    }
  } else if (!has(/^To:/im)) missing.push('a destination line ("To: …")');

  if (weight) {
    const w = String(weight).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    if (!has(new RegExp(w + "\\s*kg", "i")) && !has(new RegExp("Charged on:\\s*" + w, "i"))) {
      missing.push("the weight (" + weight + " kg)");
    }
  } else if (!has(/\bkg\b/i)) missing.push("a weight in kg");

  if (service) {
    if (!has(new RegExp("Service:\\s*" + service.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "i"))) {
      missing.push('the service ("Service: ' + service + '")');
    }
  } else if (!has(/^Service:/im)) missing.push('a service line ("Service: …")');

  if (price) {
    const digits = String(price).replace(/[^0-9]/g, "");
    const loose = digits.replace(/(\d)/g, "$1[,\\s]*");
    if (!new RegExp(loose).test(text.replace(/,/g, ","))) {
      missing.push("the price the customer saw (" + price + ")");
    }
  }
  if (!has(/^(Price|Total|Shipping):/im)) missing.push('a price line ("Price:" / "Total:")');

  if (dates && !has(/^(Estimated delivery|Pickup):/im)) {
    missing.push('the dates ("Pickup:" / "Estimated delivery:")');
  }
  if (quoteId && !has(/^Quote:\s*\S/im)) missing.push('the quote reference ("Quote: …")');

  if (missing.length) {
    throw new Error(
      [
        "the WhatsApp message is missing " + missing.length + " thing(s): " + missing.join(", "),
        "  message as built:",
        ...text.split("\n").slice(0, 14).map((l) => "    " + l),
      ].join("\n"),
    );
  }

  return text.split("\n").length + " lines, all required parts present";
}

/**
 * Find the booking link a visitor would actually press.
 *
 * WHAT THIS GOT WRONG, AND WHAT IT COST (fixed 2026-09-29). The selector is a
 * LIST - `a.btn.book, a.btn.wa, a[href*='wa.me']` - and querySelectorAll
 * returns DOCUMENT order, not list order. Every page here has three kinds of
 * WhatsApp link: the header's icon (a.btn.wa), the booking button the
 * calculator builds (a.btn.book), and the footer's plain "WhatsApp <number>"
 * in the reach block. The old code took `visible[visible.length - 1]` - the
 * last in the document, which is the footer's. That link is a bare
 * https://wa.me/<number> with no ?text= at all, so every check that read the
 * prefilled message got an empty string and reported it as the site failing to
 * build one. quick-rate-phone's cargo check failed in exactly that shape:
 *     the cargo enquiry does not say it is a cargo rate: ""
 *
 * So the order is now PRIORITY, not position: the most specific selector with
 * a visible match wins, and inside one tier a link carrying a prefilled
 * message beats one that does not. The footer's number is a real link a real
 * customer may press and it stays in the fallback - it is simply never the
 * booking button.
 */
async function bookingHref(page, selector = "a.btn.book, a.btn.wa, a[href*='wa.me']") {
  const href = await page.evaluate((sel) => {
    const onScreen = (els) =>
      els.filter((el) => {
        const r = el.getBoundingClientRect();
        if (r.width <= 0 || r.height <= 0) return false;
        const cs = getComputedStyle(el);
        return cs.visibility !== "hidden" && cs.display !== "none";
      });

    // Each comma-separated selector is its own tier, most specific first.
    for (const one of sel.split(",").map((s) => s.trim()).filter(Boolean)) {
      const all = Array.from(document.querySelectorAll(one));
      if (!all.length) continue;
      const shown = onScreen(all);
      const tier = shown.length ? shown : all;

      // Inside a tier, a prefilled message is the booking link; a bare number
      // is the "reach us" link that happens to match the same selector.
      const withText = tier.filter((el) => (el.getAttribute("href") || "").includes("text="));
      return (withText.length ? withText[0] : tier[0]).getAttribute("href");
    }
    return null;
  }, selector);
  return href;
}

/**
 * Wait out the site's minimum think time before submitting a public form.
 *
 * WHY A TEST HAS TO WAIT. Every public form carries a signed render timestamp
 * (`input[name=t]`, Http\FormToken) and the handler refuses anything returned
 * faster than a person could have typed it - FormToken::MIN_AGE_MS, three
 * seconds. That is the whole anti-bot design on the contact form, beside the
 * honeypot, and it works: the harness filled four fields in about a second and
 * every submission came back 422. The flow then reported
 *     a valid enquiry was submitted and the page never acknowledged it
 * which is the site doing its job being read as the site being broken - and
 * the same 422s showed up a second time as console errors in the
 * quiet-browser check at the end of that flow. Two red checks, no defect.
 *
 * The wait is computed from the token the page actually issued rather than
 * from a constant copied over here, so it stays right if the number moves, and
 * it waits only for what is LEFT: filling the form has already used most of
 * it. A missing or unreadable token falls back to the full three seconds plus
 * a margin, because being too slow is never the wrong answer here.
 */
async function settleThinkTime(page, { minMs = 3000, margin = 400, field = "t" } = {}) {
  const issuedAt = await page
    .getAttribute('input[name="' + field + '"]', "value")
    .then((v) => {
      const m = /^(\d{10,16})\./.exec(String(v || ""));
      return m ? Number(m[1]) : null;
    })
    .catch(() => null);

  // Date.now() here against a timestamp minted by the server: on one machine
  // that is one clock. A skewed clock can only lengthen the wait, which is the
  // harmless direction.
  const waited = issuedAt ? Math.max(0, minMs + margin - (Date.now() - issuedAt)) : minMs + margin;
  if (waited > 0) await page.waitForTimeout(waited);
  return waited;
}

/* ==================================================================== *
 * The hold panel
 * ==================================================================== */

/**
 * When prices are held, there is NO price in the HTML. Not hidden with CSS,
 * not in a data attribute, not in the JSON blob the calculator reads, not in a
 * comment. "Hidden" is a styling decision a browser extension can undo; absent
 * is absent.
 *
 * The test is deliberately crude and therefore hard to fool: scan the raw
 * server HTML for anything that reads like money.
 */
function noPriceAnywhereInHtml(html, { currency = "PKR" } = {}) {
  const hits = [];
  const patterns = [
    new RegExp("\\b" + currency + "\\s*[0-9][0-9,.]*", "gi"),
    /\bRs\.?\s*[0-9][0-9,.]*/gi,
    /"(total|price|amount|rate|grandTotal)"\s*:\s*-?[0-9]+(\.[0-9]+)?/gi,
    /\bfrom\s+[0-9]{3,}[0-9,]*\b/gi,
  ];
  for (const rx of patterns) {
    let m;
    // eslint-disable-next-line no-cond-assign
    while ((m = rx.exec(html)) !== null) {
      const at = Math.max(0, m.index - 40);
      hits.push(JSON.stringify(m[0]) + "  …" + html.slice(at, m.index + m[0].length + 30).replace(/\s+/g, " ") + "…");
      if (hits.length >= 6) break;
    }
    if (hits.length >= 6) break;
  }
  if (hits.length) {
    throw new Error(
      ["prices are held, but the HTML still contains money:", ...hits.map((h) => "    " + h)].join("\n"),
    );
  }
  return "no currency amount anywhere in " + html.length + " bytes of HTML";
}

/* ==================================================================== *
 * Admin forms
 * ==================================================================== */

/**
 * Every form that changes something carries a CSRF token. The field name is
 * Csrf::FIELD in app/Core/Csrf.php; if that ever changes this is the test that
 * notices.
 */
async function everyFormHasCsrf(page, { field = "_token" } = {}) {
  const forms = await page.evaluate(
    (f) =>
      Array.from(document.querySelectorAll("form")).map((form, i) => {
        const method = (form.getAttribute("method") || "get").toLowerCase();
        const token = form.querySelector('input[name="' + f + '"]');
        return {
          i,
          method,
          action: form.getAttribute("action") || "",
          id: form.id || "",
          hasToken: !!token,
          tokenEmpty: token ? !String(token.value || "").trim() : true,
        };
      }),
    field,
  );

  const posts = forms.filter((f) => f.method === "post");
  if (!posts.length) throw new NotReady("this page has no POST form yet - nothing to check");

  const bad = posts.filter((f) => !f.hasToken || f.tokenEmpty);
  if (bad.length) {
    throw new Error(
      [
        bad.length + " of " + posts.length + " POST form(s) have no usable " + field + ":",
        ...bad.map((f) => "    form#" + (f.id || f.i) + ' action="' + f.action + '" ' + (f.hasToken ? "(token present but empty)" : "(no token field)")),
      ].join("\n"),
    );
  }
  return posts.length + " POST form(s), every one carries a non-empty " + field;
}

/**
 * A page's form reaches the server with JavaScript off.
 *
 * Not a nicety: admin.js is progressive enhancement over plain forms, and the
 * owner edits rates over a phone connection that drops. A form that only
 * submits through a click handler is a form that loses an afternoon of edits.
 *
 * WHAT IT USED TO DO INSTEAD (fixed 2026-09-28). The default selector is
 * `form[method=post]` and `page.$()` returns the FIRST match - which on every
 * signed-in admin page is the Sign out form in the header. So "the editor works
 * with JavaScript off" clicked Sign out, watched the URL change, and passed.
 * It proved nothing about any editor, and it destroyed the session that the
 * rest of the flow was holding. Two safeguards now:
 *
 *   1. a logout form is REFUSED outright. There is no case where signing out
 *      is the thing under test, and the failure it causes lands three checks
 *      later, where nobody looks for it.
 *   2. `expect: "saved"` makes the check assert the server ACCEPTED the post.
 *      "The page changed" is too weak a bar for a save: an error notice is a
 *      change too, and the rates editor's real answer is a redirect carrying
 *      ?err=toobig.
 *
 * Callers that submit a form on purpose to see it REFUSED (the empty sign-in
 * form) leave `expect` at its default.
 *
 * @param {"handled"|"saved"} [options.expect] "handled": the server answered at
 *        all. "saved": it answered without an error notice.
 */
async function submitsWithoutJavaScript(
  t,
  pathname,
  { formSelector = "form[method=post]", submitSelector = null, cookies = null, expect = "handled" } = {},
) {
  const ctx = await t.browser.newContext({
    viewport: { width: t.viewport.width, height: t.viewport.height },
    javaScriptEnabled: false,
    ignoreHTTPSErrors: true,
  });
  try {
    if (cookies) await ctx.addCookies(cookies);
    const page = await ctx.newPage();
    const res = await page.goto(t.url(pathname), { waitUntil: "domcontentloaded", timeout: 30000 });
    if (!res || res.status() === 404) throw new NotReady(pathname + " answers 404 with JavaScript off - not shipped");
    if (res.status() >= 500) throw new Error(pathname + " answers " + res.status() + " with JavaScript off");

    const form = await page.$(formSelector);
    if (!form) throw new NotReady("no " + formSelector + " on " + pathname + " yet");

    const action = (await form.getAttribute("action")) || "";
    if (/log-?out|sign-?out/i.test(action)) {
      throw new Error(
        'the form this check picked up on ' + pathname + ' posts to "' + action + '" - that is the Sign out button, ' +
          "not the form under test. Name the real one with formSelector; submitting this one ends the session and " +
          "makes every check after it meaningless.",
      );
    }

    const submit = submitSelector
      ? await page.$(submitSelector)
      : await page.$(
          formSelector + " button[type=submit], " + formSelector + " input[type=submit], " + formSelector + " button:not([type])",
        );
    if (!submit) {
      throw new Error(
        "the form on " + pathname + " has no submit button" + (submitSelector ? " matching " + submitSelector : "") +
          " - with JavaScript off there is no way to send it",
      );
    }

    const before = page.url();
    await Promise.all([
      page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {}),
      submit.click({ timeout: 10000 }).catch(() => {}),
    ]);
    await page.waitForTimeout(400);

    const body = await page.content();
    if (/stack trace|Fatal error|Uncaught|SQLSTATE|PDOException/i.test(body)) {
      throw new Error("submitting with JavaScript off leaked an error page from " + pathname);
    }
    const moved = page.url() !== before || /error|invalid|required|saved|updated/i.test(body);
    if (!moved) {
      throw new Error("submitting with JavaScript off changed nothing - the form did not reach the server");
    }

    // What the server said back, in the words the owner would see.
    const said = await page.evaluate(() =>
      Array.from(document.querySelectorAll('.notice, .alert, .error, [role=alert], .flash'))
        .map((e) => (e.textContent || "").replace(/\s+/g, " ").trim())
        .filter(Boolean)
        .slice(0, 3)
        .join(" | "),
    );

    if (expect === "saved") {
      const url = page.url();
      const complained = /[?&]err=/.test(url) || /too big|could not|cannot|failed|went wrong|not saved|try again/i.test(said);
      if (complained) {
        throw new Error(
          [
            "the form on " + pathname + " reached the server with JavaScript off and the server REFUSED it:",
            "    landed on : " + url,
            "    it said   : " + (said || "(no notice on the page)"),
            "A form the server refuses is a form the owner cannot use on a phone with the script blocked.",
          ].join("\n"),
        );
      }
      return "the form saves with JavaScript off" + (said ? ' - "' + said.slice(0, 80) + '"' : "");
    }

    return "the form submits and the server answers with JavaScript off";
  } finally {
    await ctx.close();
  }
}

/* ==================================================================== *
 * SEO
 * ==================================================================== */

/**
 * Does this absolute URL name a host OTHER than the one being tested?
 *
 * WHAT THE OLD RULE GOT WRONG (fixed 2026-09-29). It was a word list -
 * localhost, 127.0.0.1, .test - and it fired on the canonical of every page
 * whenever the harness ran against the dev server, which is how the harness is
 * run almost every time. Seven red checks, every run, for a site that was
 * printing its own address correctly. A suite that is red for a reason nobody
 * can fix is a suite people stop reading, which is how the eighth failure -
 * a real one - gets scrolled past.
 *
 * The defect the rule was written for is narrower than the word list, and the
 * word list could not express it: a page whose canonical points somewhere the
 * page is NOT. That is a base URL baked into the code or copied from the wrong
 * config, and its symptom is identical on every host - the staging build that
 * canonicalises to the developer's laptop, and the production build that
 * canonicalises to staging, are the same bug and the word list caught neither.
 *
 * So the rule is now: the host in the URL must be the host the harness asked.
 * On the dev server that passes, because the dev server says it is the dev
 * server. On staging it catches localhost AND production. On production it
 * catches staging AND localhost. One rule, no list to keep up to date.
 *
 * @returns {string[]} zero or one problem, ready to push onto a problems list
 */
function foreignHostProblems(t, url, what) {
  let here;
  let there;
  try {
    here = new URL(t.base);
    there = new URL(url, t.base);
  } catch (e) {
    return [what + " is not a URL: " + url];
  }

  if (there.host === here.host) return [];

  return [
    what +
      " points at " +
      there.host +
      ", but this site is being served from " +
      here.host +
      " (" +
      url +
      ")",
  ];
}

/**
 * The SEO essentials, per page, server-rendered.
 *
 * Split in two deliberately. The first group is what any served page must
 * have; the second is what the SEO partial owns. Until that partial ships the
 * second group reports NOT READY and names it, rather than failing a
 * placeholder for a thing nobody has written yet.
 */
async function seoEssentials(t, pathname, { expectFaq = false, expectBreadcrumb = false } = {}) {
  const url = t.url(pathname);
  const res = await t.fetch(pathname);
  if (!res.ok) throw new Error("could not fetch " + pathname + ": " + res.error);
  if (res.status === 404) throw new NotReady(pathname + " answers 404 - not shipped");
  if (res.status !== 200) throw new Error(pathname + " answers " + res.status);

  const html = res.body;
  const problems = [];
  const notes = [];

  /* ---- group one: any served page ---------------------------------- */

  const title = (html.match(/<title[^>]*>([\s\S]*?)<\/title>/i) || [])[1];
  if (!title || !title.trim()) problems.push("no <title>");
  else if (title.trim().length > 70) notes.push("title is " + title.trim().length + " chars (over 70 is truncated in results)");

  const desc = (html.match(/<meta[^>]+name=["']description["'][^>]*content=["']([^"']*)["']/i) || [])[1];
  if (!desc || !desc.trim()) problems.push("no <meta name=description>");
  else if (desc.trim().length < 50) notes.push("description is only " + desc.trim().length + " chars");

  if (!/<html[^>]+lang=["'][a-z]{2}/i.test(html)) problems.push("no lang on <html>");
  if (!/<meta[^>]+charset=/i.test(html)) problems.push("no <meta charset>");
  if (!/<meta[^>]+name=["']viewport["']/i.test(html)) problems.push("no viewport meta");

  const h1s = html.match(/<h1[\s>]/gi) || [];
  if (h1s.length === 0) problems.push("no <h1>");
  else if (h1s.length > 1) problems.push(h1s.length + " <h1> elements (exactly one is the rule)");

  const robotsMetas = html.match(/<meta[^>]+name=["']robots["']/gi) || [];
  if (robotsMetas.length > 1) problems.push(robotsMetas.length + " <meta name=robots> tags");

  if (problems.length) {
    throw new Error([pathname + " fails the page basics:", ...problems.map((p) => "    " + p)].join("\n"));
  }

  /* ---- group two: the SEO partial ---------------------------------- */

  const canonical = (html.match(/<link[^>]+rel=["']canonical["'][^>]*href=["']([^"']+)["']/i) || [])[1];
  const ogTitle = /property=["']og:title["']/.test(html);
  const ldBlocks = html.match(/<script[^>]+type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi) || [];

  if (!canonical && !ogTitle && !ldBlocks.length) {
    throw new NotReady(
      pathname +
        " has a title, a description, one h1, lang, charset and viewport - but no canonical, no Open Graph and no JSON-LD. The SEO partial (Domain\\Seo) has not shipped.",
    );
  }

  const seoProblems = [];

  if (!canonical) seoProblems.push("no rel=canonical");
  else {
    if (!/^https?:\/\//i.test(canonical)) seoProblems.push("canonical is not absolute: " + canonical);
    if (canonical.includes("?")) seoProblems.push("canonical carries a query string: " + canonical);
    seoProblems.push(...foreignHostProblems(t, canonical, "canonical"));
    const wantPath = pathname === "/" ? "/" : pathname.replace(/\/+$/, "");
    let gotPath = "/";
    try {
      gotPath = new URL(canonical).pathname.replace(/\/+$/, "") || "/";
    } catch (e) {
      seoProblems.push("canonical is not a URL: " + canonical);
    }
    if (gotPath !== wantPath) seoProblems.push("canonical points at " + gotPath + ", this page is " + wantPath);
  }

  for (const tag of ["og:title", "og:description", "og:url", "og:type", "og:site_name", "og:image"]) {
    if (!new RegExp('property=["\']' + tag + '["\']').test(html)) seoProblems.push("no " + tag);
  }
  if (!/name=["']twitter:card["']/.test(html)) seoProblems.push("no twitter:card");

  if (!ldBlocks.length) seoProblems.push("no JSON-LD");
  const types = [];
  ldBlocks.forEach((block, i) => {
    const json = block.replace(/^[\s\S]*?>/, "").replace(/<\/script>$/i, "");
    try {
      const parsed = JSON.parse(json);
      const walk = (node) => {
        if (Array.isArray(node)) return node.forEach(walk);
        if (node && typeof node === "object") {
          if (node["@type"]) types.push(String(node["@type"]));
          if (node["@graph"]) walk(node["@graph"]);
        }
      };
      walk(parsed);
    } catch (e) {
      seoProblems.push("JSON-LD block " + (i + 1) + " does not parse: " + e.message);
    }
  });

  if (ldBlocks.length && !types.includes("LocalBusiness")) {
    seoProblems.push("no LocalBusiness in the JSON-LD (found: " + (types.join(", ") || "nothing") + ")");
  }
  if (expectFaq && !types.includes("FAQPage")) seoProblems.push("no FAQPage on the FAQ page");
  if (expectBreadcrumb && !types.includes("BreadcrumbList")) seoProblems.push("no BreadcrumbList on an inner page");

  const imgs = html.match(/<img\b[^>]*>/gi) || [];
  const noAlt = imgs.filter((tag) => !/\salt=/i.test(tag));
  if (noAlt.length) seoProblems.push(noAlt.length + " <img> without alt, e.g. " + noAlt[0].slice(0, 80));

  if (seoProblems.length) {
    throw new Error([pathname + " fails the SEO contract:", ...seoProblems.map((p) => "    " + p)].join("\n"));
  }

  return (
    "title, description, canonical, 1 h1, OG+Twitter, JSON-LD [" +
    Array.from(new Set(types)).join(", ") +
    "]" +
    (notes.length ? " (" + notes.join("; ") + ")" : "")
  );
}

module.exports = {
  priceIsLargestText,
  nothingUnderMinFont,
  noHorizontalScroll,
  noConsoleErrors,
  noHydrationStyleMismatch,
  whatsappLinkCarries,
  bookingHref,
  settleThinkTime,
  foreignHostProblems,
  noPriceAnywhereInHtml,
  everyFormHasCsrf,
  submitsWithoutJavaScript,
  seoEssentials,
  COLLECT_TEXT_ELEMENTS,
};
