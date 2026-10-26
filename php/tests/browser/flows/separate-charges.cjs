"use strict";

/**
 * SEPARATE CHARGES at 375×812 - owner, 2026-10-26: "the rates shown everywhere
 * should only show the actual rate, not the pickup charges included. At the
 * bottom just add a drill down and there show 1000 pickup charges". Owner:
 * rates show the courier rate only; the pickup charge is shown under the
 * quote, never added.
 *
 * Needs a published document with at least one "Label | Amount | separate"
 * (or "info") add-on line; without one every check is NOT READY, never FAIL,
 * because there is nothing to drill down into. On the local dev site:
 * Settings -> add-ons -> "Pickup | 1000 | separate", publish.
 *
 * What it pins, in the browser, against the engine the page itself loaded:
 *   - every country tile's "from" figure, every service row's price and the
 *     bar are the courier rate alone (plus only the add-ons that are on) -
 *     each read off the page and compared, not assumed (review, 2026-10-26:
 *     the tiles and rows used to be claimed here and never checked);
 *   - under the price, a native <details>, closed, whose summary is "Pickup
 *     charge" (or "Other charges"), and whose body says it is paid separately;
 *   - nothing shifts when the price lands, the page does not scroll sideways;
 *   - the WhatsApp text keeps "Price" as the rate and names the charge on its
 *     own "(separate)" line;
 *   - the detailed quote card carries the same drill-down under its Price.
 */

const contract = require("../contract.cjs");
const steps = require("../steps.cjs");

