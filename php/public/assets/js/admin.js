/*!
 * admin.js - the admin's browser behaviours, as plain ES2019.
 *
 * A PORT WITH A DIFFERENT SPINE. The React admin (AdminEditor.tsx) keeps the
 * whole draft document in memory, edits it there, diffs it against the live
 * version in the browser and autosaves the result through a server action.
 * A PHP admin on shared hosting cannot do that honestly: the draft lives in
 * MySQL, the diff and every validation belong on the server (they decide what
 * gets published), and a browser copy of a 250-country rate document would be
 * a second source of truth waiting to disagree with the first.
 *
 * So the shape here is inverted. PHP renders real <form>s; every action has a
 * plain POST that works with JavaScript off; and this file makes those forms
 * feel like the React one - debounced autosave instead of a Save button,
 * folds instead of page loads, a filter instead of a query string, a
 * two-step Remove instead of a confirm page.
 *
 * WHAT IS JS-ONLY, AND WHY (nothing here loses the owner any work)
 *   - The find-a-destination filter. With JS off the same box is a GET form
 *     (name="q") and the server renders the filtered table; the enhancement
 *     is only that it filters as you type, without a round trip.
 *   - The data-overflow shadow on wide tables (lib/client/overflow.ts). It is
 *     a hint that the table scrolls sideways; the table scrolls either way.
 *   - The table-wide "unsaved edits / saving / saved" line. Without JS there
 *     is a Save draft button and the line says when the draft was last saved.
 *   - The beforeunload guard. It can only exist with JS; without it the Save
 *     button is explicit, so there is nothing to lose silently.
 *   - The character counter under the hold message, and the two Bulk adjust
 *     warnings ("choose at least one service…"). The server re-checks both.
 *   - The two-step Remove (Remove -> Confirm remove). Without JS the button
 *     is a submit that lands on a confirm page.
 *   - The LADDER's accelerators (section 2a): the keyboard, pasting a column
 *     out of a spreadsheet, the fill preview, copying one country's prices
 *     onto another, and the running "what the customer pays" line. Every one
 *     of them only ever writes into boxes the SERVER already rendered with
 *     their own names, so with the script off the same twenty-five boxes are
 *     typed into by hand and the same POST saves them. The two that cannot
 *     exist at all without a script - copy, and the one-weight column view -
 *     are rendered `hidden` and turned on here; "Download as CSV" is the way
 *     to read the same comparison without them.
 *   Everything else - add a country, quick fill, undo a row, publish, hold,
 *   bulk adjust - is a real form submission that this file only ever
 *   decorates. Nothing is written by JavaScript that the server would not
 *   write from the same POST.
 *
 * NEVER a second pricing engine. The admin's Test-a-price panel posts to the
 * server, which runs the same engine the customer site runs. The running check
 * beside a price box does NOT reimplement it either: the page loads
 * js/pricing.js - the customer's own browser engine, checked against the PHP
 * one by 940 vectors - and calls priceService/bookedTotal/addonsList on it.
 * The only arithmetic written out in this file is the quick fill's
 * "start + i × step", which the server re-does on save anyway.
 *
 * NEVER an extra posted field. PHP stops reading a body at max_input_vars
 * without telling anyone - which is why Http\Admin\DraftForm::wasTruncated
 * exists. Nothing here adds a name: the column view's boxes are unnamed
 * proxies that write through to the real ones, and the copy control's select
 * is unnamed too.
 *
 * ONE FORM PER COUNTRY, so this file autosaves FORMS and not "the editor".
 * The page used to be a single form carrying the whole document; a third
 * service across eleven countries made that 1009 variables against a limit of
 * 1000, and the editor could not save at all. Each country now has its own
 * <form>, its boxes tied to it by the `form` attribute, and section 1 below
 * saves whichever one was typed into - so keying a country costs one ~90
 * variable request, and copying a ladder onto four other countries saves four
 * small forms instead of one large one. Every box still belongs to a form the
 * SERVER rendered, under the name the server gave it.
 */
