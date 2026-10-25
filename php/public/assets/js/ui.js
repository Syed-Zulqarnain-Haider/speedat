/*!
 * ui.js - the small behaviours the React shell provides today, as plain
 * ES2019. No framework, no modules, no build step: one <script defer> tag.
 *
 * What it replaces, component for component:
 *   src/components/site/ThemeToggle.tsx  -> the sun/moon button
 *   src/components/calculator/Toast.tsx  -> Speedat.toast()
 *   src/components/admin/AdminNav.tsx    -> keeping the current tab in view
 *   src/components/admin/AdminEditor.tsx -> the smooth in-page section jumps
 *
 * Everything here is an ENHANCEMENT. With JavaScript off the site is whole:
 * PHP prints data-theme on <html> from the cookie (so the theme is right
 * before a byte of script runs and there is no flash), the nav is five plain
 * links, <details> folds natively, and nothing that matters is behind a
 * toast. Hence no "loading" states and no elements that only exist once this
 * file runs.
 *
 * Deliberately NOT here:
 *   - a mobile nav drawer. There is none today and inventing one would not
 *     "look exactly like the site". On a phone the customer nav is the five
 *     links in the footer (site.css hides the header's copy below 1024px),
 *     and the admin's is a horizontally scrolling row (.anav-top). The only
 *     real behaviour either needs is scrolling the current item into view,
 *     which is below.
 *   - anything for the FAQ page. /faq is a <dl class="faq-list"> with every
 *     answer already open - brief v3 §3, "nothing to tap to read, nothing
 *     that folds". The two places that DO fold (details.how in the quote,
 *     details.fold in the admin) are native <details> and need no script;
 *     the one gap native <details> leaves is a link to a fragment inside a
 *     closed one, which is handled below.
 */
