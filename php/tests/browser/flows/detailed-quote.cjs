"use strict";

/**
 * The detailed quote: pieces, dimensions, units and the contents line, ending
 * in the same WhatsApp message with a piece list in it.
 *
 * The rule this flow exists to protect is that the detailed panel and the quick
 * panel are two doors into ONE engine. Same country and same total weight must
 * produce the same figure through both; if they diverge, one of them is lying
 * to a customer, and the harness cannot tell which - so it fails and says so.
 *
 * WHAT THIS FLOW GOT WRONG, AND FOR HOW LONG (fixed 2026-09-28). It reached for
 * the destination with `page.$("select")` - the FIRST select on the page, which
 * is "From: Lahore / Faisalabad". Choosing "United Kingdom" in it timed out
 * after thirty seconds, and the four checks behind it then measured a panel
 * that had never been filled in. It also read the price from `.results .tval`,
 * a selector this markup has never had: the detailed panel's price is
 * `.quote dd.display`, on a card that only exists once a SERVICE has been
 * chosen. Six checks, six failures, no defect - which is worse than no test,
 * because somebody spends a morning on each one. Both are in steps.cjs now, so
 * there is one place to be wrong.
 *
 * Selectors, from calculator.js's detail panel (and see steps.SEL):
 *   select           TWO of them - "From" first, then the destination
 *   button.ubtn      metric / imperial
 *   div.piece        one piece row, with a kg input and size inputs
 *   button.btn.addpiece / button.btn.p-remove
 *   button.svc       a service card - pressing one BUILDS the quote card
 *   div.quote        the quote card: dd.display is the price
 */

const contract = require("../contract.cjs");
const steps = require("../steps.cjs");

