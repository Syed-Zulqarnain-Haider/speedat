/*!
 * marketing.js - the marketers' editor: the live search preview, the character
 * counts and the unsaved-changes warning. Everything it does is extra; the form
 * publishes without it.
 */
(function () {
  "use strict";

  /* On a phone the row of pages scrolls: start with the open one in view. */
  var here = document.querySelector(".mk-tabs [aria-current]");
  if (here) here.parentNode.scrollLeft = here.offsetLeft - here.parentNode.offsetLeft - 16;

  var form = document.getElementById("marketing-form");
  if (!form) return;

  var serp = form.querySelector("[data-serp]");
  var state = form.querySelector("[data-state]");
  var publish = form.querySelector("[data-publish]");
  var resting = state ? state.textContent : "";
  var leaving = false;

  /* A refused publish comes back with the text still in the boxes: it is
     unsaved from the moment the page opens. */
  var refused = !!document.querySelector(".notice.err");

  function dirty() {
    if (refused) return true;
    return Array.prototype.some.call(form.elements, function (el) {
      return el.name && el.name.indexOf("content[") === 0 && el.value !== el.defaultValue;
    });
  }

  function show() {
    var on = dirty();
    if (!state || state.classList.contains("dirty") === on) return;
    state.classList.toggle("dirty", on);
    state.textContent = on ? "You have unpublished changes." : resting;
  }

  /* textContent only: what is typed here is shown as words, never as markup. */
  function preview(el) {
    var role = el.getAttribute("data-serp-field");
    if (!role) return;

    var text = el.value.replace(/\s+/g, " ").trim();
    var best = Number(el.getAttribute("data-best")) || 0;

    if (serp) {
      var target = serp.querySelector("[data-serp-" + role + "]");
      if (target) target.textContent = text || serp.getAttribute("data-auto-" + role) || "";
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

  form.addEventListener("input", function (event) {
    preview(event.target);
    show();
  });

  form.addEventListener("submit", function (event) {
    if (leaving) {
      event.preventDefault(); /* a second press while the first is on its way */
      return;
    }
    leaving = true;
    if (publish) {
      publish.setAttribute("aria-disabled", "true");
      publish.textContent = "Publishing…";
    }
  });

  window.addEventListener("beforeunload", function (event) {
    if (!leaving && dirty()) event.preventDefault();
  });

  /* Back to a page the browser kept: the button must work again. */
  window.addEventListener("pageshow", function (event) {
    if (!event.persisted) return;
    leaving = false;
    if (publish) {
      publish.removeAttribute("aria-disabled");
      publish.textContent = "Publish this page";
    }
  });

  Array.prototype.forEach.call(form.querySelectorAll("[data-serp-field]"), preview);
  show();
})();
