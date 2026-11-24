/*!
 * marketing.js - the marketers' editor: the live search preview, the character
 * counts, the unsaved-changes warning, and asking whether the session is still
 * there before a publish leaves the page. Everything it does is extra; the form
 * publishes without it.
 */
(function () {
  "use strict";

  /* On a phone the row of pages scrolls: start with the open one in view. */
  var here = document.querySelector(".mk-tabs [aria-current]");
  if (here) here.parentNode.scrollLeft = here.offsetLeft - here.parentNode.offsetLeft - 16;

  /* A press that cannot be taken back asks once more (Turn off two-factor). */
  Array.prototype.forEach.call(document.querySelectorAll(".mk-body [data-confirm]"), function (btn) {
    var idle = btn.textContent.trim();
    var armed = false;
    btn.addEventListener("click", function (event) {
      if (armed) return;
      event.preventDefault();
      armed = true;
      btn.textContent = btn.getAttribute("data-confirm");
    });
    btn.addEventListener("blur", function () {
      armed = false;
      btn.textContent = idle;
    });
  });

  var form = document.getElementById("marketing-form");
  if (!form) return;

  var serp = form.querySelector("[data-serp]");
  var state = form.querySelector("[data-state]");
  var publish = form.querySelector("[data-publish]");
  var resting = state ? state.textContent : "";
  var label = publish ? publish.textContent : "";
  var quiet = false; /* this page is being left on purpose */
  var sending = false; /* a publish is on its way */
  var timer = null;

  /* A refused publish comes back with the text still in the boxes: it is
     unsaved from the moment the page opens. */
  var refused = !!document.querySelector(".notice.err");

  function dirty() {
    if (refused) return true;
    return Array.prototype.some.call(form.elements, function (el) {
      return el.name && el.name.indexOf("content[") === 0 && el.value !== el.defaultValue;
    });
  }

  function say(text, strong) {
    if (!state) return;
    state.classList.toggle("dirty", !!strong);
    if (state.textContent !== text) state.textContent = text;
  }

  function show() {
    if (dirty()) say("You have unpublished changes.", true);
    else say(resting, false);
  }

  function squish(text) {
    return text.replace(/\s+/g, " ").trim();
  }

  /* What the site prints for a blank description: the first line of the words
     it comes from, cut on a word at 160. */
  function fallback(role) {
    var auto = serp.getAttribute("data-auto-" + role) || "";
    var source = role === "desc" && form.querySelector("[data-serp-source]");
    if (!source || source.value === source.defaultValue) return auto;

    var first = squish(source.value.split("\n").filter(function (l) { return l.trim(); })[0] || "");
    if (first.length <= 160) return first || auto;
    var cut = first.slice(0, 160);
    return cut.slice(0, cut.lastIndexOf(" ") > 0 ? cut.lastIndexOf(" ") : 160);
  }

  /* textContent only: what is typed here is shown as words, never as markup. */
  function preview(el) {
    var role = el.getAttribute("data-serp-field");
    if (!role) return;

    var text = squish(el.value);
    var best = Number(el.getAttribute("data-best")) || 0;

    if (serp) {
      var target = serp.querySelector("[data-serp-" + role + "]");
      if (target) target.textContent = text || fallback(role);
    }

    var hint = el.parentNode.querySelector("[data-count]");
    if (hint && best) {
      var over = text.length > best;
      hint.classList.toggle("over", over);
      hint.textContent = !text.length
        ? "Best under " + best + " characters."
        : over
          ? text.length + " characters: Google usually cuts it at about " + best + "."
          : text.length + " of " + best + " characters.";
    }
  }

  function previews() {
    Array.prototype.forEach.call(form.querySelectorAll("[data-serp-field]"), preview);
  }

  function ready() {
    clearTimeout(timer);
    sending = false;
    quiet = false;
    if (publish) {
      publish.removeAttribute("aria-disabled");
      publish.textContent = label;
    }
  }

  function send() {
    quiet = true;
    /* HTMLFormElement.submit() fires no submit event, so this is the post. */
    form.submit();
    /* A post that was stopped, or never answers, leaves this page standing:
       warn about leaving again, and let the button work again. */
    setTimeout(function () { quiet = false; }, 1500);
    timer = setTimeout(function () {
      ready();
      say("Taking a long time. Check Recent text changes before publishing again.", true);
    }, 20000);
  }

  form.addEventListener("input", function (event) {
    preview(event.target);
    if (event.target.hasAttribute("data-serp-source")) previews();
    if (!sending) show();
  });

  form.addEventListener("submit", function (event) {
    event.preventDefault();
    if (sending) return; /* a second press while the first is on its way */

    sending = true;
    if (publish) {
      publish.setAttribute("aria-disabled", "true");
      publish.textContent = "Publishing…";
    }

    var url = form.getAttribute("data-session");
    if (!url || !window.fetch) return send();

    /* Ask first. A publish posted after the session ended would come back as
       the sign-in page, with everything typed here gone. */
    fetch(url, { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } })
      .then(function (r) {
        if (r.redirected || r.status === 401 || r.status === 403) return null;
        return r.ok ? r.json() : {};
      })
      .then(function (answer) {
        if (answer === null) {
          ready();
          say("You were signed out. Sign in again in another tab, then press Publish: your text is still here.", true);
          return;
        }
        var token = form.querySelector('input[name="_token"]');
        if (token && answer.token) token.value = answer.token;
        send();
      })
      .catch(function () {
        ready();
        say("Could not reach the website. Check your connection and press Publish again: your text is still here.", true);
      });
  });

  window.addEventListener("beforeunload", function (event) {
    if (!quiet && dirty()) event.preventDefault();
  });

  /* Back to a page the browser kept: the button must work again. */
  window.addEventListener("pageshow", function (event) {
    if (event.persisted) ready();
  });

  previews();
  show();
})();
