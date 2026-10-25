"use strict";

/**
 * Settings and the website's own words: change a field in the admin, see it on
 * the public page, put it back.
 *
 * The round trip is the whole check. An owner who edits the FAQ and cannot see
 * the change will edit it again, and again, and then telephone somebody. So each
 * check here follows one value from the admin form to the rendered page - and,
 * for the FAQ, into the JSON-LD as well, because the structured data has to say
 * what the visitor reads rather than something invented alongside it.
 *
 * Every edit is reverted. The revert is in a finally block and shouts the old
 * value if it cannot complete.
 */

const contract = require("../contract.cjs");
const { adminSession } = require("../harness.cjs");

/**
 * The editable field with this name, wherever on the page it is.
 *
 * A name, not an index. /admin/settings prints 22 POST forms and this flow
 * used to address a field by its position in a node list it had collected with
 * a DIFFERENT selector (`form[method=post i]` vs `form[method=post]`, whose
 * `i` flag changes the match), so the edit landed in whatever input happened
 * to sit at that offset in the other list. A name is unique on this page and
 * survives a reload, which an index does not.
 */
function fieldByName(page, name) {
  /*
   * NOT `form [name="…"]` — THE FIELDS ARE OUTSIDE THEIR FORM (fixed
   * 2026-09-29). app/Views/admin/partials/settings.php says so in its own
   * docblock: Settings and Website pages belong to `form#editor` through the
   * HTML `form="editor"` attribute "even though they are not inside it",
   * because a settings change and a price change have to be one save and one
   * published version. A descendant selector therefore matches some of these
   * fields and not others, entirely by where they happen to sit.
   *
   * `settings[holidays]` is one of the ones it does not match, so the restore
   * spent thirty seconds waiting for it, gave up, and reported
   * "THE LIVE SITE STILL CARRIES THIS HARNESS'S EDITS" - which was true, and
   * was caused by the selector rather than by the admin.
   *
   * A name is unique on this page whether or not the field sits in a form, so
   * that is what is matched, and `visible=true` keeps it off the collapsed
   * copies (the hold panel's own `message` box lives in a closed section).
   */
  return page.locator('[name="' + name + '"]').locator("visible=true").first();
}

/**
 * The save button of the form THAT field is in.
 *
 * `.first()` over the whole page picks the header's Sign out button, which is
 * a POST form on every signed-in admin page. Submitting it ends the session
 * and saves nothing, and the flow then reports the admin as failing to publish.
 * The right button is a descendant of the field's own <form>, so it is looked
 * up from the field rather than from the document.
 */
function saveButtonFor(field) {
  // Kept for a field that really is inside its form. On /admin/settings most
  // are not (see fieldByName), which is why saveDraft() below drives the
  // autosave and falls back to the page's own visible save button rather than
  // hunting for one per field.
  return field
    .locator("xpath=ancestor::form[1]")
    .locator('button[type=submit], [data-save-submit], input[type=submit], button:not([type])')
    .locator("visible=true")
    .first();
}

/**
 * Get the edit into the DRAFT, whichever way this page saves.
 *
 * With JavaScript on, admin.js autosaves the editor form and HIDES the
 * `data-save-submit` buttons - so the flow's old `.click()` waited thirty
 * seconds on a `<button hidden>` and timed out. With JavaScript off those
 * buttons are the only way to save. Both are real, so this waits for the
 * autosave to report itself through `[data-save-state]` and only reaches for a
 * button if no such report arrives.
 */
