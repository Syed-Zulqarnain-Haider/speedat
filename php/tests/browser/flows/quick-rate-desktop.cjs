"use strict";

/**
 * The quick rate on a laptop: three taps - country, weight, the price.
 *
 * This is the flow the whole product is built around, so it is the flow with
 * the most assertions. The four that matter most, and the reason each exists:
 *
 *   the price is the largest text   a visitor who glances at the screen must
 *                                   read the number first, not the brand.
 *   it appears instantly            a count-up animation reads as "thinking",
 *                                   and a courier quote that looks calculated
 *                                   looks negotiable.
 *   nothing moves                   the page must not scroll itself to show
 *                                   the price; the slot was already there.
 *   the message carries everything  the person on WhatsApp has no screen.
 */

const contract = require("../contract.cjs");
const steps = require("../steps.cjs");

module.exports = {
  name: "quick-rate-desktop",
  order: 10,
  title: "1366×768: three taps to a price, and the price is the page",
  viewport: "desktop",

  async run(t) {
    await t.open("/");
    await t.page.waitForLoadState("load", { timeout: 20000 }).catch(() => {});
    await t.page.waitForTimeout(400);

    let quote = null;

    await t.check("the calculator is on the page and built its tiles", async () => {
      const { tiles } = await steps.requireCalculator(t);
      await t.shot("cold");
      return tiles + " country tiles, pricing engine loaded";
    });

    await t.check("three taps: country, weight, the price", async () => {
      quote = await steps.quickRateToPrice(t, { kg: 5 });
      await t.shot("priced");
      if (quote.taps !== 2) {
        throw new Error("the price took " + quote.taps + " taps to reach; country then weight is two, and the third tap is the booking");
      }
      return "2 taps to " + quote.price + ", the third tap books it";
    });

    await t.check("the price appeared instantly, with no count-up", async () => {
      t.need(quote, "no price was produced");
      const m = quote.measure;
      if (m.timedOut || m.ms === null) throw new Error("the price never appeared");
      if (m.values.length !== 1) {
        throw new Error(
          [
            "the price held " + m.values.length + " different values on its way to the final one - that is a count-up:",
            ...m.values.slice(0, 8).map((v) => "    " + JSON.stringify(v)),
          ].join("\n"),
        );
      }
      // The engine is in the page and the arithmetic is a lookup; anything past
      // a frame or two means work is happening that should not be.
      if (m.ms > 400) throw new Error("the price took " + m.ms + "ms to paint, which is long enough to see");
      return m.ms + "ms, one value only: " + JSON.stringify(m.values[0]);
    });

    await t.check("the page did not scroll itself", async () => {
      t.need(quote, "no price was produced");
      const m = quote.measure;
      if (m.scrollDelta !== 0 || m.scrollExcursion > 2) {
        throw new Error(
          "the viewport moved when the price landed: ended " + m.scrollDelta + "px away, travelled " + m.scrollExcursion + "px. " +
            "The result slot is supposed to be reserved so nothing needs scrolling into view.",
        );
      }
      return "viewport unmoved";
    });

    await t.check("nothing shifted when the price landed", async () => {
      t.need(quote, "no price was produced");
      const m = quote.measure;
      if (m.clsUnavailable) t.notReady("this browser did not report layout-shift entries");
      // 0.1 is Google's "good" threshold for a whole page load. For a single
      // interaction on a slot the CSS already reserved, the honest target is
      // near zero.
      if (m.cls > 0.02) {
        throw new Error(
          [
            "layout shift of " + m.cls + " when the price landed - the reserved slot is not the right size.",
            "  shifts, ms after the tap: " + m.shifts.map((s) => s.at + "ms +" + s.value).join(", "),
          ].join("\n"),
        );
      }
      return "CLS " + m.cls + (m.shifts.length ? " over " + m.shifts.length + " shift(s)" : ", no shift at all");
    });

    await t.check("the price is the largest text on the page", async () => {
      t.need(quote, "no price was produced");
      return contract.priceIsLargestText(t.page, steps.SEL.price);
    });

    await t.check("nothing on the page renders under 14px", async () => contract.nothingUnderMinFont(t.page));

    await t.check("every service row shows a price and an estimate", async () => {
      t.need(quote && quote.services.length, "no service rows were rendered");
      const bad = quote.services.filter((s) => !s.price || !s.name);
      if (bad.length) throw new Error(bad.length + " service row(s) have no price or no name: " + JSON.stringify(bad.slice(0, 3)));
      const noEta = quote.services.filter((s) => !s.eta);
      if (noEta.length === quote.services.length) {
        t.notReady("no service row shows an estimate - either showEta is off in settings or the dates are not wired yet");
      }
      return quote.services.length + " services: " + quote.services.map((s) => s.name + " " + s.price).join(", ");
    });

    await t.check("the WhatsApp link carries destination, weight, service, price and dates", async () => {
      t.need(quote, "no price was produced");
      return contract.whatsappLinkCarries(quote.bookHref, {
        dest: quote.country,
        weight: quote.kg,
        service: quote.service,
        price: quote.price,
        dates: true,
      });
    });

    await t.check("the booking button opens WhatsApp in a new tab, safely", async () => {
      t.need(quote, "no price was produced");
      const attrs = await t.page.evaluate((sel) => {
        const a = document.querySelector(sel);
        return a ? { target: a.getAttribute("target"), rel: a.getAttribute("rel") } : null;
      }, steps.SEL.book);
      if (!attrs) throw new Error("no booking button");
      if (attrs.target !== "_blank") throw new Error('the booking button has target="' + attrs.target + '"; leaving the site loses the quote');
      if (!/noopener/.test(attrs.rel || "")) throw new Error('the booking button is target="_blank" without rel="noopener"');
      return 'target="_blank" rel="' + attrs.rel + '"';
    });

    await t.check("the quote is logged, and the server does not trust our number", async () => {
      t.need(quote, "no price was produced");

      const probe = await t.fetch(steps.SEL.quoteUrl, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: "{}",
      });
      t.need(probe.ok && probe.status !== 404, steps.SEL.quoteUrl + " answers 404 - Domain\\Quotes has not shipped");

      const watcher = steps.watchQuoteLog(t);
      await t.page.evaluate((sel) => {
        const a = document.querySelector(sel);
        if (!a) return;

        /*
         * FIRE THE SITE'S OWN CLICK HANDLER WITHOUT LEAVING FOR WHATSAPP.
         *
         * This used to rewrite the href to "javascript:void 0" and click that.
         * It worked, and it also made the browser log
         *     Running the JavaScript URL violates the Content Security Policy
         *     directive 'script-src self nonce-...'
         * - the site's CSP correctly refusing an inline script URL. The flow's
         * last check is "the browser stayed quiet over the whole flow", so the
         * harness manufactured a console error and then failed itself for it.
         * The site was never involved.
         *
         * preventDefault in the CAPTURE phase cancels the navigation before it
         * starts while leaving the bubble-phase listener the calculator
         * attached - the one that POSTs the quote log - to run exactly as it
         * does for a real customer. `once` keeps the stub from outliving this
         * one click. The href is left alone, so nothing about the link the
         * check is measuring is changed by measuring it.
         */
        a.removeAttribute("target");
        document.addEventListener("click", (e) => e.preventDefault(), { capture: true, once: true });
        a.click();
      }, steps.SEL.book);
      const posts = await watcher.wait({ timeout: 4000 });

      if (!posts.length) {
        throw new Error("booking sent no POST to " + steps.SEL.quoteUrl + " - the quote was never logged");
      }
      const sent = posts[0].body || {};
      for (const key of ["destId", "service", "total", "billableG"]) {
        if (!(key in sent)) throw new Error("the logged quote has no " + key + ": " + JSON.stringify(sent).slice(0, 200));
      }

      // Now the half that matters. Replay the same quote with a laughable total
      // and make sure the server prices it itself. If it stores what it is
      // handed, anyone can log a PKR 1 quote and wave it at the office.
      const honest = await t.fetch(steps.SEL.quoteUrl, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(sent),
      });
      const tampered = await t.fetch(steps.SEL.quoteUrl, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ ...sent, total: 1 }),
      });

      if (!honest.ok || !tampered.ok) throw new Error("the quote endpoint stopped answering mid-check");
      if (tampered.status >= 400 && tampered.status < 500) {
        return "logged, and a tampered total is rejected with " + tampered.status;
      }

      const priceIn = (raw) => {
        try {
          const j = JSON.parse(raw);
          for (const k of ["total", "price", "serverTotal", "recomputed"]) {
            if (typeof j[k] === "number") return j[k];
            if (j.quote && typeof j.quote[k] === "number") return j.quote[k];
          }
        } catch (e) {
          /* not JSON */
        }
        return null;
      };
      const a = priceIn(honest.body);
      const b = priceIn(tampered.body);
      if (a === null || b === null) {
        t.notReady(
          "the POST is logged (" + honest.status + "), but the answer carries no price, so this check cannot prove the server recomputed it. " +
            "Have /api/quotes echo the price it stored, or verify it through the admin quotes list.",
        );
      }
      if (a !== b) {
        throw new Error("the server echoed our number: honest total → " + a + ", tampered total 1 → " + b + ". It is storing what it is told.");
      }
      if (b === 1) throw new Error("the server stored the tampered total of 1");
      return "logged, and the server recomputed " + b + " for both the honest and the tampered submission";
    });

    await t.check("changing the service changes the price and the message together", async () => {
      t.need(quote, "no price was produced");
      t.need(quote.services.length > 1, "there is only one service, so there is nothing to switch to");
      const before = { price: quote.price, href: quote.bookHref };
      await t.page.locator(steps.SEL.serviceRadio).nth(1).check();
      await t.page.waitForTimeout(300);
      const after = await t.page.evaluate(
        (sel) => ({
          price: ((document.querySelector(sel.price) || {}).textContent || "").replace(/\s+/g, " ").trim(),
          href: (document.querySelector(sel.book) || {}).getAttribute
            ? document.querySelector(sel.book).getAttribute("href")
            : null,
        }),
        { price: steps.SEL.price, book: steps.SEL.book },
      );
      if (after.price === before.price && after.href === before.href) {
        throw new Error("switching service changed neither the price nor the WhatsApp message");
      }
      if (after.href === before.href) {
        throw new Error("the price changed to " + after.price + " but the WhatsApp message still says the old one");
      }
      const text = decodeURIComponent((after.href.split("text=")[1] || "").replace(/\+/g, " "));
      if (!text.includes(after.price.replace(/\s+/g, " ").trim())) {
        throw new Error("the message does not carry the new price " + after.price);
      }
      await t.shot("service-switched");
      return before.price + " → " + after.price + ", message updated with it";
    });

    await t.check("a country with no rate says so instead of showing nothing", async () => {
      await steps.requireCalculator(t);
      const states = await t.page.evaluate(
        ({ tile, result }) => {
          const out = [];
          for (const el of document.querySelectorAll(tile)) {
            const from = el.querySelector(".ctile-from");
            out.push({ name: (el.querySelector(".ctile-name") || {}).textContent || "", hasFrom: !!from });
          }
          return { tiles: out, state: (document.querySelector(result) || {}).getAttribute ? document.querySelector(result).getAttribute("data-state") : null };
        },
        { tile: steps.SEL.countryTile, result: steps.SEL.result },
      );
      const unpriced = states.tiles.find((x) => !x.hasFrom);
      t.need(unpriced, "every country tile has a published rate, so there is no empty case to see");

      await t.page.locator(steps.SEL.countryTile, { hasText: unpriced.name.trim() }).first().click();
      await t.page.waitForTimeout(300);
      const note = await t.page.textContent(steps.SEL.priceNote).catch(() => "");
      const wait = await t.page.textContent(steps.SEL.wait).catch(() => "");
      const said = (note || wait || "").trim();
      if (!said) throw new Error("tapping " + unpriced.name + " (no published rate) said nothing at all");
      if (!/whatsapp|ask|no price|no service|yet/i.test(said)) {
        throw new Error("the empty case says " + JSON.stringify(said) + ", which does not offer a way forward");
      }
      return JSON.stringify(said);
    });

    await t.check("the browser stayed quiet over the whole flow", async () => contract.noConsoleErrors(t));
  },
};
