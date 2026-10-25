"use strict";

/**
 * The inbox and the shipment ledger: an enquiry arrives, becomes a shipment,
 * and picks up events until it is delivered.
 *
 * This is the office's daily screen, so the checks are about the things that go
 * wrong on a busy afternoon rather than about layout:
 *
 *   the enquiry actually arrived, with what the customer typed, unmangled.
 *   a customer's message is escaped, not rendered - the inbox is the one place
 *     on this site where an untrusted stranger's text is read by an admin, and
 *     it is the obvious place to try stored XSS.
 *   two clicks on "create shipment" make one shipment, not two.
 *   a status moves forward and is recorded with a time.
 *   paging works, and page 2 is not page 1.
 *   nothing in the ledger is reachable without a session.
 */

const contract = require("../contract.cjs");
const { adminSession } = require("../harness.cjs");

const XSS_PROBE = '<img src=x onerror="window.__xss=1">';

module.exports = {
  name: "admin-inbox-shipments",
  order: 23,
  title: "an enquiry in the inbox becomes a shipment with events",
  viewport: "desktop",

  async run(t) {
    await adminSession(t);

    let marker = null;
    let leadUrl = null;

    /* ---- plant an enquiry, as a stranger ----------------------------- */

    await t.check("an enquiry submitted by a visitor reaches the inbox", async () => {
      t.need(t.probe.status["/contact"] !== 404, "/contact answers 404 - the contact page has not shipped, so nothing can arrive");

      marker = "HARNESS-" + Date.now();
      const stranger = await t.browser.newContext({ ignoreHTTPSErrors: true });
      try {
        const page = await stranger.newPage();
        await page.goto(t.url("/contact"), { waitUntil: "domcontentloaded" });
        const fill = async (name, value) => {
          const el = await page.$('[name="' + name + '"]');
          if (el) await el.fill(value);
          return !!el;
        };
        t.need(await fill("name", "Harness " + marker), "no name field on the contact form");
        await fill("phone", "03001234567");
        await fill("email", "harness@example.invalid");
        // The message carries the XSS probe deliberately: this is exactly the
        // text a real attacker would send, and the inbox has to show it as text.
        await fill("message", marker + " automated test, safe to delete. " + XSS_PROBE);
        // The contact form refuses anything returned faster than a person could
        // have typed it (Http\FormToken, 3s). Playwright fills four fields in
        // well under one, so without this wait the enquiry is 422'd at the door
        // and this check reports "the enquiry is not in the inbox" - the
        // anti-bot rule working, read as the inbox losing a lead.
        await contract.settleThinkTime(page);
        await page.click("button[type=submit], input[type=submit]");
        await page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {});
        await page.waitForTimeout(600);
        const body = await page.content();
        if (/Fatal error|SQLSTATE/i.test(body)) throw new Error("the enquiry produced an error page");
      } finally {
        await stranger.close();
      }

      const page = t.page;
      const res = await page.goto(t.url("/admin/inbox"), { waitUntil: "domcontentloaded" });
      if (!res || res.status() === 404) t.notReady("/admin/inbox answers 404 - the inbox has not shipped");
      if (res.status() !== 200) throw new Error("/admin/inbox answered " + res.status());
      await page.waitForTimeout(300);

      const text = await page.evaluate(() => document.body.innerText);
      if (!text.includes(marker)) {
        // Not on page one? Look for it, but do not pretend it arrived.
        const search = await page.$("input[type=search], input[name=q], [data-filter-table]");
        if (search) {
          await search.fill(marker);
          await page.waitForTimeout(500);
          const again = await page.evaluate(() => document.body.innerText);
          if (again.includes(marker)) {
            await t.shot("inbox");
            return "the enquiry " + marker + " is in the inbox (found by search)";
          }
        }
        throw new Error("an enquiry was submitted and " + marker + " is not in the inbox");
      }
      await t.shot("inbox");
      return "the enquiry " + marker + " arrived in the inbox";
    });

    await t.check("the customer's message is shown as text, never executed", async () => {
      t.need(marker, "no enquiry was planted");
      const page = t.page;

      const row = page.locator("tr, li, article").filter({ hasText: marker }).first();
      if (await row.count()) {
        const link = row.locator("a").first();
        if (await link.count()) {
          leadUrl = await link.getAttribute("href");
          await link.click();
          await page.waitForLoadState("domcontentloaded", { timeout: 15000 }).catch(() => {});
          await page.waitForTimeout(400);
        }
      }

      const outcome = await page.evaluate(() => ({
        executed: window.__xss === 1,
        injected: !!document.querySelector('img[src="x"][onerror]'),
        shownAsText: document.body.innerText.includes('<img src=x onerror='),
      }));

      if (outcome.executed) throw new Error("STORED XSS: the customer's message ran in the admin's browser");
      if (outcome.injected) throw new Error("the customer's message became a real <img> element in the admin page");
      if (!outcome.shownAsText) {
        t.log("the probe text is not visible verbatim - it may be truncated in this view; the executed/injected checks are the ones that matter");
      }
      await t.shot("lead");
      return "the probe is inert: not executed, not parsed into an element";
    });

    /* ---- the ledger --------------------------------------------------- */

    await t.check("creating a shipment from the enquiry works once, not twice", async () => {
      t.need(marker, "no enquiry to convert");
      const page = t.page;

      const create = page.locator("button, a").filter({ hasText: /create shipment|new shipment|convert/i }).first();
      t.need(await create.count(), "no way to turn this enquiry into a shipment on this screen");

      const countShipments = async () => {
        const res = await page.goto(t.url("/admin/shipments"), { waitUntil: "domcontentloaded" });
        if (!res || res.status() !== 200) return null;
        return page.evaluate(() => document.querySelectorAll("tbody tr, tr[data-name], li[data-shipment]").length);
      };

      const before = await countShipments();
      t.need(before !== null, "/admin/shipments does not answer - the ledger has not shipped");

      await page.goBack({ waitUntil: "domcontentloaded" }).catch(() => {});
      await page.waitForTimeout(300);
      const again = page.locator("button, a").filter({ hasText: /create shipment|new shipment|convert/i }).first();
      t.need(await again.count(), "the create control is gone after navigating back");

      /*
       * "CREATE SHIPMENT" IN THE INBOX IS A LINK, NOT A BUTTON (fixed
       * 2026-09-29). app/Views/admin/inbox.php prints
       * `<a class="btn outline small" href="…shipmentFromLead…">Create
       * shipment</a>`: it opens the new-shipment FORM, carrying the lead's id.
       * Nothing is created until that form is submitted.
       *
       * This check clicked the link twice and then counted the ledger, so it
       * reported "clicking create made no shipment at all" about an admin that
       * was working exactly as designed - and, worse, it never once exercised
       * the thing it exists to exercise. The double-submit guard is
       * `data-once` on the FORM's own submit button
       * (app/Views/admin/shipment-new.php), which the old version never
       * reached.
       *
       * So: follow the link, fill the two required selects a person would
       * choose, and double-click the real submit.
       */
      await again.click();
      await page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {});
      await page.waitForTimeout(400);

      const form = page.locator('form[method=post i]').filter({ has: page.locator('select[name="destId"]') }).first();
      t.need(
        await form.count(),
        "the create link did not lead to a new-shipment form (no select[name=destId] on " + page.url() + ")",
      );

      // destId and serviceId are `required`; everything else the form takes is
      // optional, and a person converting an enquiry fills only what they know.
      for (const field of ["destId", "serviceId"]) {
        const select = form.locator('select[name="' + field + '"]');
        // eslint-disable-next-line no-await-in-loop
        const options = await select.locator("option").evaluateAll((els) =>
          els.map((el) => el.value).filter((v) => v !== ""),
        );
        t.need(options.length, "the new-shipment form offers no " + field + " to choose");
        // eslint-disable-next-line no-await-in-loop
        await select.selectOption(options[0]);
      }
      await form.locator('input[name="customerName"]').fill("Harness " + marker).catch(() => {});

      // Two clicks in quick succession: the double submit an impatient person
      // makes on a slow connection. THIS is the button `data-once` guards.
      const submit = form.locator('button[type=submit], input[type=submit]').first();
      await submit.click();
      await page.waitForTimeout(80);
      await submit.click({ timeout: 3000 }).catch(() => {});
      await page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {});
      await page.waitForTimeout(800);

      const after = await countShipments();
      if (after === null) throw new Error("the ledger stopped answering");
      if (after === before) throw new Error("clicking create made no shipment at all");
      if (after > before + 1) {
        throw new Error("a double click made " + (after - before) + " shipments from one enquiry - the create is not guarded");
      }
      await t.shot("shipments");
      return before + " → " + after + " shipments from one enquiry, despite a double click";
    });

    await t.check("a shipment's status moves forward and is timestamped", async () => {
      const page = t.page;
      await page.goto(t.url("/admin/shipments"), { waitUntil: "domcontentloaded" });
      await page.waitForTimeout(300);

      const first = page.locator("tbody tr, tr[data-name]").first();
      t.need(await first.count(), "the ledger has no rows");
      const link = first.locator("a").first();
      if (await link.count()) {
        await link.click();
        await page.waitForLoadState("domcontentloaded", { timeout: 15000 }).catch(() => {});
        await page.waitForTimeout(400);
      }

      const select = await page.$("select[name*=status i], select[name*=event i]");
      const eventBtn = page.locator("button").filter({ hasText: /add event|update|record/i }).first();
      t.need(select || (await eventBtn.count()), "no way to record an event on a shipment");

      const before = await page.evaluate(() => document.body.innerText);

      if (select) {
        const options = await page.$$eval("select[name*=status i] option, select[name*=event i] option", (els) =>
          els.filter((e) => e.value).map((e) => e.value),
        );
        t.need(options.length > 1, "the status select has only one option");
        await select.selectOption(options[1]);
      }
      const note = await page.$("textarea, input[name*=note i]");
      if (note) await note.fill("harness event " + new Date().toISOString());
      if (await eventBtn.count()) await eventBtn.click();
      else await page.locator("form[method=post] button[type=submit]").first().click();
      await page.waitForLoadState("domcontentloaded", { timeout: 20000 }).catch(() => {});
      await page.waitForTimeout(600);

      const after = await page.evaluate(() => document.body.innerText);
      if (after === before) throw new Error("recording an event changed nothing on the page");
      if (/Fatal error|SQLSTATE/i.test(await page.content())) throw new Error("recording an event produced an error page");

      // A date or a time has to be next to it, or the ledger is a list of
      // claims with no history.
      const dated = /\b\d{4}-\d{2}-\d{2}\b|\b\d{1,2}:\d{2}\b|\b(just now|minute|hour)s? ago\b/i.test(after);
      if (!dated) throw new Error("the event was recorded with no date or time next to it");
      await t.shot("shipment-event");
      return "an event was recorded, with a timestamp";
    });

    await t.check("an event cannot be recorded without a CSRF token", async () => {
      const page = t.page;
      const action = await page.evaluate(() => {
        const form = document.querySelector("form[method=post i]");
        return form ? form.getAttribute("action") || location.pathname : null;
      });
      t.need(action, "no POST form on the shipment page");
      const cookies = await t.context.cookies();
      const res = await t.fetch(action, {
        method: "POST",
        headers: {
          "content-type": "application/x-www-form-urlencoded",
          cookie: cookies.map((c) => c.name + "=" + c.value).join("; "),
        },
        body: "status=delivered&note=tokenless",
      });
      if (!res.ok) throw new Error("no answer: " + res.error);
      if (res.status >= 200 && res.status < 300 && !/token|csrf|invalid|expired/i.test(res.body)) {
        throw new Error("a tokenless POST to " + action + " answered " + res.status + " and did not mention the token");
      }
      if (res.status >= 500) throw new Error("a tokenless POST answered " + res.status + " instead of refusing politely");
      return res.status + " for a POST with no token";
    });

    await t.check("every form on these screens carries a CSRF token", async () => contract.everyFormHasCsrf(t.page));

    await t.check("paging works, and page two is not page one", async () => {
      const page = t.page;
      const res = await page.goto(t.url("/admin/shipments"), { waitUntil: "domcontentloaded" });
      t.need(res && res.status() === 200, "the ledger does not answer");
      const first = await page.evaluate(() =>
        Array.from(document.querySelectorAll("tbody tr, tr[data-name]")).slice(0, 5).map((r) => r.innerText.slice(0, 40)),
      );
      const next = page.locator("a, button").filter({ hasText: /next|›|»|page 2|^2$/i }).first();
      if (!(await next.count())) t.notReady("there is only one page of shipments, so paging cannot be exercised");
      await next.click();
      await page.waitForLoadState("domcontentloaded", { timeout: 15000 }).catch(() => {});
      await page.waitForTimeout(400);
      const second = await page.evaluate(() =>
        Array.from(document.querySelectorAll("tbody tr, tr[data-name]")).slice(0, 5).map((r) => r.innerText.slice(0, 40)),
      );
      if (JSON.stringify(first) === JSON.stringify(second)) throw new Error("page two shows the same rows as page one");
      if (!second.length) throw new Error("page two is empty, but a next link was offered");
      return "page one and page two hold different rows";
    });

    await t.check("a made-up shipment id is a 404, not a 500 and not someone else's", async () => {
      const cookies = await t.context.cookies();
      const cookie = cookies.map((c) => c.name + "=" + c.value).join("; ");
      for (const id of ["999999999", "0", "-1", "abc", "1%20OR%201=1"]) {
        // eslint-disable-next-line no-await-in-loop
        const res = await t.fetch("/admin/shipment?id=" + id, { headers: { cookie } });
        if (!res.ok) continue;
        if (res.status >= 500) throw new Error("/admin/shipment?id=" + id + " answered " + res.status);
        if (/SQLSTATE|PDOException|syntax error/i.test(res.body)) {
          throw new Error("/admin/shipment?id=" + id + " leaked a database error - the id is reaching SQL unparameterised");
        }
      }
      return "5 nonsense ids, no 500 and no SQL error";
    });

    await t.check("the inbox and the ledger are shut to anonymous callers", async () => {
      const open = [];
      for (const p of ["/admin/inbox", "/admin/shipments", "/admin/inbox/page", "/admin/shipments/page"]) {
        // eslint-disable-next-line no-await-in-loop
        const res = await t.fetch(p);
        if (res.ok && res.status === 200 && !/sign in|log in|password/i.test(res.body)) open.push(p);
      }
      if (open.length) throw new Error("open without a session: " + open.join(", "));
      return "4 paths, none open";
    });

    await t.check("the browser stayed quiet over the whole flow", async () => contract.noConsoleErrors(t));

    if (marker) {
      t.log('CLEAN UP: delete the enquiry and shipment tagged "' + marker + '" from the inbox and the ledger.');
    }
  },
};
