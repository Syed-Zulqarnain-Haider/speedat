"use strict";

/**
 * The hold panel: what the site shows when the owner has taken prices down.
 *
 * The one rule, and it is absolute: while prices are held there is NO price
 * anywhere in the HTML. Not greyed out, not display:none, not in the JSON blob
 * the calculator reads, not in a data attribute, not in a comment. A held price
 * that is merely hidden is a price a browser extension, a screen reader, a
 * "view source", or a Google cache will publish for you.
 *
 * The site still has to work: the visitor picks a country and a weight and gets
 * a WhatsApp message asking for a rate by hand. Taking prices down must not take
 * the business down with them.
 *
 * Turning the hold on is an admin action, so this flow needs either a site that
 * is already held (it detects that) or admin credentials to hold it, restore it
 * and put it back. It never leaves a site held.
 */

const contract = require("../contract.cjs");
const steps = require("../steps.cjs");

module.exports = {
  name: "hold-panel",
  order: 13,
  title: "prices held: a way forward, and not one number in the HTML",
  viewport: "phone",

  async run(t) {
    /* ---- is the site held right now? --------------------------------- */

    const home = await t.fetch("/");
    t.need(home.ok && home.status === 200, "the home page is not being served");

    const held = /data-hold-panel/.test(home.body);
    if (!held) {
      if (!/data-calculator/.test(home.body)) {
        t.notReady("the home page has neither a calculator nor a hold panel - the site views have not shipped");
      }
      t.notReady(
        "prices are live, so there is no hold panel to test. Hold them in the admin (Rates → hold) and run this flow again, " +
          "or run admin-hold, which holds, checks and restores in one go.",
      );
    }

    await t.open("/");
    await t.page.waitForLoadState("load", { timeout: 20000 }).catch(() => {});
    await t.page.waitForTimeout(400);

    /* ---- the rule --------------------------------------------------- */

    await t.check("the server's HTML contains no price at all", async () => {
      const res = await t.fetch("/");
      return contract.noPriceAnywhereInHtml(res.body);
    });

    await t.check("the rendered page contains no price either", async () => {
      // Belt and braces: the DOM after JavaScript has run, in case the blob
      // carried something the raw-HTML scan did not recognise as money.
      const text = await t.page.evaluate(() => document.body.innerText);
      const money = text.match(/\b(PKR|Rs\.?)\s*[0-9][0-9,.]*/gi) || [];
      if (money.length) throw new Error("the rendered page shows " + money.length + " amount(s): " + money.slice(0, 5).join(", "));
      return "no amount in the rendered text";
    });

    await t.check("no price survives in the page's JSON or its attributes", async () => {
      const leaks = await t.page.evaluate(() => {
        const found = [];
        for (const el of document.querySelectorAll("*")) {
          for (const attr of el.attributes || []) {
            if (/\b(PKR|Rs\.?)\s*[0-9]/i.test(attr.value) || /"(total|price|amount|rate)"\s*:\s*[0-9]/.test(attr.value)) {
              found.push(el.tagName.toLowerCase() + "[" + attr.name + "]");
            }
          }
        }
        for (const s of document.querySelectorAll("script")) {
          if (/"(total|price|amount|grandTotal)"\s*:\s*[0-9]/.test(s.textContent || "")) found.push("inline <script>");
        }
        return found.slice(0, 6);
      });
      if (leaks.length) throw new Error("a price is hiding in: " + leaks.join(", "));
      return "no amount in any attribute or inline script";
    });

    await t.check("the calculator is not merely hidden, it is absent", async () => {
      const both = await t.page.evaluate(
        (sel) => ({
          hold: !!document.querySelector(sel.holdMount),
          calc: !!document.querySelector(sel.mount),
          tiles: document.querySelectorAll(sel.countryTile).length,
        }),
        steps.SEL,
      );
      if (!both.hold) throw new Error("no hold panel on the page");
      if (both.calc || both.tiles) {
        throw new Error(
          "the calculator mount is still in the page while prices are held (" + both.tiles + " country tiles) - it is hidden, not removed",
        );
      }
      return "hold panel present, calculator mount absent";
    });

    /* ---- and it still works ------------------------------------------ */

    await t.check("the hold panel explains itself", async () => {
      const said = await t.page.evaluate((sel) => {
        const mount = document.querySelector(sel);
        return mount ? (mount.innerText || "").replace(/\s+/g, " ").trim() : "";
      }, steps.SEL.holdMount);
      if (!said) throw new Error("the hold panel renders nothing");
      if (!/whatsapp|rate|price/i.test(said)) {
        throw new Error("the hold panel does not tell the visitor what to do: " + JSON.stringify(said.slice(0, 160)));
      }
      await t.shot("held");
      return JSON.stringify(said.slice(0, 120));
    });

    await t.check("country and weight still reach a WhatsApp message", async () => {
      const page = t.page;
      const select = await page.$(steps.SEL.holdMount + " select");
      const weight = await page.$(steps.SEL.holdMount + " input[type=number]");
      t.need(select && weight, "the hold panel has no country select and weight input yet");

      const options = await page.$$eval(steps.SEL.holdMount + " select option", (els) =>
        els.filter((e) => e.value).map((e) => ({ v: e.value, t: e.textContent.trim() })),
      );
      t.need(options.length, "the hold panel's country list is empty");

      await select.selectOption(options[0].v);
      await weight.fill("2.5");
      await weight.dispatchEvent("input");
      await page.waitForTimeout(300);

      const href = await page.getAttribute(steps.SEL.holdMount + " a.btn.wa, " + steps.SEL.holdMount + " a[href*='wa.me']", "href");
      const text = decodeURIComponent(String(href || "").split("text=")[1] || "").replace(/\+/g, " ");

      if (!text) throw new Error("the WhatsApp link carries no message");
      if (!new RegExp("To:\\s*" + options[0].t.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"), "i").test(text)) {
        throw new Error("the message does not name the country: " + JSON.stringify(text));
      }
      if (!/2\.5\s*kg/i.test(text)) throw new Error("the message does not carry the weight: " + JSON.stringify(text));
      if (/Price:|Total:|PKR|Rs\.?\s*\d/i.test(text)) {
        throw new Error("the by-hand enquiry quotes a price, and there is no price while they are held: " + JSON.stringify(text));
      }
      await t.shot("held-ready");
      return options[0].t + " at 2.5 kg → " + text.split("\n").length + " lines, no figure";
    });

    await t.check("a nonsense weight is refused, and the link still works", async () => {
      const page = t.page;
      const weight = await page.$(steps.SEL.holdMount + " input[type=number]");
      t.need(weight, "no weight input");
      await weight.fill("-3");
      await weight.dispatchEvent("input");
      await page.waitForTimeout(250);
      const hint = await page.evaluate(
        (sel) => Array.from(document.querySelectorAll(sel + " .hint")).map((e) => (e.textContent || "").trim()).filter(Boolean).join(" | "),
        steps.SEL.holdMount,
      );
      if (!/above 0|enter a weight|valid/i.test(hint)) {
        throw new Error("a weight of -3 drew no complaint; the hints say: " + JSON.stringify(hint));
      }
      const href = await page.getAttribute(steps.SEL.holdMount + " a[href*='wa.me']", "href");
      if (!href) throw new Error("the WhatsApp link disappeared when the weight was invalid");
      const text = decodeURIComponent(String(href).split("text=")[1] || "").replace(/\+/g, " ");
      if (/-3/.test(text)) throw new Error("the message carries the invalid weight -3");
      await weight.fill("2.5");
      await weight.dispatchEvent("input");
      return "refused with a hint, and the link still has somewhere to go";
    });

    /* ---- the rest of the page still behaves -------------------------- */

    await t.check("the held page fits 375px and reads at 14px", async () => {
      await contract.noHorizontalScroll(t.page);
      return contract.nothingUnderMinFont(t.page);
    });

    await t.check("the held page is still a real, indexable page", async () => {
      const res = await t.fetch("/");
      if (res.status !== 200) throw new Error("the held home page answers " + res.status);
      if (/<meta[^>]+name=["']robots["'][^>]*noindex/i.test(res.body)) {
        throw new Error("the held page is marked noindex - holding prices for an afternoon would drop the site from search");
      }
      const h1s = (res.body.match(/<h1[\s>]/gi) || []).length;
      if (h1s !== 1) throw new Error("the held page has " + h1s + " <h1> elements");
      return "200, indexable, one h1";
    });

    await t.check("the browser stayed quiet over the whole flow", async () => contract.noConsoleErrors(t));
  },
};