async function saveDraft(page) {
  const state = page.locator("[data-save-state]").first();

  if (await state.count()) {
    /*
     * WAIT FOR THE TRANSITION, NOT FOR THE WORDS (fixed 2026-09-29).
     *
     * `waitFor({ hasText: /draft saved/i })` passed INSTANTLY, because the
     * line already said "Draft saved 29 Sept 2026, 17:26" from the save
     * before this one. So the flow filled a field, "waited" for a save that
     * had not started, published the version it was already on, and then
     * failed the restore with "THE LIVE SITE STILL CARRIES THIS HARNESS'S
     * EDITS" - about a restore that had been typed correctly and never sent.
     *
     * admin.js's line moves "Unsaved edits…" → "Saving…" → "Draft saved
     * <time>". Waiting for it to LEAVE the saved state first, and only then
     * for it to come back, is the difference between watching a save and
     * reading a stale label. If it never leaves within three seconds the
     * autosave did not notice the edit at all, and the explicit Save button
     * below is the honest fallback rather than a pretend success.
     */
    try {
      await state
        .filter({ hasText: /unsaved|saving/i })
        .waitFor({ timeout: 3000 })
        .catch(() => {});
      await page.waitForFunction(
        () => {
          const el = document.querySelector("[data-save-state]");
          return !!el && /draft saved/i.test(el.textContent || "") && !/unsaved|saving/i.test(el.textContent || "");
        },
        undefined,
        { timeout: 20000 },
      );
      return "autosaved: " + (await state.innerText()).replace(/\s+/g, " ").trim();
    } catch (e) {
      /* fall through to the button */
    }
  }

  const save = page.locator('[data-save-submit], button[type=submit]').locator("visible=true").first();
  if (await save.count()) {
    await save.click({ timeout: 15000 });
    await page.waitForLoadState("domcontentloaded", { timeout: 25000 }).catch(() => {});
    await page.waitForTimeout(500);
    return "saved with the visible save button";
  }

  throw new Error(
    "the edit was typed and nothing saved it: no [data-save-state] ever said \"Draft saved\" and there is "
      + "no visible save button either",
  );
}

/**
 * Publish the draft, because a draft is a draft.
 *
 * THE FLOW NEVER DID THIS, WHICH IS WHY IT FAILED (fixed 2026-09-29). The
 * editor writes a DRAFT - app/Views/admin/partials/settings.php says so, and
 * admin-rates-publish's own checks assert that an unpublished edit must NOT
 * reach the public site. So "a heading edited in the admin appears on the
 * public page" was asserting the exact opposite of the product's rule, and its
 * failure - "the edit to company[name] is not on / after saving" - was the
 * draft mechanism working. Settings, website pages and prices are ONE version
 * and one publish, which is why the publish lives at /admin/rates/review.
 */
