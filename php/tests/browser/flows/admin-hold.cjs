"use strict";

/**
 * Holding prices, from the admin's side, and proving the public site obeys.
 *
 * This is the flow that makes hold-panel testable: it holds prices, walks the
 * public site to confirm not one figure survives in the HTML, then restores.
 *
 * It touches the live site on purpose, so it is written to be safe to interrupt:
 * the restore runs in a finally block, and if the restore itself fails the check
 * fails LOUDLY with the exact thing a human has to go and undo. A harness that
 * leaves the shop shut and reports success is worse than no harness.
 */

const contract = require("../contract.cjs");
const steps = require("../steps.cjs");
const { adminSession } = require("../harness.cjs");

module.exports = {
  name: "admin-hold",
  order: 22,
  title: "hold prices, confirm the public site shows none, restore",
  viewport: "desktop",

  async run(t) {
    await adminSession(t);

    let holdPath = null;
    let wasHeldBefore = false;
    let weHeldIt = false;

    await t.check("the hold control is in the admin", async () => {
      for (const p of ["/admin/hold", "/admin/rates/review", "/admin"]) {
        // eslint-disable-next-line no-await-in-loop
        const res = await t.page.goto(t.url(p), { waitUntil: "domcontentloaded" });
        if (!res || res.status() !== 200) continue;
        // eslint-disable-next-line no-await-in-loop
        const has = await t.page.evaluate(
          () => !!document.querySelector("[data-hold-open], [data-hold-dialog], .hold-bar") ||
            !!Array.from(document.querySelectorAll("button, a, label")).find((el) => /hold (prices|rates)|pause prices/i.test(el.textContent || "")),
        );
        if (has) {
          holdPath = p;
          break;
        }
      }
      t.need(holdPath, "no hold control found on /admin/hold, /admin/rates/review or /admin - the hold feature has not shipped");
      await t.shot("hold-control");
      return "found on " + holdPath;
    });

    await t.check("the public site is not already held", async () => {
      const res = await t.fetch("/");
      wasHeldBefore = /data-hold-panel/.test(res.body);
      if (wasHeldBefore) {
        t.notReady("prices are ALREADY held on the live site. This flow will not turn them on and off underneath whoever did that - run hold-panel instead.");
      }
      return "prices are live, so this flow may hold them and put them back";
    });

    try {
      await t.check("holding prices takes every figure off the public site", async () => {
        const page = t.page;
        await page.goto(t.url(holdPath), { waitUntil: "domcontentloaded" });

        const open = await page.$("[data-hold-open]");
        if (open) {
          await open.click();
          await page.waitForTimeout(250);
        }

        const note = await page.$("[data-hold-dialog] textarea, textarea[name*=hold i], textarea");
        if (note) await note.fill("Held by the browser harness at " + new Date().toISOString() + " - restoring immediately.");

        const submit = page
          .locator("[data-hold-dialog] button[type=submit], button[type=submit], input[type=submit]")
          .filter({ hasText: /hold|pause|confirm|save/i })
          .first();
        t.need(await submit.count(), "the hold dialog has no submit button");
        await submit.click();
        await page.waitForLoadState("domcontentloaded", { timeout: 25000 }).catch(() => {});
        await page.waitForTimeout(800);

        const res = await t.fetch("/");
        if (!res.ok) throw new Error("the public site stopped answering after holding: " + res.error);
        if (!/data-hold-panel/.test(res.body)) {
          throw new Error("the admin accepted the hold but the public home page still has no hold panel - the hold did not take effect");
        }
        weHeldIt = true;
        await t.shot("held-admin");
        return "held; the public home page now renders the hold panel";
      });

      await t.check("not one price survives anywhere in the public HTML", async () => {
        t.need(weHeldIt, "prices were not held, so there is nothing to scan");
        for (const p of ["/", "/services"]) {
          // eslint-disable-next-line no-await-in-loop
          const res = await t.fetch(p);
          if (!res.ok || res.status !== 200) continue;
          contract.noPriceAnywhereInHtml(res.body);
        }
        return "no currency amount on / or /services";
      });

      await t.check("the held public site still offers a way forward", async () => {
        t.need(weHeldIt, "prices were not held");
        const visitor = await t.browser.newContext({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true, ignoreHTTPSErrors: true });
        try {
          const page = await visitor.newPage();
          t.watch(page);
          await page.goto(t.url("/"), { waitUntil: "load", timeout: 30000 });
          await page.waitForTimeout(500);

          const mount = await page.$(steps.SEL.holdMount);
          if (!mount) throw new Error("the held page has no hold panel in the browser");
          const link = await page.$(steps.SEL.holdMount + " a[href*='wa.me']");
          if (!link) throw new Error("the hold panel offers no WhatsApp link");
          const href = await link.getAttribute("href");
          const text = decodeURIComponent(String(href).split("text=")[1] || "").replace(/\+/g, " ");
          if (/PKR|Rs\.?\s*\d|Price:|Total:/i.test(text)) throw new Error("the held enquiry message quotes a price: " + JSON.stringify(text));
          await page.screenshot({ path: require("path").join(require("../harness.cjs").SHOTS_DIR, "admin-hold--held-phone.png") }).catch(() => {});
          return "the hold panel works at 375px and asks for a rate by hand";
        } finally {
          await visitor.close();
        }
      });

      await t.check("the calculator's own endpoint refuses to price while held", async () => {
        t.need(weHeldIt, "prices were not held");
        const res = await t.fetch(steps.SEL.quoteUrl, {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ destId: "any", service: "express", total: 1234, billableG: 5000 }),
        });
        if (!res.ok) throw new Error("no answer: " + res.error);
        t.need(res.status !== 404, steps.SEL.quoteUrl + " answers 404 - Domain\\Quotes has not shipped");
        if (res.status >= 200 && res.status < 300) {
          // Accepting the log is fine; echoing a price back while held is not.
          if (/"(total|price)"\s*:\s*[1-9]/.test(res.body)) {
            throw new Error("the quote endpoint returned a price while prices are held: " + res.body.slice(0, 160));
          }
        }
        return res.status + ", and no price in the answer";
      });
    } finally {
      /* ---- put it back, whatever happened above --------------------- */

      if (weHeldIt) {
        await t.check("RESTORING: prices are live again", async () => {
          const page = t.page;
          await page.goto(t.url(holdPath), { waitUntil: "domcontentloaded" }).catch(() => {});
          await page.waitForTimeout(300);

          const resume = page
            .locator("button, a, input[type=submit]")
            .filter({ hasText: /resume|unhold|restore prices|put prices back|go live/i })
            .first();

          if (await resume.count()) {
            await resume.click();
            await page.waitForTimeout(300);
            const confirm = page.locator("button").filter({ hasText: /confirm|yes|resume/i }).first();
            if (await confirm.count()) await confirm.click();
            await page.waitForLoadState("domcontentloaded", { timeout: 25000 }).catch(() => {});
            await page.waitForTimeout(800);
          }

          const res = await t.fetch("/");
          if (/data-hold-panel/.test(res.body)) {
            throw new Error(
              [
                "*** THE LIVE SITE IS STILL HOLDING PRICES AND THIS HARNESS PUT IT THERE. ***",
                "",
                "Go to " + t.base + holdPath + " and resume prices NOW.",
                "Customers are currently being asked to enquire by hand.",
              ].join("\n"),
            );
          }
          await t.shot("restored");
          return "prices are live again; the public home page has a calculator, not a hold panel";
        });
      }
    }

    await t.check("the browser stayed quiet over the whole flow", async () => contract.noConsoleErrors(t));
  },
};
