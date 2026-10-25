/*!
 * promo.js - reveal and dismiss the owner's optional offer popup, as plain
 * ES2019. No framework, no modules, no build step: one <script defer> tag,
 * loaded by site/partials/promo only when the popup is actually on the page.
 *
 * The card is printed HIDDEN by the server; this reveals it after a short beat
 * so the price paints first and is never covered, and remembers a dismissal per
 * viewer so a returning visitor is not shown the same offer twice. Everything
 * here is an ENHANCEMENT: with JavaScript off the card simply stays hidden, and
 * a promo is not part of pricing a parcel.
 *
 * localStorage is wrapped in try/catch at every touch - a private window, a
 * blocked third-party store or a full quota must never throw and take the page
 * down with it. When storage cannot be read the popup still shows; when it
 * cannot be written the popup still closes, just without being remembered.
 */
(function () {
  "use strict";

  var el = document.querySelector("[data-promo]");
  if (!el) {
    return;
  }

  var KEY = "speedat_promo_dismissed";
  var sig = el.getAttribute("data-promo-sig") || "";

  function readDismissed() {
    try {
      return window.localStorage.getItem(KEY);
    } catch (e) {
      return null;
    }
  }

  function remember(value) {
    try {
      window.localStorage.setItem(KEY, value);
    } catch (e) {
      /* private mode, blocked store or full quota - closing still works */
    }
  }

  // Already dismissed THIS exact offer? Leave it hidden. A changed offer has a
  // new signature, so it comes back on its own.
  if (sig !== "" && readDismissed() === sig) {
    return;
  }

  function dismiss() {
    el.setAttribute("hidden", "");
    remember(sig);
  }

  var closeButton = el.querySelector("[data-promo-close]");
  if (closeButton) {
    closeButton.addEventListener("click", dismiss);
  }

  // Escape closes it too, for anyone on a keyboard once it has appeared.
  document.addEventListener("keydown", function (event) {
    if ((event.key === "Escape" || event.key === "Esc") && !el.hasAttribute("hidden")) {
      dismiss();
    }
  });

  // Show after a beat, so the price is on screen first.
  window.setTimeout(function () {
    el.removeAttribute("hidden");
  }, 1200);
})();