module.exports = {
  name: "separate-charges",
  order: 15,
  title: "375×812: the courier rate only, and the pickup charge in a drill-down under it",
  viewport: "phone",

  async run(t) {
    await t.open("/");
    await t.page.waitForLoadState("load", { timeout: 20000 }).catch(() => {});
    await t.page.waitForTimeout(400);

    // What the published document says, read from the page's own engine.
    const doc = await t.page.evaluate(() => {
      const P = window.SpeedatPricing;
      const blob = document.getElementById("speedat-calculator");
      if (!P || !blob || typeof P.separateCharges !== "function") return null;
      const site = JSON.parse(blob.textContent || "{}").site;
      if (!site) return null;
      return {
        separate: P.separateCharges(site.settings),
        onTotal: P.addonsList(site.settings).reduce((s, a) => s + (a.on ? a.amount : 0), 0),
        currency: site.settings.currency,
        names: P.separateCharges(site.settings).map((c) => P.chargeName(c.label)),
        money: P.separateCharges(site.settings).map((c) => P.fmtMoney(c.amount, site.settings.currency)),
      };
    });

    let quote = null;

    await t.check("the published document has a separate charge to show", async () => {
      t.need(doc, "the calculator blob or pricing.js separateCharges() is missing");
      t.need(doc.separate.length > 0, 'no "Label | Amount | separate" add-on line is published - add "Pickup | 1000 | separate" in Settings');
      return doc.separate.length + " separate charge(s): " + doc.names.map((n, i) => n + " " + doc.money[i]).join(", ");
    });

    await t.check("the first paint reserves the drill-down's row", async () => {
      t.need(doc && doc.separate.length, "no separate charge");
      const sep = await t.page.getAttribute(steps.SEL.result, "data-sep");
      if (sep !== "1") throw new Error("the result block has data-sep=" + JSON.stringify(sep) + ", so its closed row is not reserved");
      return "data-sep=1";
    });

    await t.check("every country tile's from-price is the bare 1 kg rate, nothing separate added", async () => {
      t.need(doc && doc.separate.length, "no separate charge");
      await t.page.waitForSelector(steps.SEL.countryTile, { timeout: 10000 });
      const tiles = await t.page.evaluate(
        ({ tile, onTotal }) => {
          const P = window.SpeedatPricing;
          const site = JSON.parse(document.getElementById("speedat-calculator").textContent).site;
          const cur = site.settings.currency;
          const out = [];
          for (const el of document.querySelectorAll(tile)) {
            const name = ((el.querySelector(".ctile-name") || {}).textContent || "").trim();
            const from = el.querySelector(".ctile-from");
            const dest = site.destinations.find((d) => d.name.trim() === name);
            // The engine's answer: the cheapest service at exactly 1 kg, the
            // same call the calculator's fromPrice() makes, with no separate
            // charge in it.
            let want = null;
            if (dest) {
              const res = P.priceAll(site, { destId: dest.id, rows: [{ kg: 1, qty: 1 }] });
              if (res.ok) {
                const totals = site.services.map((sv) => res.prices[sv.id]).filter(Boolean).map((p) => p.total);
                if (totals.length) want = "from " + P.fmtMoney(Math.min.apply(null, totals) + onTotal, cur);
              }
            }
            // The figure alone: the " · 3-5 days" span is a child of the same node.
            let got = null;
            if (from) {
              const copy = from.cloneNode(true);
              copy.querySelectorAll(".ctile-days").forEach((n) => n.remove());
              got = (copy.textContent || "").trim();
            }
            out.push({ name, got, want, known: !!dest });
          }
          return out;
        },
        { tile: steps.SEL.countryTile, onTotal: doc.onTotal },
      );
      t.need(tiles.length, "no country tiles on the page");
      const priced = tiles.filter((x) => x.got !== null || x.want !== null);
      t.need(priced.length, "no tile carries a from-price");
      for (const x of tiles) {
        if (!x.known) throw new Error("tile " + JSON.stringify(x.name) + " names no destination in the document");
        if (x.got !== x.want) {
          throw new Error(x.name + ": the tile says " + JSON.stringify(x.got) + "; the engine's 1 kg minimum (no separate charge) is " + JSON.stringify(x.want));
        }
      }
      return priced.length + " tile(s) match, e.g. " + priced[0].name + " " + priced[0].got;
    });

    await t.check("two taps: the price is the courier rate, with nothing separate added", async () => {
      t.need(doc && doc.separate.length, "no separate charge");
      quote = await steps.quickRateToPrice(t, { kg: 1 });
      await t.shot("priced");
      const expected = await t.page.evaluate(
        ({ country, kg, onTotal }) => {
          const P = window.SpeedatPricing;
          const site = JSON.parse(document.getElementById("speedat-calculator").textContent).site;
          const dest = site.destinations.find((d) => d.name === country);
          const res = P.priceAll(site, { destId: dest.id, rows: [{ kg: Number(kg), qty: 1 }] });
          const chosen = document.querySelector("fieldset.opts input[type=radio]:checked");
          const p = res.prices[chosen.value];
          return P.fmtMoney(p.total + onTotal, site.settings.currency);
        },
        { country: quote.country, kg: quote.kg, onTotal: doc.onTotal },
      );
      if (quote.price !== expected) {
        throw new Error("the bar says " + quote.price + "; the courier rate (plus only the add-ons that are on) is " + expected);
      }
      return quote.country + " " + quote.kg + " kg: " + quote.price;
    });

    await t.check("every service row's price is the courier rate, nothing separate added", async () => {
      t.need(quote, "no price was produced");
      const rows = await t.page.evaluate(
        ({ country, kg, onTotal }) => {
          const P = window.SpeedatPricing;
          const site = JSON.parse(document.getElementById("speedat-calculator").textContent).site;
          const dest = site.destinations.find((d) => d.name === country);
          const res = P.priceAll(site, { destId: dest.id, rows: [{ kg: Number(kg), qty: 1 }] });
          return Array.from(document.querySelectorAll(".result fieldset.opts label.opt")).map((el) => {
            const id = (el.querySelector("input[type=radio]") || {}).value || "";
            const p = res.ok ? res.prices[id] : null;
            return {
              name: ((el.querySelector(".opt-name") || {}).textContent || "").trim(),
              got: ((el.querySelector(".opt-price") || {}).textContent || "").trim(),
              want: p ? P.fmtMoney(p.total + onTotal, site.settings.currency) : null,
            };
          });
        },
        { country: quote.country, kg: quote.kg, onTotal: doc.onTotal },
      );
      t.need(rows.length, "no service rows under the price");
      for (const r of rows) {
        if (r.got !== r.want) {
          throw new Error(r.name + ": the row says " + JSON.stringify(r.got) + "; the engine's price without the separate charge is " + JSON.stringify(r.want));
        }
      }
      return rows.map((r) => r.name + " " + r.got).join(", ");
    });

    await t.check("nothing shifted when the price landed", async () => {
      t.need(quote, "no price was produced");
      if (quote.measure.clsUnavailable) t.notReady("this browser did not report layout-shift entries");
      if (quote.measure.cls > 0.02) throw new Error("layout shift of " + quote.measure.cls);
      return "CLS " + quote.measure.cls;
    });

    await t.check("under the price: a closed native drill-down naming the charge", async () => {
      t.need(quote, "no price was produced");
      const d = await t.page.evaluate(() => {
        const el = document.querySelector(".result details.charges");
        if (!el) return null;
        const bar = document.querySelector(".result .pricebar");
        return {
          open: el.open,
          summary: (el.querySelector("summary") || {}).textContent || "",
          items: Array.from(el.querySelectorAll("li")).map((li) => li.textContent || ""),
          afterBar: !!bar && !!(bar.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING),
          h: Math.round(el.getBoundingClientRect().height),
        };
      });
      if (!d) throw new Error("no details.charges in the live result");
      if (d.open) throw new Error("the drill-down is open by default");
      const want = doc.separate.length === 1 ? doc.names[0] : "Other charges";
      if (d.summary !== want) throw new Error("summary reads " + JSON.stringify(d.summary) + ", expected " + JSON.stringify(want));
      if (!d.afterBar) throw new Error("the drill-down sits above the price bar; it must come after it");
      doc.names.forEach((n, i) => {
        const line = n + ": " + doc.money[i] + " - paid separately, not included in the price above.";
        if (!d.items.includes(line)) throw new Error("missing line " + JSON.stringify(line) + " in " + JSON.stringify(d.items));
      });
      if (d.items.some((x) => x.indexOf("—") >= 0)) throw new Error("an em dash in customer text");
      return JSON.stringify(d.summary) + ", closed, " + d.h + "px";
    });

    await t.check("the drill-down opens with a tap and the page still fits 375px", async () => {
      t.need(quote, "no price was produced");
      await t.page.locator(".result details.charges summary").click();
      await t.page.waitForTimeout(150);
      const open = await t.page.evaluate(() => document.querySelector(".result details.charges").open);
      if (!open) throw new Error("tapping the summary did not open it");
      await t.shot("drill-open");
      const fit = await contract.noHorizontalScroll(t.page);
      await t.page.locator(".result details.charges summary").click();
      return "opens; " + fit;
    });

    await t.check("WhatsApp: Price is the rate, the charge is its own (separate) line", async () => {
      t.need(quote, "no price was produced");
      const text = decodeURIComponent(String(quote.bookHref).split("text=")[1] || "");
      doc.names.forEach((n, i) => {
        const line = n + " (separate): " + doc.money[i];
        if (text.indexOf(line) < 0) throw new Error("the message lacks " + JSON.stringify(line) + ":\n" + text);
      });
      if (doc.onTotal === 0 && text.indexOf("Price: " + quote.price) < 0) {
        throw new Error("the message's Price is not the bar's " + quote.price + ":\n" + text);
      }
      return "carries " + doc.names.length + " separate line(s)";
    });

    await t.check("the detailed quote card has the same drill-down under its price", async () => {
      t.need(quote, "no price was produced");
      await steps.openDetailed(t);
      await steps.detailedQuoteToPrice(t, { country: quote.country, kg: quote.kg });
      const d = await t.page.evaluate(() => {
        const el = document.querySelector(".quote details.charges");
        const dl = document.querySelector(".quote dl");
        return el
          ? { open: el.open, summary: el.querySelector("summary").textContent, afterDl: !!dl && dl.nextElementSibling === el }
          : null;
      });
      if (!d) throw new Error("no details.charges in the quote card");
      if (d.open) throw new Error("open by default");
      if (!d.afterDl) throw new Error("not directly under the price list");
      await t.shot("detailed");
      return JSON.stringify(d.summary) + " under the quote's price";
    });

    await t.check("the browser stayed quiet over the whole flow", async () => contract.noConsoleErrors(t));
  },
};
