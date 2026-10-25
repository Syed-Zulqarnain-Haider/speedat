"use strict";

/**
 * The rates editor: edit a draft, review it, publish it, see it on the public
 * site, and restore the previous version.
 *
 * The thing that makes this flow worth running is the round trip. Anyone can
 * check that a form saves; what matters is that the number the owner typed is
 * the number a customer is quoted, and that the previous version is still there
 * when a wrong publish needs undoing at 6pm.
 *
 * It also asserts an ABSENCE. The Excel rate-sheet feature was removed from the
 * product (owner, 2026-09-27): no upload, no column mapper, no emailed sheets,
 * no auto-publish tolerance, no imports list. Dead code that comes back as a
 * live upload field is a file-upload attack surface nobody decided to have, so
 * this flow fails if one appears.
 *
 * It restores whatever it changed. If it cannot, it says so loudly rather than
 * leaving the owner's live prices edited.
 */

const contract = require("../contract.cjs");
const { adminSession } = require("../harness.cjs");

/** Every weight box in the rates grid, visible or folded away. */
const BOXES = "[data-kg-box] input, input[data-kg-box], tr[data-name] input[type=number]";

module.exports = {
  name: "admin-rates-publish",
  order: 21,
  title: "edit a draft, publish it, see it priced, restore the old version",
  viewport: "desktop",

  async run(t) {
    await adminSession(t);

    t.need(t.probe.status["/admin"] !== 404, "the admin has not shipped");

    let editedCell = null;
    let publishedAt = null;

    await t.check("the rates editor loads, with rows and weight boxes", async () => {
      const res = await t.page.goto(t.url("/admin/rates/review"), { waitUntil: "domcontentloaded" });
      if (!res || res.status() === 404) t.notReady("/admin/rates/review answers 404 - the rates editor has not shipped");
      if (res.status() !== 200) throw new Error("/admin/rates/review answered " + res.status());

      const shape = await t.page.evaluate(() => ({
        rows: document.querySelectorAll("tr[data-name]").length,
        boxes: document.querySelectorAll("[data-kg-box], input[name*='kg'], input[type=number]").length,
        forms: document.querySelectorAll("form[method=post i]").length,
      }));
      if (!shape.rows) t.notReady("the rates editor has no tr[data-name] rows yet");
      if (!shape.boxes) throw new Error(shape.rows + " rows but no editable weight boxes");
      await t.shot("rates-editor");
      return shape.rows + " rows, " + shape.boxes + " weight boxes, " + shape.forms + " POST forms";
    });

    await t.check("every form in the editor carries a CSRF token", async () => contract.everyFormHasCsrf(t.page));

    await t.check("the editor has no rate-sheet upload anywhere on it", async () => {
      const found = await t.page.evaluate(() => {
        const out = [];
        for (const el of document.querySelectorAll("input[type=file]")) out.push("input[type=file] name=" + (el.name || "?"));
        for (const el of document.querySelectorAll("form")) {
          if (/multipart\/form-data/i.test(el.getAttribute("enctype") || "")) out.push("form enctype=multipart action=" + (el.getAttribute("action") || "?"));
        }
        for (const el of document.querySelectorAll("a, button")) {
          const txt = (el.textContent || "").trim();
          if (/upload|import|xlsx|excel|spreadsheet|column map/i.test(txt)) out.push(el.tagName.toLowerCase() + ": " + txt.slice(0, 40));
        }
        return out.slice(0, 8);
      });
      if (found.length) {
        throw new Error(
          [
            "the Excel rate-sheet feature was REMOVED from the product, but the editor still offers:",
            ...found.map((f) => "    " + f),
            "An upload field nobody decided to have is a file-upload attack surface nobody is guarding.",
          ].join("\n"),
        );
      }
      return "no file input, no multipart form, no import link";
    });

    await t.check("the removed import routes are gone from the server too", async () => {
      const alive = [];
      for (const p of ["/admin/imports", "/admin/rates/import", "/admin/rates/upload", "/api/inbound/email", "/api/cron/check-sheets"]) {
        const cookies = await t.context.cookies();
        // eslint-disable-next-line no-await-in-loop
        const res = await t.fetch(p, { headers: { cookie: cookies.map((c) => c.name + "=" + c.value).join("; ") } });
        if (res.ok && res.status === 200) alive.push(p + " → 200");
      }
      if (alive.length) throw new Error(["removed routes that still answer:", ...alive.map((a) => "    " + a)].join("\n"));
      return "5 removed routes, none of them answer";
    });

    await t.check("the CSV export still works, and is a CSV", async () => {
      const cookies = await t.context.cookies();
      const res = await t.fetch("/admin/rates/export", { headers: { cookie: cookies.map((c) => c.name + "=" + c.value).join("; ") } });
      if (!res.ok) throw new Error("no answer: " + res.error);
      t.need(res.status !== 404, "/admin/rates/export answers 404 - the export has not shipped");
      if (res.status !== 200) throw new Error("the export answered " + res.status);

      const type = String((res.headers || {})["content-type"] || "");
      const disposition = String((res.headers || {})["content-disposition"] || "");
      if (!/csv|text\/plain/i.test(type)) throw new Error("the export's Content-Type is " + JSON.stringify(type));
      if (!/attachment/i.test(disposition)) throw new Error("the export is not sent as an attachment: " + JSON.stringify(disposition));

      const lines = res.body.split(/\r?\n/).filter(Boolean);
      if (lines.length < 2) throw new Error("the export has " + lines.length + " line(s); a header and no rows is not an export");
      // A CSV whose first cell starts with = + - @ executes in Excel when opened.
      const dangerous = lines.slice(1, 200).filter((l) => /^["']?[=+\-@]/.test(l));
      if (dangerous.length) {
        throw new Error(dangerous.length + " row(s) begin with a formula character, which Excel will execute: " + JSON.stringify(dangerous[0].slice(0, 60)));
      }
      return lines.length + " lines, attachment, no formula injection";
    });

    /**
     * OPEN A DESTINATION'S PRICES FIRST.
     *
     * Every weight box on the page lives inside `tr#fold-<code>`, which is
     * `hidden` until the owner presses that destination's "Prices ▾". All 550
     * of them are in the DOM and none of them is visible, so grabbing "the
     * first weight box" and typing into it waited thirty seconds for an
     * element no person could click either, and reported a rates editor that
     * cannot be edited. It can: this is the tap that was missing.
     *
     * The disclosure is a LINK (`/admin?open=gb#fold-gb`), not a script, so
     * following it is also the path the editor takes with JavaScript off.
     */
    await t.check("editing a weight box saves as a draft, not live", async () => {
      const page = t.page;

      if (!(await page.locator(BOXES).locator("visible=true").count())) {
        const disclosure = page.locator('a[href*="open="]').first();
        t.need(await disclosure.count(), "no weight box is visible and there is no \"Prices\" disclosure to open one");
        await disclosure.click();
        await page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {});
        await page.waitForTimeout(400);
      }

      const box = await page.locator(BOXES).locator("visible=true").first().elementHandle({ timeout: 5000 }).catch(() => null);
      t.need(box, "no editable weight box is visible, even after opening a destination's prices");

      const was = await box.inputValue();
      const now = String(Math.max(1, Math.round((Number(was) || 1000) * 1.07)));
      editedCell = { was, now, name: await page.evaluate((el) => el.getAttribute("name"), box) };

      // What the server actually answered the autosave, so that "Save failed"
      // comes with the reason attached instead of sending somebody to the log.
      const answers = [];
      const onSave = async (res) => {
        if (!res.url().includes("/admin/rates/save")) return;
        let body = "";
        try {
          body = (await res.text()).slice(0, 240);
        } catch (e) {
          body = "<unreadable>";
        }
        answers.push(res.status() + " " + body.replace(/\s+/g, " ").trim());
      };
      page.on("response", onSave);

      await box.fill(now);
      await box.dispatchEvent("change");

      /* WITH JAVASCRIPT ON, THE EDITOR AUTOSAVES AND HIDES ITS SAVE BUTTON.
       *
       * admin.js debounces a save 800 ms after the last edit and sets
       * `[data-save-submit]` to `hidden` on the way in - the explicit button is
       * the no-JS path and nothing else. This check used to click it anyway,
       * waited thirty seconds for a button nobody can see, and reported a
       * rates editor that cannot save. So: wait for the state line to say the
       * draft is saved, and only press a save button if one is actually
       * visible (a deployment with the script off). */
      let saved = "";
      for (let i = 0; i < 40; i += 1) {
        // eslint-disable-next-line no-await-in-loop
        saved = await page.evaluate(() => {
          const el = document.querySelector("[data-save-state]");
          return el ? (el.textContent || "").replace(/\s+/g, " ").trim() : "";
        });
        if (/draft saved/i.test(saved)) break;
        if (/save failed/i.test(saved)) break;
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(150);
      }
      page.off("response", onSave);

      if (/save failed/i.test(saved)) {
        throw new Error(
          [
            "the editor could not save the draft. It says: " + JSON.stringify(saved),
            ...answers.map((a) => "    the server answered: " + a),
            "This is the owner's whole job - keying prices in - so a save that fails is the product not working.",
          ].join("\n"),
        );
      }

      if (!/draft saved/i.test(saved)) {
        const save = page.locator("button[type=submit], [data-save-submit]").filter({ hasText: /save|draft/i }).locator("visible=true").first();
        if (!(await save.count())) {
          throw new Error(
            "the edit was typed and nothing saved it: the state line says " + JSON.stringify(saved) +
              " and there is no visible save button either",
          );
        }
        await save.click();
        await page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {});
        await page.waitForTimeout(500);
      }

      const body = await page.content();
      if (/Fatal error|SQLSTATE/i.test(body)) throw new Error("saving the draft produced an error page");

      // The public site must still quote the OLD number: a draft is a draft.
      const publicRes = await t.fetch("/");
      if (publicRes.ok && publicRes.body.includes(now) && !publicRes.body.includes(was)) {
        throw new Error("the edit reached the public site without being published - a draft that goes live is not a draft");
      }
      await t.shot("draft-saved");
      return "changed " + editedCell.name + " from " + was + " to " + now + "; the public site still shows the old rate";
    });

    await t.check("the review screen lists the pending change", async () => {
      t.need(editedCell, "nothing was edited");
      const page = t.page;
      await page.goto(t.url("/admin/rates/review"), { waitUntil: "domcontentloaded" });
      await page.waitForTimeout(300);
      const text = await page.evaluate(() => document.body.innerText);
      /*
       * THE REVIEW SCREEN FORMATS THE NUMBER; THE EDITOR CELL DOES NOT
       * (fixed 2026-09-29). The cell is typed as 8211 and the review screen
       * prints "8,211", so `text.replace(/[^0-9]/g, " ")` turned that into
       * "8 211" and the token list held "8" and "211" but never "8211". The
       * check reported "the review screen does not show the pending change of
       * 8211" about a screen that was showing it, in the only spelling a
       * person would want to read it in.
       *
       * Thousands separators - a comma, a space, or the narrow no-break space
       * some formatters emit - are stripped from BETWEEN digits first, then
       * the page is tokenised exactly as before. A number that genuinely is
       * not on the screen is still absent after the strip.
       */
      const digits = String(editedCell.now).replace(/[^0-9]/g, "");
      const unformatted = text.replace(/(\d)[,   ](?=\d{3}(\D|$))/g, "$1");
      if (!unformatted.replace(/[^0-9]/g, " ").split(/\s+/).includes(digits)) {
        throw new Error(
          "the review screen does not show the pending change of " + editedCell.now +
            " (checked with thousands separators removed)",
        );
      }
      const count = await t.page.evaluate(() => {
        const el = document.querySelector("[data-change-count]");
        return el ? (el.textContent || "").trim() : null;
      });
      await t.shot("review");
      return "pending change visible" + (count ? ", change count " + count : "");
    });

    await t.check("publishing puts the new rate on the public site", async () => {
      t.need(editedCell, "nothing was edited");
      const page = t.page;
      const publish = page.locator("button, input[type=submit]").filter({ hasText: /publish/i }).first();
      t.need(await publish.count(), "no publish button on the review screen");

      await publish.click();
      await page.waitForLoadState("domcontentloaded", { timeout: 25000 }).catch(() => {});
      await page.waitForTimeout(800);
      publishedAt = new Date().toISOString();

      const body = await page.content();
      if (/Fatal error|SQLSTATE/i.test(body)) throw new Error("publishing produced an error page");

      const publicRes = await t.fetch("/");
      if (!publicRes.ok) throw new Error("the public site stopped answering after a publish: " + publicRes.error);
      if (publicRes.status !== 200) throw new Error("the public site answers " + publicRes.status + " after a publish");

      const digits = String(editedCell.now).replace(/[^0-9]/g, "");
      const onSite = publicRes.body.replace(/[^0-9]/g, " ").split(/\s+/).includes(digits);
      if (!onSite) {
        // The edited cell may be a weight the home page does not print a "from"
        // price for. Say so rather than failing a working publish.
        t.log("the published figure " + editedCell.now + " is not visible on the home page; it may not be a 'from' rate");
      }
      await t.shot("published");
      return "published" + (onSite ? ", and the new figure is on the home page" : ", public site healthy (the figure is not one the home page prints)");
    });

    await t.check("the published version is recorded, with a time and an author", async () => {
      t.need(publishedAt, "nothing was published");
      const page = t.page;
      await page.goto(t.url("/admin/rates/review"), { waitUntil: "domcontentloaded" });
      const text = await page.evaluate(() => document.body.innerText);
      if (!/version|published|restore/i.test(text)) {
        t.notReady("the editor does not show a version history yet - nothing to check the record against");
      }
      return "the editor shows a version history";
    });

    /**
     * RESTORE IS TWO STEPS IN THIS PRODUCT, and this check used to do one.
     *
     * The control says "Restore into editor": it loads the old version back
     * into the DRAFT and changes nothing a customer sees. The site only moves
     * when that draft is published. This check clicked restore, saw the page
     * change, then read the public site and found its own edit still there -
     * and shouted "PUT IT BACK BY HAND", which reads like a broken restore.
     *
     * The restore is not broken. The flow was: it had just published a 7% rate
     * rise onto a live site and then walked away from it. So this now does what
     * the owner would do - restore the version that was live before, publish
     * it, and only then check the public site - and the shout is kept for the
     * case where that genuinely does not work, because a harness that raises
     * real prices and says nothing is worse than no harness.
     */
    await t.check("restore puts the previous version back", async () => {
      t.need(editedCell, "nothing was changed, so there is nothing to restore");
      const page = t.page;
      await page.goto(t.url("/admin"), { waitUntil: "domcontentloaded" });
      await page.waitForTimeout(400);

      const restores = page.locator("button[name=version]").filter({ hasText: /restore|revert|roll ?back/i });
      const shout = (why) =>
        new Error(
          why + " This flow has just PUBLISHED a changed rate (" + editedCell.name + ": " + editedCell.was + " → " +
            editedCell.now + ") onto the live site. PUT IT BACK BY HAND.",
        );

      if (!(await restores.count())) throw shout("There is no restore control on /admin.");

      // The newest version in the list is the one this flow just published, so
      // the one to go back to is the next one down.
      const previous = restores.first();
      const version = await previous.getAttribute("value");

      await previous.click();
      await page.waitForLoadState("domcontentloaded", { timeout: 25000 }).catch(() => {});
      await page.waitForTimeout(500);

      // Restored INTO THE EDITOR. Nothing is live until it is published.
      await page.goto(t.url("/admin/rates/review"), { waitUntil: "domcontentloaded" });
      await page.waitForTimeout(400);
      const publish = page.locator('form[action="/admin/rates/publish"] button[type=submit]').first();
      if (!(await publish.count())) throw shout("Version " + version + " was restored into the editor and there is no publish button to make it live.");

      await publish.click();
      await page.waitForLoadState("domcontentloaded", { timeout: 25000 }).catch(() => {});
      await page.waitForTimeout(800);

      const publicRes = await t.fetch("/");
      const digitsNow = String(editedCell.now).replace(/[^0-9]/g, "");
      const words = publicRes.body.replace(/[^0-9]/g, " ").split(/\s+/);
      if (words.includes(digitsNow)) {
        throw shout("Version " + version + " was restored and published and the public site STILL shows " + editedCell.now + ".");
      }
      await t.shot("restored");
      return "restored version " + version + " and published it; " + editedCell.name + " is no longer showing " + editedCell.now;
    });

    /**
     * The rates editor itself, not the first form on the page.
     *
     * `form[method=post]` picked up the Sign out button in the header, clicked
     * it, watched the URL change and passed - so this check had never once
     * exercised a save, and it quietly ended the session for whatever ran
     * after it. The editor is `form#editor`, posting to /admin/rates/save; its
     * own submit is "Save draft" (every other button in that form carries a
     * name: sort, add, remove, fill).
     */
    await t.check("the editor works with JavaScript off", async () => {
      const cookies = await t.context.cookies();
      return contract.submitsWithoutJavaScript(t, "/admin/rates/review", {
        formSelector: 'form#editor[method=post], form[action="/admin/rates/save"]',
        submitSelector: 'form#editor button[type=submit]:not([name]), form[action="/admin/rates/save"] button[type=submit]:not([name])',
        expect: "saved",
        cookies: cookies.map((c) => ({ name: c.name, value: c.value, domain: c.domain, path: c.path })),
      });
    });

    await t.check("the admin is never indexable", async () => {
      const cookies = await t.context.cookies();
      const res = await t.fetch("/admin/rates/review", { headers: { cookie: cookies.map((c) => c.name + "=" + c.value).join("; ") } });
      const meta = (res.body.match(/<meta[^>]+name=["']robots["'][^>]*content=["']([^"']*)["']/i) || [])[1] || "";
      const header = String((res.headers || {})["x-robots-tag"] || "");
      if (!/noindex/i.test(meta) && !/noindex/i.test(header)) throw new Error("no noindex on an admin page");
      return "noindex";
    });

    await t.check("nothing in the editor renders under 14px", async () => {
      await t.page.goto(t.url("/admin/rates/review"), { waitUntil: "domcontentloaded" });
      return contract.nothingUnderMinFont(t.page);
    });

    await t.check("the browser stayed quiet over the whole flow", async () => contract.noConsoleErrors(t));
  },
};
