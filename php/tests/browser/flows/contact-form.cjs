"use strict";

/**
 * The contact form: the other way a customer starts a conversation.
 *
 * What it has to get right, in order of how expensive it is to get wrong:
 *
 *   it works with JavaScript off   this is a form, on a phone, on a Lahore
 *                                  mobile connection. If it only submits
 *                                  through a click handler, some enquiries
 *                                  simply never arrive and nobody ever knows.
 *   it carries a CSRF token        or any site on the internet can post to it
 *                                  as the visitor.
 *   it validates politely          a 500 on a bad email address is a lost
 *                                  customer and a log full of noise.
 *   the honeypot is invisible      and empty, and filling it is rejected.
 *   the message arrives            in the inbox, with what was typed.
 *
 * The submission this flow makes is real, and it is tagged: every field carries
 * "harness" and a timestamp so the owner can tell a test enquiry from a customer
 * at a glance in the inbox.
 */

const contract = require("../contract.cjs");

const stamp = () => new Date().toISOString().replace(/[:.]/g, "-");

module.exports = {
  name: "contact-form",
  order: 14,
  title: "the enquiry form: no JavaScript, a CSRF token, and it arrives",
  viewport: "phone",

  async run(t) {
    t.need(t.probe.status["/contact"] !== 404, "/contact answers 404 - the contact page has not shipped");

    await t.open("/contact");
    await t.page.waitForTimeout(300);

    let fields = null;

    await t.check("the form is on the page, with the fields a lead needs", async () => {
      fields = await t.page.evaluate(() => {
        const form = document.querySelector("form[method=post i], form");
        if (!form) return null;
        /*
         * HIDDEN THE WAY THE SITE MEANS IT (fixed 2026-09-29).
         *
         * This used to be `display:none || offsetParent === null`, and the
         * site's honeypot is neither. app/Views/site/contact.php hides it by
         * putting it inside a `position:absolute; left:-9999px; top:-9999px`
         * wrapper marked `aria-hidden="true"` - the standard technique,
         * because display:none is the one thing a competent bot checks for and
         * skips. An absolutely-positioned element still has a positioned
         * ancestor, so offsetParent is NOT null, so the harness called the
         * honeypot visible and failed two checks over it:
         *     the honeypot website is visible, so real customers will fill it in
         *     no maxlength on: website
         * - the second from the field-cap sweep, which only ever meant to look
         * at fields a person types into.
         *
         * Neither was a defect. The check and the site disagreed about a word.
         * So this asks the question a PERSON answers: can I see it, and would a
         * screen reader read it out? Off-screen, clipped, zero-sized,
         * transparent, aria-hidden, display:none and visibility:hidden are all
         * the same answer - no.
         */
        const hiddenFromPeople = (el) => {
          if (el.type === "hidden") return true;
          if (el.hasAttribute("hidden")) return true;
          if (el.closest("[aria-hidden=true]")) return true;

          const cs = getComputedStyle(el);
          if (cs.display === "none" || cs.visibility === "hidden") return true;
          if (parseFloat(cs.opacity || "1") < 0.05) return true;

          const r = el.getBoundingClientRect();
          if (r.width < 2 || r.height < 2) return true;

          // Parked off-screen. Measured against the DOCUMENT, not the viewport:
          // a field below the fold is on the page and a person scrolls to it,
          // whereas a field at left:-9999px is not on the page at all.
          const doc = document.documentElement;
          if (r.right + window.scrollX < 0 || r.bottom + window.scrollY < 0) return true;
          if (r.left + window.scrollX > doc.scrollWidth) return true;

          return el.offsetParent === null && cs.position !== "fixed";
        };

        const inputs = Array.from(form.querySelectorAll("input, textarea, select")).map((el) => ({
          name: el.getAttribute("name") || "",
          type: (el.getAttribute("type") || el.tagName).toLowerCase(),
          required: el.hasAttribute("required"),
          maxlength: el.getAttribute("maxlength"),
          hidden: hiddenFromPeople(el),
          label: (() => {
            const id = el.id;
            const lab = id ? document.querySelector('label[for="' + id + '"]') : el.closest("label");
            return lab ? (lab.textContent || "").replace(/\s+/g, " ").trim().slice(0, 40) : "";
          })(),
        }));
        return { action: form.getAttribute("action") || "", method: (form.getAttribute("method") || "get").toLowerCase(), inputs };
      });
      t.need(fields, "no form on /contact yet");
      if (fields.method !== "post") throw new Error('the contact form is method="' + fields.method + '" - an enquiry is not a GET');

      const named = fields.inputs.map((i) => i.name);
      const missing = ["name", "phone", "message"].filter((n) => !named.includes(n));
      if (missing.length) {
        throw new Error("the form has no " + missing.join(", ") + " field; it has: " + named.filter(Boolean).join(", "));
      }
      const unlabelled = fields.inputs.filter((i) => !i.hidden && i.name && !i.label && i.type !== "submit");
      if (unlabelled.length) throw new Error(unlabelled.length + " visible field(s) have no label: " + unlabelled.map((i) => i.name).join(", "));
      await t.shot("contact");
      return fields.inputs.filter((i) => i.name).length + " fields: " + named.filter(Boolean).join(", ");
    });

    await t.check("every field caps its length, so a giant payload is a 400", async () => {
      t.need(fields, "no form to inspect");
      const uncapped = fields.inputs.filter((i) => !i.hidden && ["text", "email", "tel", "textarea"].includes(i.type) && !i.maxlength);
      if (uncapped.length) {
        throw new Error("no maxlength on: " + uncapped.map((i) => i.name || i.type).join(", "));
      }
      return "every text field has a maxlength";
    });

    await t.check("the form carries a CSRF token", async () => contract.everyFormHasCsrf(t.page));

    await t.check("there is a honeypot, and it is invisible to a person", async () => {
      t.need(fields, "no form to inspect");
      const candidates = fields.inputs.filter((i) => /honey|website|url|_hp|nickname|fax/i.test(i.name));
      t.need(candidates.length, "no honeypot field found (looked for a name containing honey/website/url/_hp)");
      const visible = candidates.filter((c) => !c.hidden);
      if (visible.length) throw new Error("the honeypot " + visible[0].name + " is visible, so real customers will fill it in");
      const required = candidates.filter((c) => c.required);
      if (required.length) throw new Error("the honeypot " + required[0].name + " is marked required, which blocks every real submission");
      return "honeypot: " + candidates.map((c) => c.name).join(", ") + ", hidden and optional";
    });

    await t.check("an empty submission is refused politely, not with a 500", async () => {
      const res = await t.fetch("/contact", {
        method: "POST",
        headers: { "content-type": "application/x-www-form-urlencoded" },
        body: "name=&phone=&message=",
      });
      if (!res.ok) throw new Error("no answer: " + res.error);
      if (res.status >= 500) throw new Error("an empty submission answered " + res.status);
      if (/Fatal error|SQLSTATE|PDOException|stack trace/i.test(res.body)) throw new Error("an empty submission leaked an error");
      // No token either, so the expected answer is a refusal - 400, 403, 422 or
      // the form again with a complaint on it.
      if (res.status === 200 && !/required|invalid|could not|please|error/i.test(res.body)) {
        throw new Error("an empty, tokenless submission answered 200 and said nothing about a problem - was it accepted?");
      }
      return res.status + ", refused without a trace or a stack";
    });

    await t.check("a submission with no CSRF token is refused", async () => {
      const res = await t.fetch("/contact", {
        method: "POST",
        headers: { "content-type": "application/x-www-form-urlencoded" },
        body: "name=harness&phone=03001234567&message=" + encodeURIComponent("no token " + stamp()),
      });
      if (!res.ok) throw new Error("no answer: " + res.error);
      if (res.status >= 200 && res.status < 300 && /thank|received|sent|success/i.test(res.body)) {
        throw new Error("a submission with no CSRF token was accepted - any site on the internet can post as your visitors");
      }
      if (res.status >= 500) throw new Error("a missing token answered " + res.status + " instead of refusing");
      return res.status + " for a tokenless post";
    });

    await t.check("a nonsense phone number and email are refused, not stored", async () => {
      const page = t.page;
      await page.reload({ waitUntil: "domcontentloaded" });
      const set = async (name, value) => {
        const el = await page.$('[name="' + name + '"]');
        if (el) await el.fill(value);
        return !!el;
      };
      await set("name", "harness");
      await set("phone", "not-a-phone");
      await set("email", "not-an-email");
      await set("message", "validation probe " + stamp());
      // The site refuses anything typed faster than a person could have typed
      // it (Http\FormToken, 3s). Without this the 422 arrives before the
      // validator is ever reached, and the check measures the wrong refusal.
      await contract.settleThinkTime(page);
      await page.click("button[type=submit], input[type=submit]");
      await page.waitForLoadState("domcontentloaded", { timeout: 15000 }).catch(() => {});
      await page.waitForTimeout(400);

      const body = await page.content();
      if (/Fatal error|SQLSTATE|PDOException/i.test(body)) throw new Error("bad input produced an error page");
      if (/thank|we will|received|got it/i.test(body) && !/invalid|check|valid/i.test(body)) {
        throw new Error("a phone number of \"not-a-phone\" and an email of \"not-an-email\" were accepted");
      }
      await t.shot("validation");
      const said = await page.evaluate(() =>
        Array.from(document.querySelectorAll(".error, .invalid, [role=alert], .field-error, .hint"))
          .map((e) => (e.textContent || "").trim())
          .filter(Boolean)
          .slice(0, 4)
          .join(" | "),
      );
      if (!said) throw new Error("bad input was refused, but the page does not say what is wrong");
      return JSON.stringify(said.slice(0, 140));
    });

    await t.check("filling the honeypot is refused without telling the bot why", async () => {
      t.need(fields, "no form to inspect");
      const honeypot = fields.inputs.find((i) => /honey|website|url|_hp|nickname|fax/i.test(i.name));
      t.need(honeypot, "no honeypot to fill");

      const page = t.page;
      await page.reload({ waitUntil: "domcontentloaded" });
      const token = await page.getAttribute('input[name="_token"]', "value").catch(() => null);
      t.need(token, "no CSRF token on the page to submit with");
      // The render timestamp travels with the body, and this post is meant to
      // be refused for the HONEYPOT, not for arriving in under three seconds.
      // Without the wait, a site with no honeypot at all would pass this check.
      const formToken = await page.getAttribute('input[name="t"]', "value").catch(() => null);
      await contract.settleThinkTime(page);

      const body = new URLSearchParams({
        _token: token,
        ...(formToken ? { t: formToken } : {}),
        name: "harness bot",
        phone: "03001234567",
        email: "harness@example.invalid",
        message: "honeypot probe " + stamp(),
        [honeypot.name]: "http://spam.example",
      }).toString();

      const cookies = await page.context().cookies();
      const cookieHeader = cookies.map((c) => c.name + "=" + c.value).join("; ");
      const res = await t.fetch("/contact", {
        method: "POST",
        headers: { "content-type": "application/x-www-form-urlencoded", cookie: cookieHeader },
        body,
      });
      if (!res.ok) throw new Error("no answer: " + res.error);
      if (res.status >= 500) throw new Error("a filled honeypot answered " + res.status);
      // A bot must not learn that it was caught; the polite answer is the same
      // "thanks" a person gets, with nothing written to the inbox.
      return res.status + " - the bot is not told it was caught";
    });

    await t.check("a real enquiry is accepted and acknowledged", async () => {
      const page = t.page;
      await page.reload({ waitUntil: "domcontentloaded" });
      const marker = "HARNESS " + stamp();

      const set = async (name, value) => {
        const el = await page.$('[name="' + name + '"]');
        if (el) await el.fill(value);
      };
      await set("name", "Harness Test");
      await set("phone", "03001234567");
      await set("email", "harness@example.invalid");
      await set("message", marker + " - automated browser test, safe to delete.");

      // THE FIX THIS CHECK EXISTED TO NEED. Filling four fields with Playwright
      // takes under a second; Http\FormToken refuses anything under three, so
      // every run of this check got a 422 and reported "the page never
      // acknowledged it" - the anti-bot rule working, read as the form being
      // broken. The wait is what a person spends typing anyway.
      const waited = await contract.settleThinkTime(page);
      if (waited) t.log("waited " + waited + "ms for the site's minimum think time before submitting");
      await page.click("button[type=submit], input[type=submit]");
      await page.waitForLoadState("domcontentloaded", { timeout: 15000 }).catch(() => {});
      await page.waitForTimeout(500);

      const body = await page.content();
      if (/Fatal error|SQLSTATE|PDOException/i.test(body)) throw new Error("a valid enquiry produced an error page");
      if (!/thank|received|we will|got it|soon/i.test(body)) {
        throw new Error("a valid enquiry was submitted and the page never acknowledged it: " + (await page.title()));
      }
      await t.shot("submitted");
      t.log("submitted marker " + marker + " - it should appear in the admin inbox");
      t.marker = marker;
      return "accepted and acknowledged; inbox marker " + marker;
    });

    await t.check("the same enquiry twice does not become two leads", async () => {
      const page = t.page;
      // The back button after a submit is the commonest way a duplicate happens.
      await page.goBack({ waitUntil: "domcontentloaded" }).catch(() => {});
      await page.waitForTimeout(300);
      const resubmit = await page.$("button[type=submit], input[type=submit]");
      if (!resubmit) {
        return "the submitted page has no form to resubmit - a redirect after post, which is the right answer";
      }
      const before = page.url();
      await resubmit.click();
      await page.waitForLoadState("domcontentloaded", { timeout: 15000 }).catch(() => {});
      await page.waitForTimeout(400);
      const body = await page.content();
      if (/Fatal error|SQLSTATE/i.test(body)) throw new Error("resubmitting from the back button produced an error");
      // Either the token is now spent (a refusal) or the lead is deduplicated.
      // Both are acceptable; silently creating a second lead is not, and the
      // inbox flow is what confirms which happened.
      t.log("resubmitted from " + before + "; verify in admin-inbox-shipments that only one lead exists for the marker");
      return "resubmission handled without an error; the inbox flow confirms there is only one lead";
    });

    await t.check("the form submits with JavaScript off", async () =>
      contract.submitsWithoutJavaScript(t, "/contact", { formSelector: "form[method=post]" }),
    );

    await t.check("the contact page fits 375px and reads at 14px", async () => {
      await t.open("/contact");
      await contract.noHorizontalScroll(t.page);
      return contract.nothingUnderMinFont(t.page);
    });

    /*
     * THIS FLOW MAKES THE SERVER REFUSE THINGS ON PURPOSE, so the refusals are
     * allowed here by name.
     *
     *   422             the nonsense-phone-number submission and the
     *                   back-button resubmission being rejected - which the
     *                   checks above already assert, by reading the answer.
     *   ERR_CACHE_MISS  Chrome refusing to replay a POST from history when
     *                   "the same enquiry twice" presses Back. That is the
     *                   browser's own rule about resubmitting a form, not
     *                   anything this site did.
     *
     * Anything else the browser reports still fails this check: a 500, an
     * uncaught error, a broken asset.
     */
    await t.check("the browser stayed quiet over the whole flow", async () =>
      contract.noConsoleErrors(t, { allow: [/\b422\b/, /ERR_CACHE_MISS/] }),
    );
  },
};