(function () {
  "use strict";

  /** The colour behind the address bar on phones, kept in step with the page (ThemeToggle.tsx BAR). */
  var BAR = { light: "#FFFFFF", dark: "#0B1424" };

  var root = document.documentElement;

  /* ------------------------------------------------------------------ *
   * Toast - Toast.tsx / useToast()
   * ------------------------------------------------------------------ */

  var toastEl = null;
  var toastTimer = 0;

  /**
   * A short confirmation that clears itself after 2400ms, exactly as
   * useToast does. One element is reused: a second message replaces the
   * first rather than stacking, which is what the React version does too
   * (one piece of state).
   */
  function toast(message) {
    if (!message) return;
    if (!toastEl) {
      toastEl = document.createElement("div");
      toastEl.className = "toast";
      toastEl.setAttribute("role", "status");
    }
    toastEl.textContent = String(message);
    if (!toastEl.parentNode) document.body.appendChild(toastEl);
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      toastTimer = 0;
      if (toastEl && toastEl.parentNode) toastEl.parentNode.removeChild(toastEl);
    }, 2400);
  }

  /* ------------------------------------------------------------------ *
   * Theme toggle - ThemeToggle.tsx
   * ------------------------------------------------------------------ */

  /**
   * The two glyphs, as ThemeToggle renders them: moon while light is on
   * (tap for dark), sun while dark is on (tap for light). Kept as markup
   * rather than two <img> tags because the button's icon is stroked in
   * currentColor - an <img> would always draw black. These are the same
   * paths as assets/img/icon-sun.svg and icon-moon.svg.
   */
  var GLYPH = {
    moon:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11z"></path></svg>',
    sun:
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">' +
      '<circle cx="12" cy="12" r="4"></circle>' +
      '<path d="M12 2.5V5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3l1.8 1.8M16.9 16.9l1.8 1.8M5.3 18.7l1.8-1.8M16.9 7.1l1.8-1.8"></path></svg>',
  };

  function currentTheme() {
    return root.getAttribute("data-theme") === "dark" ? "dark" : "light";
  }

  /** Paint the button for the theme that is on now. The label names what a TAP
   *  DOES, so it changes with the state (the play/pause pattern); no
   *  aria-pressed, which would contradict a changing label. */
  function paintToggle(btn, theme) {
    var label = theme === "dark" ? "Light mode" : "Dark mode";
    btn.setAttribute("aria-label", label);
    btn.setAttribute("title", label);
    btn.innerHTML = theme === "dark" ? GLYPH.sun : GLYPH.moon;
  }

  function setTheme(next) {
    root.setAttribute("data-theme", next);
    // A year, SameSite=Lax, Secure only on https - the same cookie PHP reads
    // on the next request, so the next page paints in the chosen theme with
    // no flash. No localStorage, no matchMedia: the OS preference never decides.
    document.cookie =
      "theme=" + next + "; Path=/; Max-Age=31536000; SameSite=Lax" + (location.protocol === "https:" ? "; Secure" : "");
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute("content", BAR[next]);
    var buttons = document.querySelectorAll(".btn.theme, [data-theme-toggle]");
    for (var i = 0; i < buttons.length; i++) paintToggle(buttons[i], next);
  }

  function initThemeToggle() {
    var buttons = document.querySelectorAll(".btn.theme, [data-theme-toggle]");
    if (!buttons.length) return;
    var theme = currentTheme();
    for (var i = 0; i < buttons.length; i++) {
      var btn = buttons[i];
      // The server may render the button empty (it cannot know which glyph
      // without duplicating the cookie logic in a view); paint it either way,
      // so the markup and the state can never disagree.
      paintToggle(btn, theme);
      btn.addEventListener("click", function () {
        setTheme(currentTheme() === "dark" ? "light" : "dark");
      });
    }
  }

  /* ------------------------------------------------------------------ *
   * Nav - AdminNav.tsx (the scrolling row) and AdminEditor's section jumps
   * ------------------------------------------------------------------ */

  /**
   * .anav-top scrolls sideways on a phone and the current page can start off
   * screen. aria-current="page" is printed by PHP, so all that is missing is
   * bringing it into view - without scrolling the PAGE, which
   * scrollIntoView() would do. Hence scrollLeft arithmetic.
   */
  function initScrollNav() {
    var rows = document.querySelectorAll(".anav-top");
    for (var i = 0; i < rows.length; i++) {
      var row = rows[i];
      var cur = row.querySelector('[aria-current="page"]');
      if (!cur) continue;
      var want = cur.offsetLeft - (row.clientWidth - cur.offsetWidth) / 2;
      row.scrollLeft = Math.max(0, want);
    }
  }

  /**
   * In-page links to a section scroll smoothly instead of jumping, the way
   * AdminEditor's .subnav.sections and the dashboard tiles do. Delegated, so
   * links the admin adds later are covered. Honoured only for same-page
   * fragments, and never when the reader asked for less motion.
   */
  function initAnchors() {
    document.addEventListener("click", function (ev) {
      if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
      var a = ev.target && ev.target.closest ? ev.target.closest('a[href^="#"]') : null;
      if (!a) return;
      var id = a.getAttribute("href").slice(1);
      if (!id) return;
      var target = document.getElementById(id);
      if (!target) return;
      ev.preventDefault();
      revealInDetails(target);
      var reduced = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      target.scrollIntoView({ behavior: reduced ? "auto" : "smooth", block: "start" });
      // Keep the URL and the keyboard in step with the eye: scrollIntoView
      // moves neither on its own.
      if (history.replaceState) history.replaceState(null, "", "#" + id);
      if (!target.hasAttribute("tabindex")) target.setAttribute("tabindex", "-1");
      target.focus({ preventScroll: true });
    });
  }

  /* ------------------------------------------------------------------ *
   * <details>
   * ------------------------------------------------------------------ */

  /** Open every <details> the element is inside, so a link to a fragment buried
   *  in a closed fold actually shows it. Native <details> does not do this. */
  function revealInDetails(el) {
    var d = el.closest ? el.closest("details") : null;
    while (d) {
      d.open = true;
      d = d.parentNode && d.parentNode.closest ? d.parentNode.closest("details") : null;
    }
  }

  function initDetails() {
    if (location.hash.length > 1) {
      var target = document.getElementById(location.hash.slice(1));
      if (target) {
        revealInDetails(target);
        // The browser already tried to scroll to it while the fold was shut.
        target.scrollIntoView({ block: "start" });
      }
    }
    window.addEventListener("hashchange", function () {
      if (location.hash.length <= 1) return;
      var t = document.getElementById(location.hash.slice(1));
      if (t) revealInDetails(t);
    });
  }

  /* ------------------------------------------------------------------ *
   * Shared helpers other scripts on the page use
   * ------------------------------------------------------------------ */

  /** Escape for insertion as HTML text. Everything the calculator and the
   *  admin print goes through this or through textContent - never raw. */
  function esc(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  /** Read a JSON blob PHP embedded as <script type="application/json" id="…">.
   *  Returns null when the tag is missing or unparseable - every caller must
   *  cope with that by doing nothing, so a data bug never blanks a page. */
  function readBlob(id) {
    var tag = document.getElementById(id);
    if (!tag) return null;
    try {
      return JSON.parse(tag.textContent || "null");
    } catch (e) {
      return null;
    }
  }

  function ready(fn) {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", fn);
    else fn();
  }

  window.Speedat = window.Speedat || {};
  window.Speedat.toast = toast;
  window.Speedat.esc = esc;
  window.Speedat.readBlob = readBlob;
  window.Speedat.ready = ready;
  window.Speedat.revealInDetails = revealInDetails;
  window.Speedat.setTheme = setTheme;

  ready(function () {
    initThemeToggle();
    initScrollNav();
    initAnchors();
    initDetails();
  });
})();