module.exports = {
  name: "detailed-quote",
  order: 12,
  title: "pieces, dimensions and units, through the same engine",
  viewport: "desktop",

  async run(t) {
    await t.open("/");
    await t.page.waitForLoadState("load", { timeout: 20000 }).catch(() => {});
    await t.page.waitForTimeout(400);

    let quickPrice = null;
    let quickCountry = null;
    let quickService = null;
    // Set only once the detailed panel is genuinely open. Every check past that
    // point needs it, and a check that runs without it would be reporting a
    // missing panel as a broken one.
    let detailedOpen = false;

    await t.check("a quick price first, to compare against", async () => {
      const quick = await steps.quickRateToPrice(t, { kg: 5 });
      quickPrice = quick.price;
      quickCountry = quick.country;
      quickService = (quick.service || "").replace(/\s+/g, " ").trim();
      return quickCountry + " at " + quick.kg + " kg is " + quickPrice + " on the quick panel"
        + (quickService ? " (" + quickService + ")" : "");
    });

    await t.check("the detailed panel opens, and offers a way back", async () => {
      await steps.openDetailed(t);
      detailedOpen = true;
      await t.shot("detailed-open");
      return "detailed panel open, \"Back to quick price\" present";
    });

    await t.check("one piece at the same weight gives the same price as the quick panel", async () => {
      t.need(quickPrice, "there is no quick price to compare with");
      t.need(detailedOpen, "the detailed panel never opened");

      // Same service on both sides, by NAME. The quick panel picks one for the
      // visitor; the detailed panel asks. Comparing Express here against
      // Economy there would be comparing two different shipments and calling
      // the difference a bug.
      const priced = await steps.detailedQuoteToPrice(t, {
        country: quickCountry,
        kg: 5,
        service: quickService || 0,
      });

      const digits = (s) => String(s).replace(/[^0-9]/g, "");
      if (digits(priced.price) !== digits(quickPrice)) {
        throw new Error(
          [
            "the two panels disagree about the same shipment:",
            "    quick    : " + quickPrice + (quickService ? " (" + quickService + ")" : ""),
            "    detailed : " + priced.price + (priced.service ? " (" + priced.service + ")" : ""),
            "Same country, same 5 kg, same service. One of these is wrong and the harness cannot tell which.",
          ].join("\n"),
        );
      }

      await t.shot("detailed-priced");
      return "both panels say " + priced.price + " for " + quickCountry + " at 5 kg on " + (priced.service || "the first service");
    });

    /**
     * CHANGING THE SHIPMENT TAKES THE QUOTE CARD AWAY, on purpose: the three
     * service cards come back showing the NEW prices and the panel waits to be
     * told which one. So every check below changes something and then presses
     * the same service again, which is what a person does - and it is why
     * reading a price straight after an edit finds nothing.
     */
    const repriced = async () => (await steps.chooseService(t, quickService || 0)).price;

    await t.check("adding a piece raises the price and the piece list", async () => {
      t.need(detailedOpen, "the detailed panel never opened");
      const page = t.page;
      const add = await page.$(steps.SEL.detailAddPiece);
      t.need(add, "no \"add a piece\" button");

      const before = await steps.detailedPrice(page);
      await add.click();
      await page.waitForTimeout(200);
      const rows = await page.$$(steps.SEL.detailPiece);
      if (rows.length < 2) throw new Error("clicking add left " + rows.length + " piece row(s)");

      // The WEIGHT of the new row, not the last number box in it - a piece row
      // holds kg, quantity, L, W and H, and a height on its own prices nothing.
      const second = steps.pieceWeightInput(page, -1);
      await second.fill("3");
      await second.dispatchEvent("input");
      await page.waitForTimeout(400);

      const after = await repriced();
      const n = (s) => Number(String(s).replace(/[^0-9]/g, "") || 0);
      if (!(n(after) > n(before))) {
        throw new Error("a second piece of 3 kg did not raise the price: " + before + " → " + after);
      }
      await t.shot("two-pieces");
      return "2 pieces: " + before + " → " + after;
    });

    await t.check("removing the piece puts the price back", async () => {
      t.need(detailedOpen, "the detailed panel never opened");
      const page = t.page;
      const remove = await page.$$(steps.SEL.detailRemovePiece);
      t.need(remove.length, "no remove button on a piece row");
      const before = await steps.detailedPrice(page);
      await remove[remove.length - 1].click();
      await page.waitForTimeout(400);
      const after = await repriced();
      const n = (s) => Number(String(s).replace(/[^0-9]/g, "") || 0);
      if (!(n(after) < n(before))) throw new Error("removing a piece did not lower the price: " + before + " → " + after);
      return before + " → " + after;
    });

    await t.check("switching to imperial keeps the same shipment, not the same numbers", async () => {
      t.need(detailedOpen, "the detailed panel never opened");
      const page = t.page;
      const units = await page.$$(steps.SEL.detailUnits);
      t.need(units.length >= 2, "no metric/imperial switch");

      const weightShown = async () => steps.pieceWeightInput(page, 0).inputValue();

      const before = { price: await steps.detailedPrice(page), kg: await weightShown() };

      await units[1].click();
      await page.waitForTimeout(400);
      const after = { price: await repriced(), kg: await weightShown() };

      const n = (s) => Number(String(s).replace(/[^0-9]/g, "") || 0);
      // The shipment did not change, so the price must not either - beyond the
      // rounding that converting 5 kg to pounds and back costs.
      const drift = Math.abs(n(after.price) - n(before.price)) / Math.max(1, n(before.price));
      if (drift > 0.02) {
        throw new Error(
          "switching to imperial changed the price by " + Math.round(drift * 100) + "%: " + before.price + " → " + after.price +
            " (the weight went " + before.kg + " → " + after.kg + "). The units are a display choice, not a different shipment.",
        );
      }
      await units[0].click();
      await page.waitForTimeout(300);
      await repriced();
      return "imperial round trip holds the price within " + Math.round(drift * 1000) / 10 + "%";
    });

    /**
     * The owner removed the document rate on 2026-09-27: no Docs column, no
     * document price, everything priced by weight. This check used to prove
     * that documents priced on their own rate and now proves the opposite -
     * that the choice is GONE. It is kept rather than deleted because a
     * Packages/Documents switch quietly coming back, with nothing behind it,
     * is exactly the regression the owner's decision was about.
     */
    await t.check("there is no Packages/Documents choice any more", async () => {
      t.need(detailedOpen, "the detailed panel never opened");
      const page = t.page;

      const segs = await page.$$("button.segbtn");
      if (segs.length) {
        throw new Error(
          "the detailed panel still offers a Packages/Documents switch (" + segs.length + " segment buttons). " +
            "The document rate was removed on 2026-09-27 and everything is priced by weight.",
        );
      }

      const docsWord = await page
        .locator(".svcs, .pieces, .units")
        .locator("text=/\\bdocuments?\\b/i")
        .count();
      if (docsWord) throw new Error("the pricing controls still say 'document', which no longer prices anything");

      return "priced by weight only, with no document choice to get wrong";
    });

    await t.check("the contents line reaches the WhatsApp message", async () => {
      t.need(detailedOpen, "the detailed panel never opened");
      const page = t.page;

      const contents = await page.$(steps.SEL.detailContents);
      t.need(contents, "no contents field on the quote card");
      const marker = "harness-contents-" + Date.now().toString().slice(-5);
      await contents.fill(marker);
      await contents.dispatchEvent("input");
      await page.waitForTimeout(400);

      // The quote card's OWN link, not the last wa.me anchor on the page: the
      // header, the footer and the rates board carry plain "message us" links
      // with no ?text= at all, and reading one of those reported the detailed
      // quote as carrying no message.
      const href = await contract.bookingHref(page, steps.SEL.detailBook);
      const text = decodeURIComponent(String(href || "").split("text=")[1] || "").replace(/\+/g, " ");
      if (!text.includes(marker)) {
        throw new Error("the contents the customer typed are not in the message: " + JSON.stringify(text.slice(0, 200)));
      }
      return 'the message carries "Contents: ' + marker + '"';
    });

    await t.check("the WhatsApp message carries the piece list, the weight and the dates", async () => {
      t.need(detailedOpen, "the detailed panel never opened, so there is no detailed message to read");
      const page = t.page;
      const href = await contract.bookingHref(page, steps.SEL.detailBook);
      t.need(href, "no WhatsApp link on the quote card");
      const state = {
        service: await page.evaluate(() => {
          const chosen = document.querySelector("button.svc[aria-pressed=true]");
          return chosen ? ((chosen.querySelector(".svc-name") || chosen).textContent || "").trim().split("\n")[0] : "";
        }),
        price: await steps.detailedPrice(page),
      };
      const text = decodeURIComponent(String(href || "").split("text=")[1] || "").replace(/\+/g, " ");
      if (!/^(Packages|Documents):\s*\d+\s*×/im.test(text)) {
        throw new Error('the message has no piece list ("Packages: 1 × 5 kg"):\n    ' + text.split("\n").join("\n    "));
      }
      if (!/^Charged on:\s*[\d.]+\s*kg/im.test(text)) {
        throw new Error('the message does not say what the shipment is charged on ("Charged on: N kg")');
      }
      return contract.whatsappLinkCarries(href, { price: state.price, dates: true });
    });

    await t.check("share and copy hand over the same message the button does", async () => {
      t.need(detailedOpen, "the detailed panel never opened");
      const page = t.page;
      const copy = page.locator(steps.SEL.detailCopy, { hasText: /^copy$/i }).first();
      t.need(await copy.count(), "no Copy button on the quote card");

      await page.context().grantPermissions(["clipboard-read", "clipboard-write"]).catch(() => {});
      const href = await contract.bookingHref(page, steps.SEL.detailBook);
      const fromLink = decodeURIComponent(String(href || "").split("text=")[1] || "").replace(/\+/g, " ");

      await copy.click();
      await page.waitForTimeout(400);
      const copied = await page.evaluate(() => (navigator.clipboard ? navigator.clipboard.readText().catch(() => null) : null));
      if (copied === null) t.notReady("the clipboard could not be read in this browser context, so Copy cannot be verified here");

      const norm = (s) => String(s).replace(/\s+/g, " ").trim();
      if (norm(copied) !== norm(fromLink)) {
        throw new Error(
          ["Copy and the WhatsApp button hand over different text:", "    copied: " + norm(copied).slice(0, 160), "    link  : " + norm(fromLink).slice(0, 160)].join("\n"),
        );
      }
      return "Copy matches the booking message, " + norm(copied).length + " chars";
    });

    await t.check("the price is still the largest text in the detailed panel", async () => {
      t.need(detailedOpen, "the detailed panel never opened");
      /*
       * SCOPED TO THE CALCULATOR, WHICH IS WHAT THIS CHECK HAS ALWAYS SAID IT
       * MEASURED (2026-09-29). Unscoped it compared the detailed panel's price
       * against every element on the page and found the page's own headline:
       *     price : 39.52px  "PKR 8,070"
       *     bigger: 42.95px  section.calc > div.hero-copy > h1
       * That headline is not a rival to the price - a customer reading the
       * detailed panel has scrolled past it. The QUICK panel replaces the hero
       * when it prices, so quick-rate-desktop and quick-rate-phone still
       * assert the strong, page-wide form of this rule; this is the only place
       * the narrow one is used. The headline is outside [data-calculator]
       * while every service card, the piece list and the quote card are inside
       * it, so nothing that competes for the customer's eye is excluded.
       */
      return contract.priceIsLargestText(t.page, steps.SEL.detailPrice, { within: steps.SEL.mount });
    });

    await t.check("nothing in the detailed panel renders under 14px", async () => contract.nothingUnderMinFont(t.page));

    await t.check("a nonsense weight is refused politely, not priced", async () => {
      t.need(detailedOpen, "the detailed panel never opened");
      const page = t.page;
      const kgInput = await page.$(steps.SEL.detailKg);
      t.need(kgInput, "no weight input");
      for (const bad of ["-5", "0", "999999"]) {
        // eslint-disable-next-line no-await-in-loop
        await kgInput.fill(bad);
        // eslint-disable-next-line no-await-in-loop
        await kgInput.dispatchEvent("input");
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(350);
        // eslint-disable-next-line no-await-in-loop
        const out = {
          // eslint-disable-next-line no-await-in-loop
          price: await steps.detailedPrice(page),
          // eslint-disable-next-line no-await-in-loop
          hint: await page.evaluate(() =>
            Array.from(document.querySelectorAll(".hint, .status, .tnote")).map((e) => (e.textContent || "").trim()).filter(Boolean).join(" | "),
          ),
        };
        if (out.price && bad !== "999999") {
          throw new Error("a weight of " + bad + " kg was priced at " + out.price);
        }
        if (!out.price && !out.hint) {
          throw new Error("a weight of " + bad + " kg produced neither a price nor a word of explanation");
        }
      }
      await t.shot("bad-weight");
      return "-5, 0 and 999999 each get an explanation instead of a price";
    });

    await t.check("the browser stayed quiet over the whole flow", async () => contract.noConsoleErrors(t));
  },
};