async function publishDraft(t, page) {
  await page.goto(t.url("/admin/rates/review"), { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(300);

  const publish = page
    .locator("button, input[type=submit]")
    .filter({ hasText: /publish/i })
    .locator("visible=true")
    .first();
  if (!(await publish.count())) return null;

  await publish.click({ timeout: 20000 });
  await page.waitForLoadState("domcontentloaded", { timeout: 25000 }).catch(() => {});
  await page.waitForTimeout(700);
  return new Date().toISOString();
}

module.exports = {
  name: "admin-settings-content",
  order: 24,
  title: "edit a setting and a page's words, see them live, put them back",
  viewport: "desktop",

  async run(t) {
    await adminSession(t);

    let sectionsPath = null;
    const toRestore = [];

    /*
     * DOES THE SITE ALREADY CARRY A PREVIOUS RUN'S WORDS? Asked first, because
     * the answer changes what everything below means.
     *
     * This flow edits a value, publishes it, and puts the old one back. If a
     * previous run died between those two steps, the "old value" this run
     * reads is already "Speedat International Courier [harness]" - so the edit
     * check compares a string against itself and reports "the edit reached /
     * without being published", and the restore dutifully puts the harness's
     * own words back and then fails because the site still has them in it.
     * Three red checks, none of them about today, and the message on the loud
     * one is an instruction to fix something this run did not do.
     *
     * A dirty site is NOT READY with the values named. The site has to be put
     * back by hand before this flow can say anything.
     */
    await t.check("the site is clean before this flow starts editing it", async () => {
      const dirty = [];
      for (const p of ["/", "/services", "/about", "/faq", "/contact"]) {
        // eslint-disable-next-line no-await-in-loop
        const res = await t.fetch(p);
        if (!res.ok || res.status !== 200) continue;
        if (/\[harness\]|__contentXss|data-harness/.test(res.body)) dirty.push(p);
      }
      t.need(
        dirty.length === 0,
        "the live site already carries an earlier run's edits on " + dirty.join(", ") + ". "
          + "Put them back through Apps -> Settings and publish, then run this flow again: until then "
          + "every check below would be measuring the last run instead of this one.",
      );
      return "no page mentions the harness";
    });

    await t.check("the content editor is in the admin", async () => {
      for (const p of ["/admin/sections", "/admin/fields", "/admin/settings", "/admin/content"]) {
        // eslint-disable-next-line no-await-in-loop
        const res = await t.page.goto(t.url(p), { waitUntil: "domcontentloaded" });
        if (res && res.status() === 200) {
          // eslint-disable-next-line no-await-in-loop
          const editable = await t.page.evaluate(
            () => document.querySelectorAll("form[method=post i] input[type=text], form[method=post i] textarea").length,
          );
          if (editable > 0) {
            sectionsPath = p;
            break;
          }
        }
      }
      t.need(sectionsPath, "no content editor found on /admin/sections, /admin/fields, /admin/settings or /admin/content");
      await t.shot("content-editor");
      return "found on " + sectionsPath;
    });

    await t.check("every form in the content editor carries a CSRF token", async () => contract.everyFormHasCsrf(t.page));

    try {
      await t.check("a heading edited in the admin appears on the public page", async () => {
        const page = t.page;
        await page.goto(t.url(sectionsPath), { waitUntil: "domcontentloaded" });

        // Pick a short text field whose current value is actually visible on the
        // public site, so the round trip is provable.
        // VISIBLE fields only. This page carries collapsed sections - the hold
        // panel's own message box among them - and a field a person cannot see
        // is a field Playwright cannot fill: the old version picked one and
        // waited thirty seconds for it to become editable.
        const candidates = await page.evaluate(() =>
          Array.from(document.querySelectorAll("form[method=post i] input[type=text], [form] input[type=text]"))
            .filter((el) => {
              const r = el.getBoundingClientRect();
              const cs = getComputedStyle(el);
              return r.width > 2 && r.height > 2 && cs.visibility !== "hidden" && cs.display !== "none" && !el.disabled && !el.readOnly;
            })
            .map((el, i) => ({ i, name: el.getAttribute("name") || "", value: el.value || "", maxlength: el.getAttribute("maxlength") }))
            .filter((f) => f.name && f.value && f.value.length > 3 && f.value.length < 60),
        );
        t.need(candidates.length, "no short, visible text field with a value to edit");

        let chosen = null;
        for (const field of candidates) {
          // eslint-disable-next-line no-await-in-loop
          const home = await t.fetch("/");
          if (home.ok && home.body.includes(field.value)) {
            chosen = field;
            break;
          }
        }
        if (!chosen) {
          // eslint-disable-next-line no-await-in-loop
          for (const p of ["/services", "/about", "/faq", "/contact"]) {
            // eslint-disable-next-line no-await-in-loop
            const res = await t.fetch(p);
            if (!res.ok || res.status !== 200) continue;
            const hit = candidates.find((f) => res.body.includes(f.value));
            if (hit) {
              chosen = { ...hit, page: p };
              break;
            }
          }
        }
        t.need(chosen, "none of the editable fields' current values appear on any public page, so a round trip cannot be proved");

        const target = chosen.page || "/";
        const original = chosen.value;
        const edited = original.replace(/\s*\[harness\]$/, "") + " [harness]";

        /*
         * BY NAME, AND THROUGH ITS OWN FORM'S SAVE BUTTON (fixed 2026-09-29).
         *
         * Two bugs, one symptom - "the edit to company[name] is not on / after
         * saving", which reads as the admin failing to publish and was nothing
         * of the kind:
         *
         *   1. The candidates were collected with `form[method=post i] input`
         *      and then addressed with `form[method=post] input` and an index.
         *      The `i` flag makes those two DIFFERENT node lists, so .nth(i)
         *      filled whatever input happened to sit at that position in the
         *      other list.
         *   2. /admin/settings prints 22 POST forms. `.first()` submit button
         *      on the page belongs to the first of them - which is the header's
         *      Sign out on every signed-in admin page, and in any case not the
         *      form the field is in. The field was edited and a different form
         *      was submitted, so nothing was ever saved.
         *
         * A name is unique on this page and survives a reload, so the field is
         * found by name and the button is found INSIDE the same form. Both
         * mistakes become impossible rather than less likely.
         */
        const input = fieldByName(page, chosen.name);
        await input.fill(edited);
        toRestore.push({ index: chosen.i, name: chosen.name, original, edited });

        const how = await saveDraft(page);

        // A DRAFT IS A DRAFT, so prove that first - the same rule
        // admin-rates-publish asserts from the other side.
        const beforePublish = await t.fetch(target);
        if (beforePublish.ok && beforePublish.body.includes(edited)) {
          throw new Error("the edit reached " + target + " without being published - a draft that goes live is not a draft");
        }

        const publishedAt = await publishDraft(t, page);
        t.need(publishedAt, "no publish button on /admin/rates/review, so a saved draft cannot be made live");

        const body = await page.content();
        if (/Fatal error|SQLSTATE/i.test(body)) throw new Error("publishing the content produced an error page");

        const publicRes = await t.fetch(target);
        if (!publicRes.ok) throw new Error("the public page stopped answering: " + publicRes.error);
        if (!publicRes.body.includes(edited)) {
          throw new Error(
            "the edit to " + chosen.name + " is not on " + target + " after saving AND publishing (" + how + ")",
          );
        }
        await t.shot("content-live");
        return chosen.name + ' → "' + edited + '" is live on ' + target + " (" + how + ", then published)";
      });

      await t.check("the edited words are escaped, not injected", async () => {
        const page = t.page;
        await page.goto(t.url(sectionsPath), { waitUntil: "domcontentloaded" });

        /*
         * VISIBLE FIELDS ONLY, ADDRESSED BY NAME (fixed 2026-09-29).
         *
         * This picked "the first textarea in the list" and then addressed it
         * with a DIFFERENT selector and an index, and landed on the hold
         * panel's own `message` box - which lives in a collapsed section. The
         * fill waited thirty seconds for an element that is never visible and
         * timed out, reporting nothing about the escaping it was written to
         * check. Same disease as the check above: an index across two node
         * lists that are not the same list.
         */
        const candidates = await page.evaluate(() =>
          Array.from(document.querySelectorAll("form[method=post i] textarea, form[method=post i] input[type=text], [form] textarea, [form] input[type=text]"))
            .filter((el) => {
              const r = el.getBoundingClientRect();
              const cs = getComputedStyle(el);
              return r.width > 2 && r.height > 2 && cs.visibility !== "hidden" && cs.display !== "none" && !el.disabled && !el.readOnly;
            })
            .map((el, i) => ({ i, tag: el.tagName.toLowerCase(), name: el.getAttribute("name") || "", value: el.value || "" }))
            .filter((f) => f.name),
        );
        const field = candidates.find((f) => f.tag === "textarea") || candidates[0];
        t.need(field, "no visible editable field to try this on");

        const probe = '</p><script>window.__contentXss=1</script><b data-harness>x</b>';
        const original = field.value;

        const el = fieldByName(page, field.name);
        await el.fill(original + " " + probe);
        toRestore.push({ name: field.name, original, edited: original + " " + probe });

        await saveDraft(page);
        // Published, because the check below looks at the PUBLIC pages and an
        // unpublished draft is correctly absent from them - which this check
        // would have read as "the escaping works".
        await publishDraft(t, page);

        const visitor = await t.browser.newContext({ ignoreHTTPSErrors: true });
        try {
          const vp = await visitor.newPage();
          t.watch(vp);
          let executed = false;
          let injected = false;
          for (const p of ["/", "/services", "/about", "/faq", "/contact"]) {
            // eslint-disable-next-line no-await-in-loop
            const res = await vp.goto(t.url(p), { waitUntil: "load", timeout: 30000 }).catch(() => null);
            if (!res || res.status() !== 200) continue;
            // eslint-disable-next-line no-await-in-loop
            const out = await vp.evaluate(() => ({
              ran: window.__contentXss === 1,
              el: !!document.querySelector("b[data-harness]"),
            }));
            if (out.ran) executed = true;
            if (out.el) injected = true;
          }
          if (executed) throw new Error("STORED XSS: an admin's content field executed a script on the public site");
          if (injected) throw new Error("the content field's markup became real elements on the public site - it is not being escaped");
          return "the probe is printed as text on every public page, not parsed";
        } finally {
          await visitor.close();
        }
      });

      await t.check("a field refuses input longer than its column", async () => {
        const page = t.page;
        await page.goto(t.url(sectionsPath), { waitUntil: "domcontentloaded" });
        /*
         * THIS CHECK USED TO SIGN THE SESSION OUT, EVERY RUN (fixed
         * 2026-09-29).
         *
         * It took `document.querySelector("form[method=post i]")` - the FIRST
         * POST form on the page - as "the editor". On every signed-in admin
         * page that is the header's **Sign out** form, so the check posted a
         * valid CSRF token to the sign-out action, got a polite answer, and
         * passed. Everything after it then ran signed out: the restore at the
         * end of this flow found /admin/login instead of the editor and
         * reported "THE LIVE SITE STILL CARRIES THIS HARNESS'S EDITS" on a
         * site it had simply been locked out of.
         *
         * `contract.submitsWithoutJavaScript()` carries a guard against
         * exactly this and says why; this check had the same bug and no
         * guard. So: take the field FIRST, then the form that actually
         * contains it (or, on this page, the one it names through
         * `form="editor"`), and refuse a sign-out action outright.
         */
        const field = await page.evaluate(() => {
          const boxes = Array.from(document.querySelectorAll("input[type=text][maxlength]"));
          const el = boxes.find((b) => {
            const form = b.form;                       // honours form="editor"
            if (!form) return false;
            if ((form.getAttribute("method") || "get").toLowerCase() !== "post") return false;
            return !/log-?out|sign-?out/i.test(form.getAttribute("action") || "");
          });
          if (!el) return null;
          const form = el.form;
          const token = form.querySelector('input[name="_token"]');
          return {
            name: el.getAttribute("name"),
            maxlength: Number(el.getAttribute("maxlength")),
            value: el.value,
            action: form.getAttribute("action") || location.pathname,
            token: token ? token.value : "",
          };
        });
        t.need(field, "no text field with a maxlength belongs to a POST form that is not Sign out");

        // The browser enforces maxlength; the server must too, because a POST
        // does not come from a browser.
        const cookies = await t.context.cookies();
        const token = field.token;
        t.need(token, "the editor form carries no CSRF token to post with");
        const action = field.action;
        if (/log-?out|sign-?out/i.test(action)) {
          throw new Error('refusing to post to "' + action + '" - that is Sign out, not the editor');
        }
        /*
         * WHATEVER THIS WRITES, THE RESTORE PUTS BACK (2026-09-29).
         *
         * This posts 620 characters into a real field of the real draft, and
         * the server accepts the POST (303) — so the value IS written. The
         * first run after this check started reaching the editor instead of
         * the Sign out form left a destination called `xxxxxxxx…` on the live
         * site, which then widened the rates board to 1087px and made five
         * other checks in two other flows fail on a horizontal scrollbar that
         * the harness itself had created.
         *
         * Recording the old value here, BEFORE the post, is what makes the
         * restore at the end of this flow responsible for it.
         */
        toRestore.push({ name: field.name, original: field.value, edited: "x".repeat(field.maxlength + 500) });

        const res = await t.fetch(action, {
          method: "POST",
          headers: {
            "content-type": "application/x-www-form-urlencoded",
            cookie: cookies.map((c) => c.name + "=" + c.value).join("; "),
          },
          body: new URLSearchParams({ _token: token, [field.name]: "x".repeat(field.maxlength + 500) }).toString(),
        });
        if (!res.ok) throw new Error("no answer: " + res.error);
        if (res.status >= 500) throw new Error("an over-long value answered " + res.status + " - the cap is the database's, not the app's");
        if (/SQLSTATE|Data too long/i.test(res.body)) throw new Error("an over-long value reached the database and it complained");

        // And it must not have been STORED at full length. A 303 that quietly
        // keeps 620 characters in a 120-character field is the cap failing
        // politely, which is the failure this check is named for.
        await page.goto(t.url(sectionsPath), { waitUntil: "domcontentloaded" });
        await page.waitForTimeout(400);
        const stored = await fieldByName(page, field.name).inputValue().catch(() => null);
        if (stored !== null && stored.length > field.maxlength) {
          throw new Error(
            "the server stored " + stored.length + " characters in a " + field.maxlength
              + "-character field (answered " + res.status + "), so the maxlength is the browser's alone",
          );
        }

        return res.status + " for " + (field.maxlength + 500) + " characters into a " + field.maxlength
          + "-character field; stored " + (stored === null ? "?" : stored.length);
      });

      await t.check("the FAQ's JSON-LD says what the FAQ page says", async () => {
        const res = await t.fetch("/faq");
        t.need(res.ok && res.status === 200, "/faq is not being served");
        const blocks = res.body.match(/<script[^>]+application\/ld\+json[^>]*>([\s\S]*?)<\/script>/gi) || [];
        t.need(blocks.length, "/faq has no JSON-LD - Domain\\Seo has not shipped");

        let faq = null;
        for (const block of blocks) {
          const json = block.replace(/^[\s\S]*?>/, "").replace(/<\/script>$/i, "");
          try {
            const parsed = JSON.parse(json);
            const walk = (n) => {
              if (Array.isArray(n)) return n.forEach(walk);
              if (n && typeof n === "object") {
                if (n["@type"] === "FAQPage") faq = n;
                if (n["@graph"]) walk(n["@graph"]);
              }
              return undefined;
            };
            walk(parsed);
          } catch (e) {
            throw new Error("a JSON-LD block on /faq does not parse: " + e.message);
          }
        }
        t.need(faq, "no FAQPage in the /faq JSON-LD");

        const questions = (faq.mainEntity || []).map((q) => String(q.name || ""));
        t.need(questions.length, "the FAQPage has no questions");

        const visible = res.body.replace(/<script[\s\S]*?<\/script>/gi, "").replace(/<[^>]+>/g, " ").replace(/\s+/g, " ");
        const invented = questions.filter((q) => !visible.includes(q.slice(0, Math.min(30, q.length))));
        if (invented.length) {
          throw new Error(
            [
              invented.length + " question(s) are in the structured data but not on the page a visitor reads:",
              ...invented.slice(0, 3).map((q) => "    " + JSON.stringify(q.slice(0, 60))),
              "Structured data that differs from the page is a manual action waiting to happen.",
            ].join("\n"),
          );
        }
        return questions.length + " questions, every one visible on the page";
      });

      /*
       * ONE NUMBER PER CHANNEL, AND NOTHING ORPHANED (rewritten 2026-09-29).
       *
       * This used to fold every number on the page into one set and fail if
       * there was more than one, which the site has never been. Domain\SiteDoc
       * carries `phone`, `phone2` and `whatsapp` as separate fields, the reach
       * block prints "WhatsApp <x> · Call <y>", and the live site publishes a
       * Lahore landline beside the WhatsApp number the owner named. The check
       * reported that as "a customer who rings the wrong one is a customer who
       * rings a competitor" - a false alarm about a deliberate design, and one
       * nobody could clear without deleting a real phone number.
       *
       * What IS worth catching is narrower and still catches every version of
       * the original fear:
       *
       *   1. ONE WhatsApp number. Two different wa.me links on one page means
       *      half the booking buttons open a chat with someone else. The owner
       *      named exactly one number (+92 333 7667076); there is no second.
       *   2. NO ORPHAN IN THE STRUCTURED DATA. Every "telephone" in the JSON-LD
       *      must also be a number the page shows - Google reads that field and
       *      puts it in the search result, so a number that exists only there
       *      is a number nobody in the office ever hears ring.
       *
       * Two tel: numbers are fine. That is a landline and a mobile, which is
       * what a courier in Lahore has.
       */
      await t.check("one WhatsApp number, and no phone number that exists only in the structured data", async () => {
        const res = await t.fetch("/");
        t.need(res.ok && res.status === 200, "the home page is not served");

        const last10 = (n) => String(n).replace(/[^\d]/g, "").replace(/^0+/, "").slice(-10);
        const uniq = (xs) => Array.from(new Set(xs.filter(Boolean)));

        const wa = uniq(Array.from(res.body.matchAll(/wa\.me\/(\d+)/g)).map((m) => last10(m[1])));
        const tel = uniq(Array.from(res.body.matchAll(/href=["']tel:([+\d\s-]+)["']/g)).map((m) => last10(m[1])));
        const ld = uniq(Array.from(res.body.matchAll(/"telephone"\s*:\s*"([^"]+)"/g)).map((m) => last10(m[1])));

        t.need(wa.length || tel.length, "the page carries neither a WhatsApp link nor a tel: link");

        if (wa.length > 1) {
          throw new Error(
            "the page links to " + wa.length + " different WhatsApp numbers (last 10 digits): " + wa.join(", ") +
              " - half the booking buttons open a chat with somebody else",
          );
        }

        const shown = new Set([...wa, ...tel]);
        const orphans = ld.filter((n) => !shown.has(n));
        if (orphans.length) {
          throw new Error(
            "the JSON-LD publishes " + orphans.join(", ") + " but no link on the page uses it" +
              " - Google will print a number the office never hears ring. Visible: " +
              (Array.from(shown).join(", ") || "none"),
          );
        }

        return "WhatsApp …" + (wa[0] || "none") + ", " + tel.length + " tel: number(s), structured data agrees";
      });

      /**
       * The editor on this page, found by what it contains.
       *
       * `form[method=post]` is the Sign out button in the header on every
       * signed-in admin page - this check used to click it, see the URL
       * change and pass, having exercised no editor at all and having ended
       * the session the checks after it were holding. The content editor is
       * the POST form that carries the editable fields, and the page says
       * which one that is.
       */
      await t.check("the editor works with JavaScript off", async () => {
        const cookies = await t.context.cookies();
        await t.page.goto(t.url(sectionsPath), { waitUntil: "domcontentloaded" });

        const selector = await t.page.evaluate(() => {
          const forms = Array.from(document.querySelectorAll("form[method=post i]"));
          const editor = forms.find(
            (f) =>
              !/log-?out|sign-?out/i.test(f.getAttribute("action") || "") &&
              f.querySelectorAll("input[type=text], textarea").length > 0,
          );
          if (!editor) return null;
          if (editor.id) return "form#" + editor.id;
          const action = editor.getAttribute("action") || "";
          return action ? 'form[action="' + action + '"]' : null;
        });
        t.need(selector, "no POST form on " + sectionsPath + " carries editable fields");

        return contract.submitsWithoutJavaScript(t, sectionsPath, {
          formSelector: selector,
          submitSelector: selector + " button[type=submit]:not([name]), " + selector + " input[type=submit]:not([name])",
          expect: "saved",
          cookies: cookies.map((c) => ({ name: c.name, value: c.value, domain: c.domain, path: c.path })),
        });
      });

      await t.check("nothing in the editor renders under 14px", async () => {
        await t.page.goto(t.url(sectionsPath), { waitUntil: "domcontentloaded" });
        return contract.nothingUnderMinFont(t.page);
      });
    } finally {
      /* ---- put the words back ---------------------------------------- */

      if (toRestore.length) {
        await t.check("RESTORING: the content is back as it was", async () => {
          const page = t.page;
          await page.goto(t.url(sectionsPath), { waitUntil: "domcontentloaded" }).catch(() => {});
          await page.waitForTimeout(300);

          /*
           * PUT EACH FIELD BACK THROUGH ITS OWN FORM (fixed 2026-09-29).
           *
           * This carried the same two bugs as the edit above - an index from a
           * differently-collected list, and one page-wide `.first()` submit
           * button for every field - and it failed in the loudest possible
           * way: "*** THE LIVE SITE STILL CARRIES THIS HARNESS'S EDITS. PUT
           * THEM BACK BY HAND. ***" on a site that carried no edit at all,
           * because the edit had never saved either. An alarm that fires when
           * nothing is wrong is how a real one gets ignored.
           *
           * Each field is now put back by NAME, all of them in one pass, and
           * then saved and PUBLISHED once - because the public pages this
           * check reads are served from the published version, and a restore
           * that stops at the draft leaves the harness's words live while the
           * check reports success.
           */
          const failures = [];

          /**
           * Put every field back, save, publish, and then LOOK. Twice if it
           * has to, because the editor autosaves on a debounce and a publish
           * that started before the last save landed publishes the version
           * before it - which shows up as a restore that ran clean and left
           * the harness's words on the live site anyway.
           */
          const putBack = async () => {
            // ALWAYS from the editor, every pass. An earlier check ends on
            // /admin?published=N, and a restore that assumes it is still on
            // the editor finds none of its fields and reports the site as
            // un-restorable.
            await page.goto(t.url(sectionsPath), { waitUntil: "domcontentloaded" }).catch(() => {});
            await page.waitForTimeout(600);

            const trouble = [];
            let filled = 0;

            for (const item of toRestore) {
              const field = item.name
                ? fieldByName(page, item.name)
                : page.locator(item.selector).nth(item.nth);
              try {
                // count() first, so a field that is simply not on this page
                // reports that in a sentence rather than as a thirty-second
                // timeout with a call log in it.
                // eslint-disable-next-line no-await-in-loop
                if ((await field.count()) === 0) {
                  trouble.push((item.name || "(field)") + " is not on " + page.url());
                  continue;
                }
                // eslint-disable-next-line no-await-in-loop
                await field.fill(item.original, { timeout: 10000 });
                // The autosave listens for input and fill() dispatches it, but
                // blur is what ends the debounce - so the save starts now
                // rather than whenever something else happens to move focus.
                // eslint-disable-next-line no-await-in-loop
                await field.blur().catch(() => {});
                filled += 1;
              } catch (e) {
                trouble.push(
                  "could not fill " + (item.name || "(field)") + " on " + page.url() + ": "
                    + String((e && e.message) || e).split("\n")[0],
                );
              }
            }

            // Save and publish whatever DID go in. One field that cannot be
            // found must not strand the others on the live site - which is
            // what returning early used to do.
            if (filled > 0) {
              try {
                await saveDraft(page);
                await publishDraft(t, page);
                await page.waitForTimeout(500);
              } catch (e) {
                trouble.push("save/publish: " + String((e && e.message) || e).split("\n")[0]);
              }
            }

            return trouble.length ? trouble.join("; ") : null;
          };

          /** Which public pages still carry something this harness typed. */
          const dirtyPages = async () => {
            const dirty = [];
            for (const p of ["/", "/services", "/about", "/faq", "/contact"]) {
              // eslint-disable-next-line no-await-in-loop
              const res = await t.fetch(p);
              if (!res.ok || res.status !== 200) continue;
              if (/\[harness\]|__contentXss|data-harness/.test(res.body)) dirty.push(p);
            }
            return dirty;
          };

          toRestore.reverse();

          let problem = await putBack();
          if (problem) failures.push({ name: "(restore)", original: problem });

          let still = await dirtyPages();

          if (still.length && !problem) {
            // One retry, from a freshly loaded editor. If the second pass does
            // not clear it, the message below is a real emergency and says so.
            t.log("the first restore did not clear the live site; trying once more from a fresh editor");
            await page.goto(t.url(sectionsPath), { waitUntil: "domcontentloaded" }).catch(() => {});
            await page.waitForTimeout(400);
            problem = await putBack();
            if (problem) failures.push({ name: "(restore, second pass)", original: problem });
            still = await dirtyPages();
          }

          if (still.length || failures.length) {
            throw new Error(
              [
                "*** THE LIVE SITE STILL CARRIES THIS HARNESS'S EDITS. PUT THEM BACK BY HAND. ***",
                "",
                ...(still.length ? ["Pages still showing an edit: " + still.join(", ")] : []),
                // WHAT WENT WRONG, not only that something did. This list used
                // to be collected and never printed, so the loudest message in
                // the suite said "put them back by hand" and gave no clue why
                // the machine could not.
                ...(failures.length
                  ? ["", "What stopped the restore:", ...failures.map((f) => "    " + f.name + ": " + f.original)]
                  : []),
                "",
                "The original values were:",
                ...toRestore.map((i) => "    " + (i.name || "(field)") + " = " + JSON.stringify(i.original)),
              ].join("\n"),
            );
          }
          return toRestore.length + " field(s) restored, and no public page mentions the harness";
        });
      }
    }

    await t.check("the browser stayed quiet over the whole flow", async () => contract.noConsoleErrors(t));
  },
};