(function () {
  "use strict";

  var UI = window.Speedat || {};
  var toast = UI.toast || function () {};

  function qsa(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function on(el, ev, fn) {
    if (el) el.addEventListener(ev, fn);
  }

  /* ================================================================== *
   * 1. Autosave - one queue, many forms
   *
   * Contract with the server (also written out in php/docs/assets.md):
   *   <form data-autosave action="…" method="post"> … </form>
   *   The form posts ITSELF, whole, urlencoded, with X-Requested-With: fetch.
   *   The endpoint answers JSON: {"ok":true,"savedAt":"12 Sep 2026, 14:02",
   *   "changes":7,"revision":"9f13…"} or {"ok":false,"message":"…"} - and,
   *   when the refusal is that somebody else moved the draft,
   *   {"ok":false,"conflict":true,"editedBy":"…"}. Same URL, same fields,
   *   same CSRF token as the no-JS submit: one code path on the server.
   *
   * THERE ARE NOW SEVERAL SUCH FORMS - one per country, plus Settings and
   * Website pages - because the single form carrying the whole document grew
   * past PHP's max_input_vars and stopped saving (1009 variables against a
   * limit of 1000). A box belongs to its country's form through the `form`
   * attribute, so `event.target.form` is the form to save and `new
   * FormData(form)` still collects exactly the fields the server rendered.
   *
   * THE SAVES ARE A QUEUE, NEVER PARALLEL, and that is not tidiness. Each
   * write changes the draft's fingerprint, and every form posts the
   * fingerprint it holds; two saves in the air at once would mean the second
   * one carrying a fingerprint the first has just replaced, and the server
   * would rightly refuse it as somebody else's work. "Copy this ladder to
   * every country" touches five forms, so five small requests go out one
   * after another, each with the fingerprint the one before it returned.
   *
   * A CONFLICT STOPS EVERYTHING. When the server says the draft moved, this
   * file does NOT adopt the new fingerprint and try again: that would be the
   * silent overwrite the guard exists to prevent, performed automatically and
   * faster than a person could. It shows who has it and offers a reload.
   * ================================================================== */

  var SAVE_DELAY = 800; // AdminEditor.tsx uses 800ms; keep it identical.

  /** Every form that autosaves, and what each one is doing. */
  var sheets = [];
  var saveTimer = 0;
  var inFlight = false;
  var savedAt = "";
  var conflicted = false;
  var resubmitting = false;
  // THE PAGE'S FORM TOKEN IS DEAD. Set when a save came back signed out or
  // with the token refused (403); the next save fetches a fresh one first -
  // see refreshToken(). Without it "press Retry save" could never work: the
  // token is signed with the session's own secret, so signing in again in
  // another tab mints a new secret and the token already in this page is
  // refused on every press, until a reload threw the typed prices away.
  var tokenStale = false;
  // THIS PAGE IS LEAVING BECAUSE IT WAS ASKED TO - a submit went through. The
  // beforeunload guard warns about failed saves on tab close and link
  // clicks; on Discard, Review and publish, Undo or Fill it put a second
  // "Leave site?" behind a choice the owner had already made. See
  // initSaving().
  var leavingBySubmit = false;
  // The words on the button whose submit is waiting for a save, so a failed
  // save can say WHICH action it stopped ("Review and publish", "Discard").
  var heldAction = "";
  var stateEl = null;
  var countEl = null;
  // The publish bar's two buttons and the two facts that decide whether they
  // may be pressed. The SERVER prints them disabled from `$page->changeCount`,
  // and the autosave moves that count without ever navigating - so unless they
  // are repainted here the bar reads "1 unpublished change" beside a dead
  // Review and publish, and the only way to publish a typed price is a reload.
  // See paintActions().
  var publishBtn = null;
  var discardBtn = null;
  var readOnly = false;
  var changeCount = 0;
  // How many price boxes the last save left exactly as they were, and which
  // country's save reported it - see keep() below.
  var keptCount = 0;
  var keptForm = null;

  function initSaving(root) {
    stateEl = document.querySelector("[data-save-state]");
    countEl = document.querySelector("[data-change-count]");

    var bar = document.querySelector("[data-pubbar]");
    if (bar) {
      publishBtn = bar.querySelector("[data-publish]");
      discardBtn = bar.querySelector("[data-discard]");
      readOnly = bar.hasAttribute("data-read-only");
      changeCount = Number(bar.getAttribute("data-changes")) || 0;
    }

    qsa("form[data-autosave]", root).forEach(function (form) {
      if (!savedAt) savedAt = form.getAttribute("data-saved-at") || "";
        // `failed` is false, or WHY the last save failed: "refused" (the server
      // read it and said no - toolong, toobig, badshape), "signedout" (the
      // session or its CSRF token has gone), "busy" (429) or "network". The
      // reason decides what a submit does next - see onSubmit().
      sheets.push({ form: form, dirty: false, saving: false, failed: false });
    });

    if (!sheets.length) return;

    // THE SAVE BUTTONS STAY ON THE PAGE. They used to be hidden here, on the
    // grounds that the autosave owns them - which left the owner keying
    // twenty-five boxes inside a fold with nothing on screen saying his work
    // was being stored, and nothing to press when a save failed: the only
    // report was one grey line in a bar at the foot of the page, in the same
    // grey as a successful save. The button and the status are now the same
    // object, beside the work. A press saves that form AT ONCE rather than
    // navigating; the plain submit is still what happens with no script.
    qsa("[data-save-submit]", root).forEach(function (b) {
      if (!b.getAttribute("data-save-idle")) b.setAttribute("data-save-idle", b.textContent.trim());
      b.addEventListener("click", onSaveClick);
    });
    qsa("[data-save-note]", root).forEach(function (p) {
      p.textContent =
        "Each country saves on its own, as you type - its Save button says when it last saved, and turns red if a save failed. Settings and Website pages save the same way.";
    });

    // On the page and not on each form: an input reaches its form through the
    // `form` attribute, so the event bubbles to the document and `.form` says
    // which of them to save. A control that belongs to no autosaving form
    // (Bulk adjust, Test a price, the hold message) is ignored here.
    document.addEventListener("input", onEdit);
    document.addEventListener("change", onEdit);

    // CAPTURE, so this runs before the per-form handlers: a submit that is
    // about to leave the page must not leave a typed price behind it. If
    // anything is unsaved it is saved first and the submit is made again.
    document.addEventListener("submit", onSubmit, true);

    // failing() TOO, not only pending(). send() clears `dirty` as the request
    // leaves, so a save that then failed - an expired session, a dropped
    // connection - left nothing "pending" and the tab closed without a word,
    // taking typed prices with it. The edits are still in the boxes; leaving
    // is the moment they are actually lost.
    //
    // EXCEPT a submit this page let through. A sheet the server refused is
    // deliberately not retried by onSubmit(), so Discard - the way out of
    // that state - went through and was then stopped by "Leave site?", after
    // its own data-confirm had already asked. Bubble phase on window, so it
    // sees whether any handler (data-confirm's first press, the filter's
    // Enter) cancelled the submit; a cancelled one is not leaving. pending()
    // still warns: onSubmit() never lets a submit past an unsaved edit, so
    // pending here means something new arrived after it.
    window.addEventListener("submit", function (e) {
      if (e.defaultPrevented) return;
      var form = e.target;
      var btn = e.submitter || null;
      var target = (btn && btn.getAttribute("formtarget")) || (form && form.getAttribute && form.getAttribute("target")) || "";
      if (target && target !== "_self") return; // a new tab or a download frame: this page stays
      markLeaving();
    });
    window.addEventListener("beforeunload", function (e) {
      var deliberate = leavingBySubmit;
      leavingBySubmit = false;
      if (conflicted || pending() || (failing() && !deliberate)) e.preventDefault();
    });

    paint();
    window.Speedat.adminSave = flushAll; // so Publish can flush first
  }

  /**
   * A submit went through and the page is about to go. beforeunload fires as
   * that navigation starts, well inside a second; the flag is dropped after
   * one so a submit that never navigated (a 204, a download) does not leave
   * the tab-close warning switched off for the rest of the session.
   */
  var leavingTimer = 0;
  function markLeaving() {
    leavingBySubmit = true;
    if (leavingTimer) clearTimeout(leavingTimer);
    leavingTimer = setTimeout(function () {
      leavingBySubmit = false;
      leavingTimer = 0;
    }, 1000);
  }

  function sheetOf(form) {
    for (var i = 0; i < sheets.length; i++) if (sheets[i].form === form) return sheets[i];
    return null;
  }

  function pending() {
    for (var i = 0; i < sheets.length; i++) if (sheets[i].dirty || sheets[i].saving) return true;
    return false;
  }

  function firstDirty() {
    for (var i = 0; i < sheets.length; i++) if (sheets[i].dirty) return sheets[i];
    return null;
  }

  function failing() {
    for (var i = 0; i < sheets.length; i++) if (sheets[i].failed) return true;
    return false;
  }

  function saving() {
    for (var i = 0; i < sheets.length; i++) if (sheets[i].saving) return true;
    return false;
  }

  function onEdit(e) {
    var el = e.target;
    if (!el || !el.form) return;
    markDirty(el.form);
  }

  /**
   * A Save button was pressed with the script running. It saves THAT form now
   * instead of navigating, which is what makes the button a retry as well as a
   * save: a failed sheet is cleared of its failure and queued again. A form
   * this file does not autosave (there is none today) is left to submit
   * normally rather than quietly doing nothing.
   */
  function onSaveClick(e) {
    var btn = e.currentTarget;
    var sheet = btn && btn.form ? sheetOf(btn.form) : null;
    if (!sheet || conflicted) return;
    e.preventDefault();
    sheet.failed = false;
    sheet.dirty = true;
    paint();
    flush();
  }

  /** One form has unsaved edits. Exported so the ladder tools can say so too. */
  function markDirty(form) {
    var sheet = sheetOf(form);
    if (!sheet || conflicted) return;
    sheet.dirty = true;
    sheet.failed = false;
    paint();
    schedule();
  }

  function schedule() {
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(flush, SAVE_DELAY);
  }

  function flush() {
    saveTimer = 0;
    if (inFlight || conflicted) return;
    var next = firstDirty();
    if (!next) return;
    if (tokenStale) refreshToken(next);
    else send(next);
  }

  /**
   * A FRESH FORM TOKEN BEFORE A SAVE THAT WAS SIGNED OUT, then the save.
   *
   * Core\Csrf signs every token with the session row's own secret
   * (Session::secret()). Signing in again in another tab starts a NEW row
   * with a new secret, so the token printed into this page is refused for
   * good and "Sign in, then press Retry save" failed on every press - the
   * only way out was a reload, which loses exactly the typed prices the
   * message promised were safe. The page is fetched again (a GET, which
   * changes nothing) and its token is written into every form here, the
   * way adoptRevision() does for the fingerprint, so Publish and Discard
   * carry it too. If that GET lands on the sign-in page he is still signed
   * out and the save stays failed, saying so.
   */
  function refreshToken(sheet) {
    inFlight = true;
    sheet.saving = true;
    paint();

    // dirty is cleared before failSave() so pending() lets go; the boxes
    // still hold the edits and Retry save queues them again.
    function stillOut(reason, message) {
      sheet.dirty = false;
      failSave(sheet, reason, message);
    }

    fetch(location.pathname + location.search, {
      method: "GET",
      credentials: "same-origin",
      headers: { Accept: "text/html" },
    })
      .then(function (r) {
        var path = "";
        try {
          path = new URL(r.url, location.href).pathname;
        } catch (err) {
          path = "";
        }
        if ((r.redirected && /^\/admin\/login/.test(path)) || r.status === 401 || r.status === 403) return null;
        if (!r.ok) throw new Error("status " + r.status);
        return r.text();
      })
      .then(function (html) {
        var found = null;
        if (typeof html === "string") {
          var doc = new DOMParser().parseFromString(html, "text/html");
          found = doc.querySelector('input[name="_token"]');
        }
        if (!found || !found.value) {
          stillOut(
            "signedout",
            "Still signed out, so this was not saved. Sign in in another tab, then press Retry save - your edits are still in the boxes.",
          );
          return;
        }
        qsa('input[name="_token"]').forEach(function (el) {
          el.value = found.value;
        });
        tokenStale = false;
        inFlight = false;
        sheet.saving = false;
        send(sheet);
      })
      .catch(function () {
        stillOut("signedout", "Save failed - check the connection, then press Retry save.");
      });
  }

  /** Save every dirty form, then call back. Used before a submit navigates away. */
  function flushAll(done) {
    if (saveTimer) {
      clearTimeout(saveTimer);
      saveTimer = 0;
    }
    if (typeof done === "function") flushAll.then = done;
    if (!inFlight) flush();
    if (!pending() && flushAll.then) {
      var fn = flushAll.then;
      flushAll.then = null;
      fn();
    }
  }
  flushAll.then = null;

  /**
   * The body of one autosave: the form's fields, with every number box the
   * browser could not read posted as "?".
   *
   * A type=number box holding "4500-", or "4,500" in Firefox and Safari,
   * reports its value as "" (validity.badInput) - so FormData posted a BLANK,
   * DraftForm read blank as "not offered" and deleted the stored 4,500, and
   * the page said "Draft saved". In Settings the same typo saved 0% tax, or a
   * cargo threshold of 0, which means no limit. "?" is not a number, so the
   * server keeps what was stored and counts it in `kept`, and the existing
   * "N boxes did not save" warning appears. The post still completes, which
   * is what lets a held submit go ahead with everything else saved.
   */
  function bodyOf(form) {
    var params = new URLSearchParams(new FormData(form));
    Array.prototype.forEach.call(form.elements, function (el) {
      if (el.type === "number" && el.name && el.validity && el.validity.badInput && !el.disabled) params.set(el.name, "?");
    });
    return params;
  }

  /**
   * WHY A SAVE CAME BACK WITHOUT A SAVE IN IT, from the response itself.
   *
   * The autosave used to call r.json() on whatever arrived. Past the 8 h idle
   * limit the POST is redirected to the sign-in page, and fetch follows the
   * redirect and hands back HTML; a stale CSRF token or a 429 is an HTML error
   * page too. r.json() threw on all of them and the toast said "check the
   * connection" to a man whose connection was fine - and every retry failed
   * the same way. The request now asks for JSON (so ErrorPage answers its
   * envelope), and anything that is not this route's own answer is named here.
   */
  function refusalOf(r) {
    var type = (r.headers && r.headers.get("content-type")) || "";
    var path = "";
    try {
      path = new URL(r.url, location.href).pathname;
    } catch (err) {
      path = "";
    }
    // Both are "signedout" because both are cured the same way - Retry save
    // fetches a fresh token first (refreshToken()) - but they are not worded
    // the same. A 403 that is NOT a redirect to sign-in is
    // HttpError::invalidToken inside a live session: the token passed its
    // 12 h MAX_AGE on a page left open overnight. Telling him he was signed
    // out, when he was not, sent him to sign in for nothing.
    if ((r.redirected && /^\/admin\/login/.test(path)) || r.status === 401 || r.status === 419) {
      return {
        reason: "signedout",
        message: "You were signed out, so this was not saved. Sign in in another tab, then press Retry save - your edits are still in the boxes.",
      };
    }
    if (r.status === 403) {
      return {
        reason: "signedout",
        message: "This page's form expired, so this was not saved. Press Retry save - your edits are still in the boxes.",
      };
    }
    if (r.status === 429) {
      var wait = Number(r.headers.get("retry-after")) || 0;
      return {
        reason: "busy",
        message: wait
          ? "Too many saves at once - not saved. Press Retry save in " + wait + " s; your edits are still in the boxes."
          : "Too many saves at once - not saved. Press Retry save in a moment; your edits are still in the boxes.",
      };
    }
    if (type.indexOf("application/json") === -1) {
      return { reason: "network", message: "Save failed - check the connection, then press Retry save." };
    }
    return null;
  }

  /**
   * A SAVE DID NOT LAND. The one place every failure ends, so all of them do
   * the same three things - and before this, they did not.
   *
   * 1. A SUBMIT HELD FOR THIS SAVE IS DROPPED, not run. The catch path used to
   *    call after(), which ran the held "Review and publish" because pending()
   *    ignores failed sheets: the review was built without the price just
   *    typed. The refusal path left the callback armed instead, so a Discard
   *    the owner had decided against fired minutes later on the next good
   *    save, through requestSubmit, which skips the click-based data-confirm.
   *    The toast says the action was not done, so he presses it again.
   * 2. THE QUEUE KEEPS DRAINING when the refusal was about this form alone;
   *    the refusal path used to return without flushing the other countries.
   *    Signed out or rate limited, the next form would fail the same way, so
   *    those wait for Retry save instead.
   * 3. The sheet is marked failed with its reason, for paint() and onSubmit().
   */
  function failSave(sheet, reason, message) {
    inFlight = false;
    sheet.saving = false;
    sheet.failed = reason;
    if (reason === "signedout") tokenStale = true;
    var held = flushAll.then;
    var action = heldAction;
    flushAll.then = null;
    heldAction = "";
    paint();
    toast(
      held
        ? "Not saved, so " + (action ? "\u201c" + action + "\u201d" : "that") + " was not done - press it again after the save succeeds. " + message
        : message,
    );
    if (reason === "refused" || reason === "network") {
      if (firstDirty()) flush();
    }
  }

  function send(sheet) {
    var form = sheet.form;
    inFlight = true;
    sheet.dirty = false;
    sheet.saving = true;
    paint();

    fetch(form.getAttribute("action") || location.pathname, {
      method: "POST",
      // URL-ENCODED, NOT multipart FormData, and that is not a style choice.
      // Http\Admin\DraftForm::wasTruncated compares the body on the wire with
      // what PHP made of it, and it can only read an urlencoded body back
      // (php://input cannot be re-read for multipart). Posted as FormData, an
      // autosave that PHP cut off at max_input_vars was UNMEASURABLE and
      // answered "Saved" over prices that did not save - the exact failure the
      // guard exists to catch, on the path the owner actually uses. The fields
      // and their names are identical either way, so the server reads one
      // shape from both the autosave and the plain submit.
      body: bodyOf(form),
      credentials: "same-origin",
      headers: { "X-Requested-With": "fetch", Accept: "application/json" },
    })
      .then(function (r) {
        var refused = refusalOf(r);
        if (refused) return { failure: refused };
        return r.json().then(
          function (res) {
            return { res: res, status: r.status };
          },
          function () {
            return { failure: { reason: "network", message: "Save failed - check the connection, then press Retry save." } };
          },
        );
      })
      .then(function (got) {
        if (got.failure) {
          failSave(sheet, got.failure.reason, got.failure.message);
          return;
        }
        var res = got.res;

        if (res && res.conflict) {
          inFlight = false;
          sheet.saving = false;
          flushAll.then = null; // nothing after a conflict may submit by itself
          showConflict(res.editedBy || "", res.message || "");
          return;
        }
        if (!res || !res.ok) {
          // The edits are still in the boxes and the form is left dirty-able:
          // the next keystroke tries again, and the message says what the
          // server refused (a body past max_input_vars names the fix). An
          // ErrorPage envelope ({error:{message}}) is a refusal too.
          var said = (res && (res.message || (res.error && res.error.message))) || "Save failed";
          failSave(sheet, got.status >= 500 ? "network" : "refused", said);
          return;
        }

        inFlight = false;
        sheet.saving = false;

        savedAt = res.savedAt || savedAt;
        sheet.failed = false;
        // THE WRITE LANDED; IT MAY NOT HAVE STORED EVERYTHING. Boxes that did
        // not hold a number keep what was already in the document, and this
        // is the only place the owner can be told so - the no-JS path says it
        // in the redirect, and this path answers JSON.
        keep(sheet, typeof res.kept === "number" ? res.kept : 0, res.message || "");
        // THE FINGERPRINT MOVED WITH THIS WRITE, so every form on the page has
        // to be told: a form still holding the old one would be refused by
        // this very save. See admin/partials/form-keys.
        if (typeof res.revision === "string" && res.revision) adoptRevision(res.revision);
        if (typeof res.changes === "number") {
          changeCount = res.changes;
          if (countEl) {
            countEl.textContent = changeCount
              ? changeCount + " unpublished change" + (changeCount === 1 ? "" : "s")
              : "No unpublished changes";
          }
        }
        // THE TABLE HAS TO AGREE WITH THE BOXES. The lede promises "changed
        // rows carry an orange bar" and the note below promises Undo; before
        // this, both were false for the whole session in which the work was
        // done - a collapsed row went on showing the live price with no mark
        // until the page was reloaded.
        repaintRow(sheet.form);
        paint();
        after();
      })
      .catch(function () {
        failSave(sheet, "network", "Save failed - check the connection, then press Retry save.");
      });
  }

  /** The next form in the queue, or the submit that was waiting for it. */
  function after() {
    if (firstDirty()) {
      flush();
      return;
    }
    if (flushAll.then && !pending()) {
      var fn = flushAll.then;
      flushAll.then = null;
      fn();
    }
  }

  function adoptRevision(value) {
    qsa("[data-revision]").forEach(function (el) {
      el.value = value;
    });
  }

  /**
   * WHAT THIS SAVE DID NOT STORE.
   *
   * A price box holding something that is not a number is not written: the
   * server keeps the value already in the document rather than putting NaN in
   * it, and counts what it kept. The plain submit carries that count in the
   * redirect and the page prints a sentence about it; this path gets JSON, so
   * until it read `kept` the answer was "Draft saved" and the prices that did
   * not save went unmentioned.
   *
   * The warning stays on the screen until the SAME country saves cleanly:
   * clearing it on any other form's save would take it down while the boxes
   * it is about are still wrong. The sentence is the server's own
   * (Notice::keptText), so the two paths cannot word one refusal two ways.
   */
  function keep(sheet, count, message) {
    if (count > 0) {
      keptCount = count;
      keptForm = sheet.form;
    } else if (keptForm === sheet.form) {
      keptCount = 0;
      keptForm = null;
    } else {
      return; // another country saved cleanly; this warning is not about it
    }

    var box = document.querySelector("[data-kept-box]");

    if (box) {
      box.textContent = keptCount ? message || "Some price boxes did not hold a number." : "";
      box.hidden = !keptCount;
    }

    if (count > 0) toast(message || "Some price boxes did not hold a number");
  }

  /**
   * THE PUBLISH BAR'S BUTTONS, kept in step with the count printed beside
   * them. `admin/partials/pubbar` renders them disabled when the stored draft
   * matches the live version; the autosave changes that on a page that never
   * navigates, so the count and the buttons have to move together or the bar
   * tells the owner he has unpublished work and the button that would publish
   * it is dead. It lives in paint() so the failure and conflict paths keep it
   * right too.
   */
  function paintActions() {
    var can = changeCount > 0 && !readOnly;

    if (publishBtn) publishBtn.disabled = !can;
    if (!discardBtn) return;

    discardBtn.disabled = !can;
    // The same sentence RatesPage::discardConfirm() prints, from the same
    // count: the second press must not describe a different draft from the
    // one the bar above it is counting.
    discardBtn.setAttribute(
      "data-confirm",
      "Discard all " + changeCount + " change" + (changeCount === 1 ? "" : "s") + "?",
    );
    if (!can) {
      // Disabling an armed button would leave "Discard all 3 changes?" sitting
      // on a control nobody can press.
      var idle = discardBtn.getAttribute("data-confirm-idle");
      if (idle) discardBtn.textContent = idle;
      discardBtn.classList.remove("is-armed");
    }
  }

  /** "23 Sept 2026, 14:05" -> "14:05", which is what fits on a button. */
  function justTime(text) {
    var found = /(\d{1,2}:\d{2})\s*$/.exec(String(text || ""));
    return found ? found[1] : "";
  }

  /**
   * Every Save button says what happened to the form it belongs to. The small
   * ones sit in the table's pinned actions column, where a long word widens
   * the column over the Prices control, so they get the short wording.
   */
  function paintSaveButtons() {
    qsa("[data-save-submit]").forEach(function (btn) {
      var sheet = btn.form ? sheetOf(btn.form) : null;
      if (!sheet) return;

      var idle = btn.getAttribute("data-save-idle") || "Save";
      var compact = btn.classList.contains("small");
      var when = justTime(savedAt);
      var failed = !conflicted && sheet.failed;

      btn.textContent = conflicted
        ? idle
        : sheet.saving
          ? "Saving…"
          : failed
            ? compact
              ? "Retry save"
              : "Save failed - press to retry"
            : sheet.dirty || when === ""
              ? idle
              : "Saved " + when;

      btn.classList.toggle("danger", !!failed);
      btn.classList.toggle("primary", !failed);
    });
  }

  function paint() {
    paintActions();
    paintSaveButtons();
    if (!stateEl) return;
    stateEl.textContent = conflicted
      ? "Not saved - somebody else changed this draft"
      : saving()
        ? "Saving…"
        : firstDirty()
          ? "Unsaved edits…"
          : failing()
            ? "Save failed - retrying on next edit"
            : keptCount
              ? // Not a plain "Draft saved": part of it did not save, and the
                // line that reports the save is where somebody looks to find
                // out whether it did.
                "Draft saved " +
                savedAt +
                " - " +
                keptCount +
                (keptCount === 1 ? " box was left as it was" : " boxes were left as they were")
              : "Draft saved " + savedAt;

    // A FAILED SAVE MUST NOT LOOK LIKE A SAVED ONE. This line reported
    // "Save failed - retrying on next edit" in the same grey, at the same
    // size, beside the same timestamp as "Draft saved", in a bar at the foot
    // of the page. admin.css gives this class a warning ground and a mark.
    stateEl.classList.toggle("save-alarm", conflicted || failing());
  }

  /**
   * SOMEBODY ELSE CHANGED THE DRAFT. Nothing was written - the server refused
   * inside the transaction that would have done the writing - and nothing here
   * tries again, because trying again means writing over their prices.
   *
   * The box is the same one the server fills after a refused plain submit, so
   * both paths say the same thing in the same place.
   */
  function showConflict(who, message) {
    conflicted = true;
    paint();

    var box = document.querySelector("[data-conflict-box]");
    if (!box) {
      toast(message || "Somebody else changed this draft - reload the page");
      return;
    }

    box.textContent = "";
    var lead = document.createElement("strong");
    lead.textContent = "Nothing was saved. ";
    var text = document.createElement("span");
    // textContent: `who` carries a colleague's address out of the database.
    // "brought up to date ..." is Repo::catchUpDraft(), not a colleague
    // (Draft::editedBy()); it must not be called "somebody else".
    text.textContent =
      who && who.indexOf("brought up to date") === 0
        ? "While the page was open this draft was " + who + ". Reload to see it, then make your change again. Your edits are still in the boxes until you do."
        : (who ? "Somebody else changed this draft while the page was open - it was " + who + ". " : "Somebody else changed this draft while the page was open. ") +
          "Reload to see their version, then make your change again. Your edits are still in the boxes until you do.";
    var reload = document.createElement("a");
    reload.className = "btn small";
    reload.href = location.pathname;
    reload.textContent = "Reload";
    reload.setAttribute("data-conflict-reload", "");

    box.appendChild(lead);
    box.appendChild(text);
    box.appendChild(reload);
    box.hidden = false;
    box.scrollIntoView({ block: "nearest" });
    toast("Nothing was saved - somebody else changed this draft");
  }

  /**
   * A submit is about to leave the page. Anything typed and not yet saved
   * goes first, and then the submit is made again with the same button - the
   * button matters, because "undo=gb" and "fill=all:gb:express" live in it.
   */
  function onSubmit(e) {
    if (resubmitting || conflicted) return;

    // A FAILED SAVE IS RETRIED BEFORE THE PAGE IS LEFT, not stepped over. A
    // country whose save failed is not pending (send() cleared `dirty`), so
    // Review and publish used to go straight through and build the review
    // without it. Its edits are queued again here; if the retry fails too,
    // failSave() drops this submit and says so. A save the SERVER refused
    // (too long, too big, a bad shape) is not retried: it would be refused
    // again, and Discard - the way out of exactly that state - would never go
    // through. Nor does beforeunload ask about them once this lets a submit
    // go: the red Save button has said so, and the owner pressed Discard (or
    // Review and publish) anyway. Closing the tab still asks.
    var retry = [];
    sheets.forEach(function (sheet) {
      if (sheet.failed && sheet.failed !== "refused") retry.push(sheet);
    });
    if (!pending() && !retry.length) return;

    var form = e.target;
    if (!form || !form.tagName || form.tagName !== "FORM") return;

    var btn = e.submitter || null;
    e.preventDefault();
    heldAction = btn ? (btn.getAttribute("data-confirm-idle") || btn.textContent || "").trim() : "";

    retry.forEach(function (sheet) {
      sheet.failed = false;
      sheet.dirty = true;
    });
    paint();

    flushAll(function () {
      // RESET IN finally, AND THAT IS THE POINT. The submit event fires
      // synchronously inside requestSubmit, so the flag is only needed for
      // that one call. Left set, a resubmit that another handler cancelled
      // (Enter in Find a destination, which initFilter stops) kept it true
      // for the rest of the session, and every later submit skipped the
      // save-first step.
      heldAction = "";
      resubmitting = true;
      try {
        if (form.requestSubmit) form.requestSubmit(btn && btn.form === form ? btn : undefined);
        else if (btn) btn.click();
        else {
          markLeaving(); // form.submit() fires no submit event to say so
          form.submit();
        }
      } finally {
        resubmitting = false;
      }
    });
  }

  /* ================================================================== *
   * 2. The rates table - filter, overflow shadow, folds, undo, remove
   * ================================================================== */

  /** Lower-case, accent-free key, as format.ts nameKey does, so "Türkiye" finds "turkiye". */
  function nameKey(name) {
    return String(name === null || name === undefined ? "" : name)
      .normalize("NFD")
      .replace(/[̀-ͯ]/g, "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, " ")
      .trim();
  }

  /**
   * Which sides of a sideways-scrolling box still hide content
   * (lib/client/overflow.ts). The overlay scrollbars of Windows 11 and macOS
   * are not drawn at rest, so the pinned first/last columns cast a shadow
   * instead - this is what tells the owner the table goes on.
   */
  function hiddenEdges(scrollLeft, clientWidth, scrollWidth) {
    if (scrollWidth - clientWidth <= 1) return "";
    var left = scrollLeft > 1;
    var right = scrollLeft + clientWidth < scrollWidth - 1;
    if (left && right) return "left right";
    if (left) return "left";
    if (right) return "right";
    return "";
  }

  function initOverflow(el) {
    function mark() {
      var edges = hiddenEdges(el.scrollLeft, el.clientWidth, el.scrollWidth);
      if (edges) el.setAttribute("data-overflow", edges);
      else el.removeAttribute("data-overflow");
    }
    mark();
    el.addEventListener("scroll", mark, { passive: true });
    if (typeof ResizeObserver !== "undefined") {
      var ro = new ResizeObserver(mark);
      ro.observe(el);
      if (el.firstElementChild) ro.observe(el.firstElementChild);
    }
  }

  /**
   * Find a destination. The same input is name="q" inside a GET form, so with
   * JS off it filters on the server; here it filters as you type and the form
   * never submits. Rows carry data-name.
   */
  function initFilter(input) {
    var table = document.getElementById(input.getAttribute("data-filter-table") || "");
    if (!table) return;
    var form = input.form;
    if (form) form.addEventListener("submit", function (e) {
      e.preventDefault();
    });
    input.addEventListener("input", function () {
      var k = nameKey(input.value);
      qsa("tr[data-name]", table).forEach(function (tr) {
        var hit = !k || nameKey(tr.getAttribute("data-name")).indexOf(k) >= 0;
        tr.hidden = !hit;
        // The per-kilogram fold belongs to the row above it.
        var fold = tr.nextElementSibling;
        if (fold && fold.classList.contains("grid-row")) fold.hidden = !hit || fold.getAttribute("data-open") !== "1";
      });
    });
  }

  /**
   * "Prices ▾" / "Hide prices ▴": the per-kilogram boxes for one country.
   * Server-rendered and hidden, so with JS off the fold is a link back to the
   * same page with ?open=<id> and the server renders it open.
   */
  function initPriceFolds(root) {
    qsa("[data-fold-toggle]", root).forEach(function (btn) {
      var row = document.getElementById(btn.getAttribute("data-fold-toggle"));
      if (!row) return;
      // Turn the no-JS link into a button now that JS is here.
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        var open = row.getAttribute("data-open") === "1";
        row.setAttribute("data-open", open ? "0" : "1");
        row.hidden = open;
        btn.setAttribute("aria-expanded", open ? "false" : "true");
        btn.textContent = open ? "Prices ▾" : "Hide prices ▴";
      });
    });
  }

  /* ================================================================== *
   * 2a. The LADDER - one service's price for every whole kilogram
   *
   * This is the product's daily job: twenty-five numbers per service per
   * country, keyed off a carrier's sheet. Everything in this section exists
   * because doing that with nothing but twenty-five text boxes was measured
   * and found to cost twenty-five pointer trips, no way to check the result,
   * and no way to reuse a ladder that was already right.
   *
   * NOTHING HERE IS A SECOND PRICING ENGINE. The running check calls
   * SpeedatPricing.priceService - the customer's own engine, loaded on this
   * page by admin/layouts/shell - so the number beside the box is produced by
   * the code 940 vectors check against the server's. The only arithmetic
   * written out here is the fill's `start + i × step`, which the server redoes
   * on save anyway.
   *
   * NOTHING HERE ADDS A POSTED FIELD. Every box it writes into is a box the
   * server already rendered with a name; the column view's boxes and the copy
   * control's select carry no name at all. The form posts what it always
   * posted, which is what keeps Http\Admin\DraftForm::wasTruncated - and the
   * max_input_vars limit behind it - meaning what it meant.
   * ================================================================== */

  var PRICING = window.SpeedatPricing || null;

  /**
   * The pricing settings AS THEY ARE ON THIS PAGE RIGHT NOW - read out of the
   * Settings section's own boxes, not out of a copy taken when the page was
   * rendered. The owner can change the tax and the rounding and see the
   * running check move, which is the whole point of showing it to him.
   */
  function settingsNow() {
    function field(name) {
      var el = document.querySelector('[name="settings[' + name + ']"]');
      return el ? String(el.value) : "";
    }
    function num(name, fallback) {
      var n = Number(field(name));
      return isFinite(n) ? n : fallback;
    }
    return {
      pricingMode: field("pricingMode") || "grid",
      currency: field("currency").trim() || "PKR",
      taxPct: num("taxPct", 0),
      roundTo: num("roundTo", 1) || 1,
      maxKg: num("maxKg", 0),
      firstKg: num("firstKg", 0.5),
      stepKg: num("stepKg", 0.5),
      volumetricDivisor: num("volumetricDivisor", 5000),
      addons: field("addons"),
    };
  }

  /** The add-ons a customer gets without ticking anything. */
  function standingAddons(settings) {
    if (!PRICING) return [];
    return PRICING.addonsList(settings).filter(function (a) {
      return a.on;
    });
  }

  function boxesOf(scope) {
    return qsa("[data-kg-box]", scope);
  }

  /** This ladder as the engine wants a grid: { "1": 4200, "2": 5900, … }. */
  function gridOf(scope) {
    var grid = {};
    boxesOf(scope).forEach(function (box) {
      var text = String(box.value).trim();
      if (text === "") return;
      var n = Number(text);
      if (isFinite(n)) grid[box.getAttribute("data-kg")] = n;
    });
    return grid;
  }

  /**
   * The three checks, recomputed as the owner types. They are the SAME three
   * View\Admin\DestinationRow ran on the server, so the marks on the page are
   * the same marks with the script off:
   *   low  - priced below the kilogram underneath (the ladder runs backwards)
   *   gap  - blank between two priced kilograms (not offered at that weight:
   *          a blank box is not a price, owner 2026-09-28)
   *   edited - differs from the version the website is serving
   */
  function markLadder(scope) {
    var boxes = boxesOf(scope);
    var row = scope.closest("tr");
    var live = liveSnapshot(row ? row.previousElementSibling : null);

    var previous = null;
    var anyBelow = false;
    var state = [];

    boxes.forEach(function (box) {
      var text = String(box.value).trim();
      // "4500-" or "4,500" in a number box reads as "" (validity.badInput):
      // it is NOT a blank, and the autosave posts it as "?" so the stored
      // price is kept - so it is marked as a box that is not a number.
      var unread = !!(box.validity && box.validity.badInput);
      var n = text === "" ? null : Number(text);
      var bad = unread || (text !== "" && !isFinite(n));
      var low = !bad && n !== null && previous !== null && n < previous;
      state.push({ box: box, kg: Number(box.getAttribute("data-kg")), blank: text === "" && !unread, bad: bad, low: low, gap: text === "" && !unread && anyBelow });
      if (n !== null && isFinite(n)) {
        previous = n;
        anyBelow = true;
      }
    });

    // A blank after the LAST price is "we do not carry that weight", not a gap.
    var seenAbove = false;
    for (var i = state.length - 1; i >= 0; i--) {
      if (!state[i].blank) {
        seenAbove = true;
        continue;
      }
      state[i].gap = state[i].gap && seenAbove;
    }

    var low = [];
    var gap = [];
    var bad = [];
    var filled = 0;

    state.forEach(function (s) {
      var label = s.box.closest(".grid-box");
      if (!label) return;
      // A box holding letters wears the same mark as one that runs backwards:
      // both are a price the owner cannot have meant.
      label.classList.toggle("is-low", s.low || s.bad);
      label.classList.toggle("is-gap", s.gap);
      var name = s.box.getAttribute("name");
      var was = live && Object.prototype.hasOwnProperty.call(live, name) ? String(live[name]) : null;
      label.classList.toggle("is-edited", was !== null && was !== String(s.box.value).trim());
      if (s.low) low.push(s.kg);
      if (s.gap) gap.push(s.kg);
      if (s.bad) bad.push(s.kg);
      if (!s.blank) filled++;
      if (s.low || s.gap || s.bad) s.box.setAttribute("aria-invalid", "true");
      else s.box.removeAttribute("aria-invalid");
    });

    paintNote(scope, low, gap, bad);

    var count = scope.querySelector("[data-ladder-count]");
    if (count) {
      count.textContent = filled === state.length ? "all " + state.length + " priced" : filled + " of " + state.length + " priced";
    }
  }

  /** "4, 6 and 9 kg" - the weights a note names, the same shape PHP prints. */
  function kgList(kgs) {
    var shown = kgs.slice(0, 6);
    var rest = kgs.length - shown.length;
    var text =
      shown.length === 1 ? shown[0] + " kg" : shown.slice(0, -1).join(", ") + " and " + shown[shown.length - 1] + " kg";
    return rest > 0 ? text + " (and " + rest + " more)" : text;
  }

  /**
   * The sentence under the ladder. In place, quiet, and never a dialog.
   *
   * Three tones, the same three View\Admin\RateCells::ladderTone() prints:
   * a rung priced below the one under it is WRONG and is orange; blanks are
   * merely sparse and are stated in the hint colour; a sound ladder says
   * nothing. An alarm that fires on the ordinary case gets ignored.
   */
  function paintNote(scope, low, gap, bad) {
    var note = scope.querySelector("[data-ladder-note]");
    if (!note) return;
    var parts = [];
    if (bad.length) parts.push(kgList(bad) + (bad.length === 1 ? " is" : " are") + " not a number, so the stored price is kept.");
    if (low.length)
      parts.push(kgList(low) + (low.length === 1 ? " is" : " are") + " priced below the kilogram underneath, so a heavier parcel costs less.");
    // The same sentence DestinationRow::ladderNote() prints. It used to say
    // "charged at the next heavier price", which stopped being true when a
    // blank box stopped being a price - the customer is sent to WhatsApp.
    if (gap.length)
      parts.push(
        kgList(gap) + (gap.length === 1 ? " is blank, so it is" : " are blank, so they are") + " not offered (customers are sent to WhatsApp).",
      );
    note.textContent = parts.join(" ");
    note.className = "ladder-note " + (low.length || bad.length ? "warn" : parts.length ? "info" : "ok");
    note.hidden = !parts.length;
  }

  /** The live values the server printed on the country row, for "is this edited?". */
  function liveSnapshot(row) {
    if (!row || !row.getAttribute) return null;
    var raw = row.getAttribute("data-live");
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch (err) {
      return null;
    }
  }

  /**
   * THE RUNNING CHECK: what the customer pays for the kilogram being typed.
   *
   * It prices the ladder as it stands with the customer's own engine, so it
   * also answers the question a blank box raises - a 7 kg parcel with 7 kg
   * blank has no price (a blank box is not a price, owner 2026-09-28), and the
   * line says so.
   */
  function runningCheck(scope, box) {
    var out = scope.querySelector("[data-ladder-running]");
    if (!out) return;
    if (!box || !PRICING) {
      out.textContent = "";
      return;
    }

    var kg = Number(box.getAttribute("data-kg"));
    var settings = settingsNow();
    var serviceId = scope.getAttribute("data-service") || "s";
    var dest = { id: "x", name: "", active: true, rates: {} };
    dest.rates[serviceId] = { grid: gridOf(scope) };

    var priced = null;
    try {
      priced = PRICING.priceService(settings, dest, serviceId, Math.round(kg * 1000));
    } catch (err) {
      priced = null;
    }

    if (!priced) {
      out.textContent = kg + " kg has no price yet - nothing is charged at this weight.";
      return;
    }

    var addons = standingAddons(settings);
    var total = PRICING.bookedTotal(priced.total, addons);

    var work = [];
    if (priced.gridKg && priced.gridKg !== kg) work.push("charged at the " + priced.gridKg + " kg box");
    work.push(PRICING.fmtNum(priced.base) + " in the box");
    if (settings.taxPct) work.push("+ " + settings.taxPct + "% tax");
    if (settings.roundTo > 1) work.push("rounded to " + settings.roundTo);
    addons.forEach(function (a) {
      work.push("+ " + a.label + " " + PRICING.fmtNum(a.amount));
    });

    out.innerHTML = "";
    var lead = document.createTextNode(kg + " kg \u2192 customer pays ");
    var strong = document.createElement("strong");
    strong.textContent = PRICING.fmtMoney(total, settings.currency);
    var tail = document.createElement("span");
    tail.className = "work";
    // textContent, never innerHTML: an add-on label is the owner's free text.
    tail.textContent = " (" + work.join(", ") + ")";
    out.appendChild(lead);
    out.appendChild(strong);
    out.appendChild(tail);
  }

  /**
   * The form a ladder's boxes belong to. They are tied to their country's
   * form by the `form` attribute rather than by being inside it, so the way
   * to find it is to ask a box - an "input" event dispatched on the ladder
   * <div> has no `.form` and the autosave would never hear it.
   */
  function formOf(scope) {
    var box = scope ? scope.querySelector("input[name]") : null;
    return box ? box.form : null;
  }

  /** Tell the autosave that this country's form has edits in it. */
  function touched(scope) {
    var form = formOf(scope);
    if (form) markDirty(form);
  }

  /** One ladder changed: re-mark it and let the autosave pick its form up. */
  function ladderChanged(scope) {
    markLadder(scope);
    syncColumnView();
    touched(scope);
  }

  /**
   * FILL FROM A BASE PRICE - with the arithmetic shown BEFORE it is applied.
   *
   * The two buttons are ordinary submits to /admin/rates/fill and work with
   * the script off; what this adds is the preview line and the counts, so the
   * owner can see that 4,200 + 700 lands on 21,000 at 25 kg without filling
   * twenty-five boxes to find out.
   */
  function fillPreview(scope) {
    var out = scope.querySelector("[data-fill-preview]");
    if (!out) return;
    var startEl = scope.querySelector("[data-fill-start]");
    var stepEl = scope.querySelector("[data-fill-step]");
    var start = Number(startEl && startEl.value);
    var step = Number(stepEl && stepEl.value);
    var boxes = boxesOf(scope);

    if (!isFinite(start) || start <= 0 || !boxes.length) {
      out.textContent = "";
      return;
    }
    if (!isFinite(step) || step < 0) step = 0;

    var blanks = boxes.filter(function (b) {
      return String(b.value).trim() === "";
    }).length;

    var first = Math.round(start);
    var last = Math.round(start + (boxes.length - 1) * step);
    var second = boxes.length > 1 ? Math.round(start + step) : first;
    var topKg = boxes[boxes.length - 1].getAttribute("data-kg");

    out.textContent =
      "1 kg " +
      (PRICING ? PRICING.fmtNum(first) : first) +
      " \u00b7 2 kg " +
      (PRICING ? PRICING.fmtNum(second) : second) +
      " \u2026 " +
      topKg +
      " kg " +
      (PRICING ? PRICING.fmtNum(last) : last) +
      " \u2014 Fill blanks writes " +
      blanks +
      ", Fill all writes " +
      boxes.length +
      ".";
  }

  /**
   * Quick fill: the boxes from two numbers (start + i × step, rounded). "Fill
   * blanks" leaves typed boxes alone; "Fill all" overwrites. Filling only
   * writes into the inputs - the autosave then stores them exactly as if they
   * had been typed, so the server sees an ordinary edit and re-validates it.
   */
  function initQuickFill(root) {
    qsa("[data-fill]", root).forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        var scope = btn.closest("[data-fill-scope]");
        if (!scope) return;
        var startEl = scope.querySelector("[data-fill-start]");
        var stepEl = scope.querySelector("[data-fill-step]");
        var start = Number(startEl && startEl.value);
        var step = Number(stepEl && stepEl.value);
        if (!isFinite(start) || start <= 0 || !isFinite(step) || step < 0) return;
        var overwrite = btn.getAttribute("data-fill") === "all";
        var boxes = boxesOf(scope);
        var wrote = 0;
        boxes.forEach(function (box, i) {
          if (!overwrite && String(box.value).trim() !== "") return;
          box.value = String(Math.round(start + i * step));
          wrote++;
        });
        if (!wrote) return;
        // One synthetic event for the whole fill: the autosave posts the form
        // as it now stands, so 25 boxes cost one request, not 25.
        ladderChanged(scope);
        toast(wrote + " price" + (wrote === 1 ? "" : "s") + " filled");
      });
    });
  }

  /**
   * COPY THIS LADDER ONTO ANOTHER COUNTRY, OR ONTO ALL OF THEM.
   *
   * It writes into boxes that are already in this form - the ordinary autosave
   * stores them and the server validates them like any other edit - so it is
   * fast without being a back door. It needs those boxes in the page, which is
   * why it is hidden until this runs: with the script off there is nothing
   * honest for the control to do, and "Download as CSV" is the way out.
   */
  function initCopyTool(scope) {
    var tool = scope.querySelector("[data-copy-tool]");
    if (!tool) return;
    var select = tool.querySelector("[data-copy-target]");
    var go = tool.querySelector("[data-copy-go]");
    var say = tool.querySelector("[data-copy-preview]");
    if (!select || !go) return;

    tool.hidden = false;
    go.hidden = false;

    var serviceId = scope.getAttribute("data-service");
    var fromName = scope.getAttribute("data-dest-name") || "this country";

    function targets() {
      var want = select.value;
      if (!want) return [];
      return qsa('[data-ladder][data-service="' + cssEscape(serviceId) + '"]').filter(function (other) {
        if (other === scope) return false;
        return want === "*" || other.getAttribute("data-dest") === want;
      });
    }

    function describe() {
      if (!say) return;
      var to = targets();
      if (!to.length) {
        say.textContent = "";
        return;
      }
      var values = Object.keys(gridOf(scope)).length;
      say.textContent =
        values +
        " price" +
        (values === 1 ? "" : "s") +
        " from " +
        fromName +
        " would replace " +
        (to.length === 1 ? to[0].getAttribute("data-dest-name") || "that country" : to.length + " countries") +
        "'s " +
        (scope.getAttribute("data-service-name") || "") +
        " prices.";
    }

    select.addEventListener("change", describe);

    go.addEventListener("click", function (e) {
      e.preventDefault();
      var to = targets();
      if (!to.length) {
        toast("Choose a country to copy these prices to");
        return;
      }
      var source = {};
      boxesOf(scope).forEach(function (b) {
        source[b.getAttribute("data-kg")] = b.value;
      });

      var wrote = 0;
      to.forEach(function (other) {
        boxesOf(other).forEach(function (b) {
          var kg = b.getAttribute("data-kg");
          if (!Object.prototype.hasOwnProperty.call(source, kg)) return;
          if (b.value === source[kg]) return;
          b.value = source[kg];
          wrote++;
        });
        markLadder(other);
      });

      if (!wrote) {
        toast("Those prices were already the same");
        return;
      }
      syncColumnView();
      // The prices landed in OTHER countries' forms, so those are the forms
      // with unsaved edits in them - this one has not changed at all. The
      // autosave queue sends them one after another, each carrying the
      // fingerprint the one before it returned.
      to.forEach(touched);
      toast(
        wrote + " price" + (wrote === 1 ? "" : "s") + " copied to " + (to.length === 1 ? to[0].getAttribute("data-dest-name") : to.length + " countries"),
      );
    });

    describe();
  }

  /** An attribute value made safe for a selector, without CSS.escape everywhere. */
  function cssEscape(value) {
    return String(value === null || value === undefined ? "" : value).replace(/["\\]/g, "\\$&");
  }

  /**
   * THE KEYBOARD. Keying a carrier's sheet is a two-handed job and the mouse
   * is the slow hand, so the ladder answers the keys a spreadsheet answers:
   *
   *   Enter / ↓         the next kilogram
   *   Shift+Enter / ↑   the previous one
   *   ← →               left and right along the ladder
   *   Esc               put this box back the way it was found
   *   Tab / Shift+Tab   unchanged: the DOM order IS kilogram order
   *
   * The arrow keys are intercepted rather than left alone: on a number input a
   * bare ↑ or ↓ is a SPINNER, and a ladder where "move down one rung" silently
   * changes a price by 1 is worse than no keyboard at all.
   */
  /** The ladder box that last had focus - which running check a settings edit repaints. */
  var lastLadderBox = null;

  function initLadderKeys(scope) {
    var boxes = boxesOf(scope);

    boxes.forEach(function (box, i) {
      box.addEventListener("focus", function () {
        // What Escape restores, and what the running check describes.
        box.setAttribute("data-was", box.value);
        lastLadderBox = box;
        runningCheck(scope, box);
      });
      box.addEventListener("blur", function () {
        box.removeAttribute("data-was");
      });
      box.addEventListener("keydown", function (e) {
        if (e.altKey || e.ctrlKey || e.metaKey) return;
        var go = null;
        if (e.key === "Enter") go = e.shiftKey ? i - 1 : i + 1;
        else if (e.key === "ArrowDown") go = i + 1;
        else if (e.key === "ArrowUp") go = i - 1;
        else if (e.key === "ArrowRight" && atEnd(box)) go = i + 1;
        else if (e.key === "ArrowLeft" && atStart(box)) go = i - 1;
        else if (e.key === "Escape") {
          if (!box.hasAttribute("data-was")) return;
          e.preventDefault();
          box.value = box.getAttribute("data-was");
          ladderChanged(scope);
          runningCheck(scope, box);
          return;
        }
        if (go === null) return;
        e.preventDefault(); // never let ↑/↓ work the spinner
        var next = boxes[go];
        if (!next) return;
        next.focus();
        if (next.select) next.select();
      });
    });
  }

  /** Is the caret at the very end / start of the box? (so ← → still edit text) */
  function atEnd(el) {
    try {
      return el.selectionStart === null || el.selectionStart === String(el.value).length;
    } catch (err) {
      return true; // a number input refuses selectionStart in some browsers
    }
  }
  function atStart(el) {
    try {
      return el.selectionStart === null || el.selectionStart === 0;
    } catch (err) {
      return true;
    }
  }

  /**
   * PASTE A COLUMN STRAIGHT OUT OF A SPREADSHEET.
   *
   * The carrier sends a sheet; the owner selects the price column, copies it,
   * clicks the kilogram it starts at and pastes. Tabs and newlines both split,
   * so a column and a row both work, and each cell goes through the engine's
   * own lenient number reader - "Rs 4,500/-" is 4500, "-" and "n/a" are blanks.
   * A single value is left to the browser to paste as it always would.
   */
  function initLadderPaste(scope) {
    var boxes = boxesOf(scope);

    boxes.forEach(function (box, start) {
      box.addEventListener("paste", function (e) {
        var data = e.clipboardData || window.clipboardData;
        if (!data) return;
        var text = data.getData("text");
        if (!text || !/[\t\r\n]/.test(text)) return; // one value: ordinary paste

        var cells = String(text)
          .split(/[\r\n\t]+/)
          .map(function (s) {
            return s.trim();
          })
          .filter(function (s) {
            return s !== "";
          });
        if (cells.length < 2) return;

        e.preventDefault();
        var wrote = 0;
        var skipped = 0;
        for (var i = 0; i < cells.length && start + i < boxes.length; i++) {
          var n = PRICING ? PRICING.toNumLoose(cells[i]) : Number(cells[i]);
          if (n === null) {
            boxes[start + i].value = "";
            wrote++;
            continue;
          }
          if (typeof n !== "number" || !isFinite(n) || n < 0) {
            skipped++;
            continue;
          }
          boxes[start + i].value = String(n);
          wrote++;
        }
        var over = cells.length - (boxes.length - start);
        ladderChanged(scope);
        runningCheck(scope, box);
        toast(
          wrote +
            " price" +
            (wrote === 1 ? "" : "s") +
            " pasted from " +
            box.getAttribute("data-kg") +
            " kg" +
            (skipped ? ", " + skipped + " skipped (not a number)" : "") +
            (over > 0 ? ", " + over + " past the top of the ladder ignored" : ""),
        );
      });
    });
  }

  /**
   * A mouse wheel over a FOCUSED number box changes its value. In a page whose
   * whole job is scrolling past two hundred number boxes, that is a price the
   * owner never typed and never saw change - it was measured happening: a
   * 17,200 became 17,199 on the way past. The box gives up focus instead.
   */
  function initWheelGuard(root) {
    root.addEventListener(
      "wheel",
      function (e) {
        var el = e.target;
        if (!el || !el.tagName || el.tagName !== "INPUT" || el.type !== "number") return;
        if (document.activeElement !== el) return;
        el.blur();
      },
      { passive: true },
    );
  }

  function initLadders(root) {
    qsa("[data-ladder]", root).forEach(function (scope) {
      initLadderKeys(scope);
      initLadderPaste(scope);
      initCopyTool(scope);
      scope.addEventListener("input", function (e) {
        if (e.target && e.target.hasAttribute && e.target.hasAttribute("data-kg-box")) {
          markLadder(scope);
          runningCheck(scope, e.target);
          syncColumnView();
        }
        if (e.target && e.target.hasAttribute && (e.target.hasAttribute("data-fill-start") || e.target.hasAttribute("data-fill-step"))) {
          fillPreview(scope);
        }
      });
      fillPreview(scope);
    });

    // The keyboard line is only true once this file has run.
    qsa("[data-keys-note]", root).forEach(function (el) {
      el.hidden = false;
    });

    // A settings change (tax, rounding, a standing charge) moves every running
    // check, so the one on screen is repainted when the owner edits them.
    // The box is the LAST ONE THAT HAD FOCUS, not document.activeElement:
    // while tax is being typed the active element is the tax box, which is in
    // no ladder, so the line never moved and stood stale until a price box was
    // focused again.
    qsa('[name^="settings["]').forEach(function (el) {
      el.addEventListener("input", function () {
        var box = lastLadderBox;
        if (!box || !box.isConnected) return;
        var scope = box.closest ? box.closest("[data-ladder]") : null;
        if (scope) runningCheck(scope, box);
      });
    });
  }

  /* ================================================================== *
   * 2b. ONE WEIGHT, EVERY COUNTRY
   *
   * A rate change arrives as "10 kg is going up on the Gulf routes", and
   * reading that out of the folds means opening one country at a time and
   * remembering. This lays the one rung out down the page.
   *
   * The boxes it draws are PROXIES with NO NAME: they are never posted. Each
   * writes straight through to the real box in the fold, so there is one
   * source of truth, the autosave posts exactly the fields it always posted,
   * and the field count the server's max_input_vars guard reasons about does
   * not move.
   * ================================================================== */

  function initColumnView(root) {
    var panel = root.querySelector("[data-colview]");
    var open = root.querySelector("[data-colview-open]");
    if (!panel || !open) return;

    var kgSel = panel.querySelector("[data-colview-kg]");
    var svcSel = panel.querySelector("[data-colview-service]");
    var body = panel.querySelector("[data-colview-body]");
    var close = panel.querySelector("[data-colview-close]");
    if (!kgSel || !svcSel || !body) return;

    open.hidden = false;
    if (close) close.hidden = false;

    function draw() {
      var kg = kgSel.value;
      var service = svcSel.value;
      body.textContent = "";

      var ladders = qsa('[data-ladder][data-service="' + cssEscape(service) + '"]', root);
      if (!ladders.length) {
        var none = document.createElement("p");
        none.className = "colview-empty";
        none.textContent = "No country prices that service yet.";
        body.appendChild(none);
        return;
      }

      ladders.forEach(function (scope) {
        var real = scope.querySelector('[data-kg-box][data-kg="' + cssEscape(kg) + '"]');
        if (!real) return;
        var label = real.closest(".grid-box");

        var row = document.createElement("div");
        row.className = "colview-row";
        if (label) {
          ["is-low", "is-gap", "is-edited"].forEach(function (c) {
            if (label.classList.contains(c)) row.classList.add(c);
          });
        }

        var who = document.createElement("span");
        who.className = "who";
        // textContent: a country name is the owner's own free text.
        who.textContent = scope.getAttribute("data-dest-name") || "(unnamed country)";

        var proxy = document.createElement("input");
        proxy.type = "number";
        proxy.step = "any";
        proxy.min = "0";
        proxy.inputMode = "decimal";
        proxy.value = real.value;
        proxy.disabled = real.disabled;
        proxy.setAttribute("aria-label", who.textContent + " " + kg + " kg price");
        proxy.setAttribute("data-colview-proxy", real.getAttribute("name"));

        proxy.addEventListener("input", function () {
          real.value = proxy.value;
          markLadder(scope);
          var l = real.closest(".grid-box");
          ["is-low", "is-gap", "is-edited"].forEach(function (c) {
            row.classList.toggle(c, !!(l && l.classList.contains(c)));
          });
          real.dispatchEvent(new Event("input", { bubbles: true }));
        });

        row.appendChild(who);
        row.appendChild(proxy);
        body.appendChild(row);
      });
    }

    function show(on) {
      panel.hidden = !on;
      open.setAttribute("aria-expanded", on ? "true" : "false");
      open.textContent = on ? "Hide the weight comparison" : "Compare one weight";
      if (on) {
        draw();
        kgSel.focus();
      }
    }

    open.addEventListener("click", function (e) {
      e.preventDefault();
      show(panel.hidden);
    });
    if (close) close.addEventListener("click", function (e) {
      e.preventDefault();
      show(false);
      open.focus();
    });
    kgSel.addEventListener("change", draw);
    svcSel.addEventListener("change", draw);

    // Redrawn from the real boxes whenever something else writes into them
    // (a fill, a paste, a copy, an undo), so the two views cannot disagree.
    syncColumnView = function () {
      if (!panel.hidden) draw();
    };
  }

  /** Replaced by initColumnView once the panel is on the page. */
  var syncColumnView = function () {};

  /**
   * Undo a row: put the live values back into that row's boxes. They are on
   * the row as data-live (a JSON object of input name -> value), printed by
   * the server from the live version, so the browser never has to hold a copy
   * of the live document. Without JS the button is a POST that does the same
   * on the server.
   */
  /**
   * THE TABLE AFTER AN AUTOSAVE.
   *
   * A row that has just been saved has to look saved: the orange bar on the
   * left, the Undo button in the actions column, and the collapsed row's
   * "Express: 7,674 – 10,074" summary showing what is now in the boxes. None
   * of it moved before, so a fold could be keyed, collapsed, and the table
   * would go on showing the live prices with no marks at all until the page
   * was reloaded - while the lede above it promised an orange bar and the note
   * below it promised Undo.
   *
   * It is computed HERE and not returned by the server for the same reason the
   * ladder's own marks are: the values are in the boxes on this page, the
   * comparison is against the live snapshot the server already printed on the
   * row (`data-live`), and the two arithmetics are the same one - see
   * markLadder() and View\Admin\DestinationRow::summary().
   */
  function repaintRow(form) {
    if (!form || !form.getAttribute("data-row-form")) return;

    var id = form.getAttribute("data-row-form");
    var row = null;
    qsa("tr[data-row]").forEach(function (tr) {
      if (tr.getAttribute("data-row") === id) row = tr;
    });
    if (!row) return;

    var fold = row.nextElementSibling;
    if (fold && !fold.classList.contains("grid-row")) fold = null;

    paintRowSummary(row, fold);

    var live = liveSnapshot(row);
    // No snapshot means no live counterpart: the country is new, so the server
    // already called the row changed and there is nothing to compare it with.
    if (!live) return;

    row.classList.toggle("changed", rowDiffers(row, fold, live));
    paintUndo(row);
  }

  /** Does anything in this row differ from the version the website is serving? */
  function rowDiffers(row, fold, live) {
    var fields = qsa("input, select", row);
    if (fold) fields = fields.concat(qsa("input, select", fold));

    for (var i = 0; i < fields.length; i++) {
      var f = fields[i];
      // The hidden "0" companion of the on-site checkbox shares its name and
      // holds no state of its own; the checkbox below answers for both.
      if (!f.name || f.type === "hidden") continue;
      if (!Object.prototype.hasOwnProperty.call(live, f.name)) continue;
      if (f.type === "checkbox") {
        if (!!live[f.name] !== f.checked) return true;
        continue;
      }
      var was = live[f.name] === null || live[f.name] === undefined ? "" : String(live[f.name]);
      if (was !== String(f.value).trim()) return true;
    }

    return false;
  }

  /**
   * The collapsed row's one line per service: the cheapest and the dearest
   * price in the ladder, and how many of the kilograms are priced. Same shape
   * as DestinationRow::summary(), read from the boxes rather than the document.
   */
  function paintRowSummary(row, fold) {
    var out = qsa(".grid-summary > span", row);
    if (!out.length || !fold) return;

    var ladders = qsa("[data-ladder]", fold);
    out.forEach(function (span, i) {
      var scope = ladders[i];
      if (!scope) return;
      var name = scope.getAttribute("data-service-name") || "";
      span.textContent = name + ": " + ladderSummary(scope);
    });
  }

  function ladderSummary(scope) {
    var boxes = boxesOf(scope);
    var filled = [];
    boxes.forEach(function (box) {
      var text = String(box.value).trim();
      if (text === "") return;
      var n = Number(text);
      if (isFinite(n)) filled.push(n);
    });

    if (!filled.length) return "-";

    function fmt(n) {
      return PRICING ? PRICING.fmtNum(n) : String(n);
    }

    var text = fmt(filled[0]) + " – " + fmt(filled[filled.length - 1]);

    return filled.length < boxes.length ? text + " (" + filled.length + "/" + boxes.length + ")" : text;
  }

  /**
   * Undo appears with the orange bar and goes away with it. The server prints
   * the button on a changed row; this puts it there - and takes it away again
   * when the row is typed back to the live prices - so the note's promise that
   * "Undo restores the live version of that row" holds during the session the
   * work is done in, not only after a reload.
   */
  function paintUndo(row) {
    var cell = row.querySelector("td.row-actions");
    if (!cell || readOnly) return;

    var existing = cell.querySelector("[data-undo-row]");
    var wanted = row.classList.contains("changed");

    if (!wanted) {
      if (existing) cell.removeChild(existing);
      return;
    }
    if (existing) return;

    var form = row.getAttribute("data-form") || "";
    var formEl = form ? document.getElementById(form) : null;
    var action = formEl ? formEl.getAttribute("data-undo-action") : "";
    // No action means no honest button: the server's URL is the one thing the
    // browser cannot invent, and a submit to the wrong place would save the
    // row instead of undoing it.
    if (!action) return;

    var nameBox = row.querySelector("input.name");
    var name = nameBox ? String(nameBox.value).trim() : "";

    var btn = document.createElement("button");
    btn.className = "btn small outline";
    btn.type = "submit";
    btn.setAttribute("form", form);
    btn.setAttribute("formaction", action);
    btn.name = "undo";
    btn.value = row.getAttribute("data-row") || "";
    btn.setAttribute("data-undo-row", "");
    btn.setAttribute("aria-label", "Undo changes to " + (name === "" ? "this country" : name));
    btn.textContent = "Undo";
    bindRowUndo(btn);

    // Before Remove, which is the last control in the column, so the order is
    // the one the server renders: Save, Undo, Remove.
    var remove = cell.querySelector("[data-confirm]");
    if (remove) cell.insertBefore(btn, remove);
    else cell.appendChild(btn);
  }

  function initRowUndo(root) {
    qsa("[data-undo-row]", root).forEach(bindRowUndo);
  }

  function bindRowUndo(btn) {
    btn.addEventListener("click", function (e) {
      var row = btn.closest("tr[data-name]");
      if (!row) return;
      var raw = row.getAttribute("data-live");
      if (!raw) return; // no live snapshot: let the plain POST handle it
      var live;
      try {
        live = JSON.parse(raw);
      } catch (err) {
        return;
      }
      e.preventDefault();
      qsa("input, select", row).forEach(function (f) {
        if (!f.name) return;
        if (!Object.prototype.hasOwnProperty.call(live, f.name)) return;
        if (f.type === "checkbox") f.checked = !!live[f.name];
        else if (f.type !== "hidden") f.value = live[f.name] === null || live[f.name] === undefined ? "" : String(live[f.name]);
      });
      var fold = row.nextElementSibling;
      if (fold && fold.classList.contains("grid-row")) {
        qsa("input", fold).forEach(function (f) {
          if (f.name && Object.prototype.hasOwnProperty.call(live, f.name)) f.value = live[f.name] === null ? "" : String(live[f.name]);
        });
        // The ladder's marks describe the boxes, so they have to be redrawn
        // with them: an undone row must stop saying three prices were edited.
        qsa("[data-ladder]", fold).forEach(markLadder);
      }
      row.classList.remove("changed");
      paintRowSummary(row, fold && fold.classList.contains("grid-row") ? fold : null);
      syncColumnView();
      // The row's boxes belong to the row's own form through `form=`, so the
      // autosave is told about that form and not about the <tr>, which has
      // no `.form` and would be ignored.
      touched(row);
      var fold2 = row.nextElementSibling;
      if (fold2 && fold2.classList.contains("grid-row")) touched(fold2);
      toast("Row restored to the live version");
    });
  }

  /**
   * Remove: the first click arms the button, the second submits it. Exactly
   * RatesTable's confirmDel, and the reason it is not a window.confirm() is
   * that the owner does this in a long table and a modal loses his place.
   * Only one button is armed at a time.
   */
  function initConfirm(root) {
    var armed = null;
    function disarm() {
      if (!armed) return;
      armed.textContent = armed.getAttribute("data-confirm-idle") || "Remove";
      armed.classList.remove("is-armed");
      armed = null;
    }
    qsa("[data-confirm]", root).forEach(function (btn) {
      btn.setAttribute("data-confirm-idle", btn.textContent.trim());
      btn.addEventListener("click", function (e) {
        if (armed === btn) return; // second click: let the submit through
        e.preventDefault();
        disarm();
        armed = btn;
        btn.textContent = btn.getAttribute("data-confirm") || "Confirm remove";
        btn.classList.add("is-armed");
      });
    });
    // Anything else the owner does disarms it, so a stray Confirm is never
    // left sitting under the pointer.
    document.addEventListener("click", function (e) {
      if (armed && e.target !== armed && !(e.target.closest && e.target.closest("[data-confirm]") === armed)) disarm();
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape") disarm();
    });
  }

  /* ================================================================== *
   * 3. The review / publish box - reviewLanding.ts
   * ================================================================== */

  /**
   * How far to scroll so a box lands in the band the owner can see: below the
   * sticky section tabs, above the fixed publish bar. Straight port of
   * scrollToClear - scrollIntoView({block:"nearest"}) used to align the review
   * card with the viewport's bottom edge, i.e. its buttons under the bar.
   */
  function scrollToClear(box, floor, ceiling, gap) {
    ceiling = ceiling || 0;
    gap = gap === undefined ? 16 : gap;
    var top = ceiling + gap;
    var bottom = floor - gap;
    if (box.top >= top && box.bottom <= bottom) return 0;
    var fits = box.bottom - box.top <= bottom - top;
    if (fits && box.bottom > bottom) return box.bottom - bottom;
    return box.top - top;
  }

  /**
   * The server renders the review card (it owns the diff and the validation),
   * so all that is left here is landing it where it can be read and acted on.
   * Runs once, on load, when the card is present.
   */
  function initReviewLanding() {
    var box = document.getElementById("reviewbox");
    if (!box || !box.firstElementChild) return;
    var bar = document.querySelector(".pubbar");
    var tabs = document.querySelector(".subnav.sections");
    var delta = scrollToClear(
      box.getBoundingClientRect(),
      bar ? bar.getBoundingClientRect().top : window.innerHeight,
      tabs ? tabs.offsetHeight : 0,
    );
    if (!delta) return;
    var reduced = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    window.scrollBy({ top: delta, behavior: reduced ? "auto" : "smooth" });
  }

  /**
   * Publish is a plain submit. The only thing JS adds is flushing a pending
   * autosave first, so the version that gets published is the one on screen,
   * and stopping a second click from posting twice.
   */
  function initSubmitGuards(root) {
    qsa("[data-once]", root).forEach(function (btn) {
      var form = btn.form;
      if (!form) return;
      form.addEventListener("submit", function (e) {
        // A submit the autosave has held back (section 1) is coming again in a
        // moment. Disabling the button now would disable it for the submit
        // that actually happens, which is how "Publish" becomes a dead button.
        if (e.defaultPrevented) return;
        setTimeout(function () {
          btn.disabled = true;
          if (btn.hasAttribute("data-busy-text")) btn.textContent = btn.getAttribute("data-busy-text");
        }, 0);
      });
    });
  }

  /* ================================================================== *
   * 4. The hold dialog - HoldBar.tsx
   * ================================================================== */

  /**
   * "Hold prices" opens a box with the message customers will see; the box is
   * in the page already (server-rendered, hidden) so with JS off the button is
   * a link to the same page with the box open. The counter under the textarea
   * is the only JS-only part.
   */
  function initHold(root) {
    var box = root.querySelector("[data-hold-dialog]");
    var openBtn = root.querySelector("[data-hold-open]");
    var wrap = root.querySelector(".hold-bar");
    // The button now sits inside a block that states where prices stand; the
    // dialog asks the same question, so the two must not be on screen at once.
    var idle = root.querySelector("[data-hold-idle]");
    if (box && openBtn) {
      openBtn.addEventListener("click", function (e) {
        e.preventDefault();
        box.hidden = false;
        openBtn.hidden = true;
        if (idle) idle.hidden = true;
        if (wrap) wrap.classList.add("open");
        var ta = box.querySelector("textarea");
        if (ta) ta.focus();
      });
      qsa("[data-hold-cancel]", box).forEach(function (b) {
        b.addEventListener("click", function (e) {
          e.preventDefault();
          box.hidden = true;
          openBtn.hidden = false;
          if (idle) idle.hidden = false;
          if (wrap) wrap.classList.remove("open");
          openBtn.focus();
        });
      });
    }
    var ta2 = root.querySelector("[data-hold-count]");
    if (ta2) {
      var out = document.getElementById(ta2.getAttribute("data-hold-count"));
      var max = Number(ta2.getAttribute("maxlength")) || 0;
      var paint = function () {
        if (out) out.textContent = ta2.value.trim().length + "/" + max;
      };
      ta2.addEventListener("input", paint);
      paint();
    }
  }

  /* ================================================================== *
   * 5. Bulk adjust - BulkAdjust.tsx
   *
   * (There is no import mapper. The owner removed the Excel rate-sheet
   * feature from the product on 2026-09-27 - no upload, no mapper, no
   * emailed sheets - so the panel it decorated, and the file input and the
   * submit-on-change hooks it needed, are gone. Rates CSV *export* stays,
   * and that is section 6 below.)
   * ================================================================== */

  /**
   * The arithmetic runs on the server (it touches every rate in the
   * document). The two refusals are re-stated here so the owner is not made
   * to wait for a round trip to be told he ticked nothing; the server checks
   * both again and is the one that decides.
   */
  function initBulk(root) {
    var form = root.querySelector("[data-bulk-form]");
    if (!form) return;
    var msg = root.querySelector("[data-bulk-msg]");
    function say(kind, text) {
      if (!msg) return;
      msg.className = "notice " + kind;
      msg.textContent = text;
      msg.hidden = false;
    }
    form.addEventListener("submit", function (e) {
      var services = qsa('input[name="services[]"]:checked', form).length;
      var fields = qsa('input[name="fields[]"]:checked', form).length;
      if (!services || !fields) {
        e.preventDefault();
        return say("warn", "Choose at least one service and one price field.");
      }
      var valEl = form.querySelector('[name="value"]');
      var v = Number(valEl && valEl.value);
      if (!isFinite(v) || v === 0) {
        e.preventDefault();
        return say("warn", "Enter the change value, for example 5 for +5% or -200 for 200 less.");
      }
    });
  }

  /* ================================================================== *
   * 6. Copy / download the rates as CSV - RatesTable's two buttons
   * ================================================================== */

  /**
   * The CSV is built by the server (it is the draft, and neutralising a
   * leading = + - @ against spreadsheet formula injection belongs next to the
   * data). Download is a plain link. Copy needs the text in the browser, so
   * the server puts it in a <pre hidden> beside the button - not a
   * <script type="text/plain">, because a country name is free text and the
   * one string a script element cannot carry is its own closing tag. Without
   * JS only the download link is shown.
   */
  function initCsvCopy(root) {
    qsa("[data-copy-from]", root).forEach(function (btn) {
      var src = document.getElementById(btn.getAttribute("data-copy-from"));
      if (!src) {
        btn.hidden = true;
        return;
      }
      btn.hidden = false;
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        var text = src.textContent || "";
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(
            function () {
              toast(btn.getAttribute("data-copy-ok") || "Copied to the clipboard");
            },
            function () {
              toast("Copy failed");
            },
          );
        } else toast("Copy is not supported here");
      });
    });
  }

  /* ================================================================== *
   * 7. Tables and filters elsewhere (inbox, shipments)
   * ================================================================== */

  /**
   * A list filter over any table or list whose items carry data-search: the
   * same as the rates filter, generalised. The no-JS path is again the GET
   * form the input already sits in.
   */
  function initListFilters(root) {
    qsa("[data-list-filter]", root).forEach(function (input) {
      var list = document.getElementById(input.getAttribute("data-list-filter"));
      if (!list) return;
      if (input.form) input.form.addEventListener("submit", function (e) {
        e.preventDefault();
      });
      input.addEventListener("input", function () {
        var k = nameKey(input.value);
        qsa("[data-search]", list).forEach(function (item) {
          item.hidden = !!k && nameKey(item.getAttribute("data-search")).indexOf(k) < 0;
        });
      });
    });
  }

  /** Column sort links are plain links (?sort=…); nothing to enhance. */

  /* ================================================================== *
   * boot
   * ================================================================== */

  function boot() {
    var app = document.querySelector(".admin-app");
    if (!app) return;

    // The theme toggle in the admin chrome is rendered `hidden` because it only
    // works with a script (ui.js writes the cookie and repaints it). ui.js has
    // already wired it by now; reveal it so it is not a dead control. Admin-only
    // and harmless where there is nothing to show.
    qsa("[data-theme-toggle]", app).forEach(function (b) {
      b.hidden = false;
    });

    qsa(".tablewrap").forEach(initOverflow);
    qsa("[data-filter-table]").forEach(initFilter);
    initPriceFolds(app);
    initWheelGuard(app);
    initColumnView(app);
    initLadders(app);
    initQuickFill(app);
    initRowUndo(app);
    initConfirm(app);
    initHold(app);
    initBulk(app);
    initCsvCopy(app);
    initListFilters(app);
    initSubmitGuards(app);
    initReviewLanding();

    // Every autosaving form on the page: one per country, plus Settings and
    // Website pages. They share one queue - see section 1.
    initSaving(app);

    // One-shot messages the server wants shown as a toast rather than a
    // notice (e.g. "Version 12 is live" after a publish redirect).
    var flash = document.querySelector("[data-toast]");
    if (flash) {
      toast(flash.getAttribute("data-toast"));
      flash.parentNode.removeChild(flash);
    }
  }

  window.Speedat = window.Speedat || {};
  window.Speedat.scrollToClear = scrollToClear;
  window.Speedat.hiddenEdges = hiddenEdges;
  window.Speedat.nameKey = nameKey;

  if (UI.ready) UI.ready(boot);
  else if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
