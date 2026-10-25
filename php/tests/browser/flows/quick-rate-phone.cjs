"use strict";

/**
 * The same three taps at 375×812, where the customers actually are.
 *
 * Everything the desktop flow asserts still applies; what this flow adds is the
 * phone's own failure modes:
 *
 *   sideways scroll     one tile 4px too wide and the whole page rocks.
 *   the sticky bar      the price bar pins to the bottom of the screen while
 *                       the calculator is in view. If the price lands below
 *                       the fold on a phone, there is no product.
 *   tap targets         a 30px tile beside another 30px tile is a wrong price
 *                       sent to WhatsApp.
 *   the price still wins at a width where every font is smaller.
 */

const contract = require("../contract.cjs");
const steps = require("../steps.cjs");

module.exports = {
  name: "quick-rate-phone",
  order: 11,
  title: "375×812: the same price, in a thumb's reach, nothing sideways",
  viewport: "phone",

  async run(t) {
    await t.open("/");
    await t.page.waitForLoadState("load", { timeout: 20000 }).catch(() => {});
    await t.page.waitForTimeout(400);

    let quote = null;

    await t.check("the page fits 375px before anything is tapped", async () => {
      await t.shot("cold");
      return contract.noHorizontalScroll(t.page);
    });

    await t.check("the calculator is on the page and built its tiles", async () => {
      const { tiles } = await steps.requireCalculator(t);
      return tiles + " country tiles";
    });

    await t.check("the country tiles are reachable without scrolling", async () => {
      const first = await t.page.evaluate((sel) => {
        const el = document.querySelector(sel);
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return { top: Math.round(r.top), bottom: Math.round(r.bottom), h: Math.round(window.innerHeight) };
      }, steps.SEL.countryTile);
      t.need(first, "no country tile to measure");
      if (first.top > first.h) {
        throw new Error(
          "the first country tile starts " + first.top + "px down a " + first.h + "px screen - a visitor has to scroll before they can start",
        );
      }
      return "the first tile sits " + first.top + "px down a " + first.h + "px screen";
    });

    await t.check("tap targets are big enough for a thumb", async () => {
      const small = await t.page.evaluate(
        ({ tile, weight }) => {
          const out = [];
          for (const el of document.querySelectorAll(tile + ", " + weight + ", a.btn, button.btn")) {
            const r = el.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) continue;
            // 44px is the figure both platform guidelines settle on.
            if (r.height < 44 || r.width < 44) {
              out.push({
                what: el.tagName.toLowerCase() + "." + String(el.className || "").split(/\s+/).slice(0, 2).join("."),
                text: (el.textContent || "").replace(/\s+/g, " ").trim().slice(0, 30),
                w: Math.round(r.width),
                h: Math.round(r.height),
              });
            }
          }
          return out.slice(0, 8);
        },
        { tile: steps.SEL.countryTile, weight: steps.SEL.weightTile },
      );
      if (small.length) {
        throw new Error(
          ["tap targets under 44px:", ...small.map((s) => "    " + s.what + " " + s.w + "×" + s.h + "  " + JSON.stringify(s.text))].join("\n"),
        );
      }
      return "every tile and button is at least 44px";
    });

    await t.check("three taps: country, weight, the price", async () => {
      quote = await steps.quickRateToPrice(t, { kg: 5 });
      await t.shot("priced");
      if (quote.taps !== 2) throw new Error("the price took " + quote.taps + " taps; country then weight is two");
      return "2 taps to " + quote.price;
    });

    await t.check("the price appeared instantly, with no count-up", async () => {
      t.need(quote, "no price was produced");
      const m = quote.measure;
      if (m.timedOut || m.ms === null) throw new Error("the price never appeared");
      if (m.values.length !== 1) {
        throw new Error(
          ["the price counted up through " + m.values.length + " values:", ...m.values.slice(0, 8).map((v) => "    " + JSON.stringify(v))].join("\n"),
        );
      }
      if (m.ms > 400) throw new Error("the price took " + m.ms + "ms to paint");
      return m.ms + "ms, one value: " + JSON.stringify(m.values[0]);
    });

    await t.check("the page did not scroll itself to show the price", async () => {
      t.need(quote, "no price was produced");
      const m = quote.measure;
      if (m.scrollDelta !== 0 || m.scrollExcursion > 2) {
        throw new Error(
          "the page scrolled itself " + m.scrollDelta + "px (travelled " + m.scrollExcursion + "px). On a phone that moves the tiles out from under a thumb mid-tap.",
        );
      }
      return "viewport unmoved";
    });

    await t.check("nothing shifted when the price landed", async () => {
      t.need(quote, "no price was produced");
      if (quote.measure.clsUnavailable) t.notReady("this browser did not report layout-shift entries");
      const m = quote.measure;
      if (m.cls > 0.02) {
        throw new Error(
          [
            "layout shift of " + m.cls + " on a phone, where there is least room to absorb it.",
            "  shifts, ms after the tap: " + m.shifts.map((s) => s.at + "ms +" + s.value).join(", "),
          ].join("\n"),
        );
      }
      return "CLS " + m.cls + (m.shifts.length ? " over " + m.shifts.length + " shift(s)" : ", no shift at all");
    });

    await t.check("the price is visible on screen without scrolling", async () => {
      t.need(quote, "no price was produced");
      const where = await t.page.evaluate((sel) => {
        const el = document.querySelector(sel);
        if (!el) return null;
        const r = el.getBoundingClientRect();
        const cs = getComputedStyle(el.closest(".pricebar") || el);
        return {
          top: Math.round(r.top),
          bottom: Math.round(r.bottom),
          h: window.innerHeight,
          position: cs.position,
          scrollY: Math.round(window.scrollY),
        };
      }, steps.SEL.price);
      t.need(where, "no price element to locate");
      if (where.top < 0 || where.bottom > where.h) {
        throw new Error(
          "the price sits at " + where.top + "…" + where.bottom + " on a " + where.h + "px screen - it is off screen, so the customer has to hunt for it",
        );
      }
      return "on screen at " + where.top + "…" + where.bottom + " of " + where.h + "px, price bar is position:" + where.position;
    });

    await t.check("the price bar does not cover what comes after the calculator", async () => {
      t.need(quote, "no price was produced");
      const covered = await t.page.evaluate((sel) => {
        const bar = document.querySelector(sel);
        if (!bar) return null;
        const cs = getComputedStyle(bar);
        if (cs.position !== "fixed" && cs.position !== "sticky") return { ok: true, position: cs.position };
        // Scroll past the calculator; the bar must come to rest rather than
        // follow the visitor down the page over the footer.
        window.scrollTo(0, document.documentElement.scrollHeight);
        return new Promise((resolve) => {
          setTimeout(() => {
            const r = bar.getBoundingClientRect();
            const footer = document.querySelector("footer");
            const fr = footer ? footer.getBoundingClientRect() : null;
            resolve({
              ok: !fr || r.top >= fr.top - 1 || r.bottom <= fr.top + 1,
              position: cs.position,
              bar: Math.round(r.top) + "…" + Math.round(r.bottom),
              footer: fr ? Math.round(fr.top) + "…" + Math.round(fr.bottom) : "none",
            });
          }, 400);
        });
      }, steps.SEL.pricebar);
      t.need(covered, "no price bar to check");
      await t.shot("scrolled-to-foot");
      if (!covered.ok) {
        throw new Error("the price bar (" + covered.bar + ") still covers the footer (" + covered.footer + ") at the bottom of the page");
      }
      await t.page.evaluate(() => window.scrollTo(0, 0));
      return "position:" + covered.position + ", comes to rest at the panel's end";
    });

    await t.check("the price is the largest text on the page", async () => {
      t.need(quote, "no price was produced");
      return contract.priceIsLargestText(t.page, steps.SEL.price);
    });

    await t.check("nothing renders under 14px at phone width", async () => contract.nothingUnderMinFont(t.page));

    await t.check("the page still does not scroll sideways with a price on it", async () => contract.noHorizontalScroll(t.page));

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

    await t.check("the cargo way out works for a weight over the ceiling", async () => {
      const more = await t.page.$(steps.SEL.weightMore);
      t.need(more, "no \"Over N kg\" tile - either maxKg is 0 in settings or the tile has not shipped");
      await more.click();
      await t.page.waitForTimeout(300);
      const state = await t.page.getAttribute(steps.SEL.result, "data-state");
      if (state !== "cargo") throw new Error('tapping "Over N kg" left data-state at ' + JSON.stringify(state) + ', expected "cargo"');
      const href = await contract.bookingHref(t.page);
      const text = decodeURIComponent((String(href).split("text=")[1] || "").replace(/\+/g, " "));
      if (!/cargo/i.test(text)) throw new Error("the cargo enquiry does not say it is a cargo rate: " + JSON.stringify(text.slice(0, 120)));
      if (/Price:|Total:/.test(text)) throw new Error("the cargo enquiry quotes a price, and there is no price for a weight we have not seen");
      await t.shot("cargo");
      return "cargo enquiry, no invented price";
    });

    await t.check("the browser stayed quiet over the whole flow", async () => contract.noConsoleErrors(t));
  },
};
