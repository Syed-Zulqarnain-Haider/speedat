/*!
 * calculator.js - the browser behaviour of src/components/calculator/Calculator.tsx
 * (plus the hold panel, src/components/site/HoldPanel.tsx) as plain ES2019.
 *
 * WHAT IT IS A PORT OF
 *   Calculator.tsx            the quick price and the detailed quote
 *   calculator/pieces.ts      the piece rows and the units toggle
 *   calculator/Toast.tsx      -> Speedat.toast() in ui.js
 *   lib/pricing/format.ts     fmtMoney, fmtDay, fmtRange, quoteIdFor, inputDate…
 *   lib/pricing/dates.ts      estimateDelivery and its day/holiday parsing
 *   lib/pricing/quote.ts      piecesText, weightSentence, quoteText, waLink…
 *   lib/site/copy.ts          fromPrice (the "from PKR …" on a country tile)
 *   site/HoldPanel.tsx        the by-hand form shown while prices are on hold
 *
 *   The PRICING ITSELF is not ported: every number comes from
 *   window.SpeedatPricing, phase 1's port of lib/pricing/engine.ts. This file
 *   never adds, rounds or steps a price of its own - if a figure here is wrong
 *   the engine is wrong, and the engine is the one thing with golden vectors.
 *
 * THE RULES IT KEEPS (design brief v3 §4 - all four are load-bearing)
 *   1. Nothing scrolls by itself. There is not one scrollIntoView or hash
 *      write in this file. The visitor's thumb decides where the page is.
 *      focus() appears in exactly three places, all with preventScroll so
 *      the page cannot move (audit B6, 2026-10-25): handing focus back to the
 *      service control a re-render replaced (keepFocus), moving it into the
 *      view a "Box size · many pieces" / "Back" tap just opened (applyMode),
 *      and following the arrow keys across the country tiles (onTileKey).
 *      Each answers a key or a tap the visitor made; without them a keyboard
 *      or screen-reader user was dropped on <body> by their own action.
 *   2. Tap feedback under 80ms. Every click handler mutates the DOM
 *      synchronously - no rAF, no timers, no debounce, no fetch on the path
 *      between the tap and the paint. The price is printed, never counted up:
 *      there is no animation code here at all, by design. The one timer in
 *      the calculator (shownLog) runs AFTER the paint and only decides whether
 *      to log a price that stayed on screen; it never touches the DOM.
 *   3. Nothing shifts when the price lands. .result carries data-state and
 *      data-n so calculator.css can reserve the room the live rows, lines and
 *      bar will take; this file only ever changes those attributes and fills
 *      the slot. It never inserts a block above the price.
 *   4. Plain-words readout states, and never "PKR 0" or a dash. The figure
 *      exists only in state "live"; every other state is a sentence that says
 *      what to do or what will happen instead ("Tap a country", "Over 25 kg?
 *      We price it on WhatsApp"), and the green button always has somewhere
 *      to go.
 *
 * NO-SCRIPT
 *   The calculator is interactive by nature; in the Next app with JavaScript
 *   off you see the tiles and can tap nothing. Here, whatever PHP printed
 *   inside the mount stays until this file has a document to render - so put
 *   the plain WhatsApp way out there (see php/docs/assets.md). This file
 *   replaces the mount's children in one go, so a half-built calculator is
 *   never on screen.
 */
(function () {
  "use strict";

  // Re-read at boot: the load order of two <script defer> tags is fixed, but
  // this way the file does not care which of them the view prints first.
  var P = window.SpeedatPricing || null;
  var UI = window.Speedat || {};

  /* ================================================================== *
   * tiny DOM helpers
   * ================================================================== */

  /** h("span.foo", {attr: v}, [children | "text"]) -> Element. */
  function h(spec, attrs, kids) {
    var bits = String(spec).split(".");
    var el = document.createElement(bits[0] || "div");
    for (var i = 1; i < bits.length; i++) el.classList.add(bits[i]);
    if (attrs)
      for (var k in attrs) {
        if (!Object.prototype.hasOwnProperty.call(attrs, k)) continue;
        var v = attrs[k];
        if (v === null || v === false || v === undefined) continue;
        if (k === "text") el.textContent = String(v);
        else if (k === "html") el.innerHTML = v;
        else if (k.slice(0, 2) === "on") el.addEventListener(k.slice(2), v);
        else el.setAttribute(k, v === true ? "" : String(v));
      }
    if (kids) add(el, kids);
    return el;
  }

  function add(el, kids) {
    if (kids === null || kids === undefined || kids === false) return el;
    if (Array.isArray(kids)) {
      for (var i = 0; i < kids.length; i++) add(el, kids[i]);
      return el;
    }
    el.appendChild(typeof kids === "string" || typeof kids === "number" ? document.createTextNode(String(kids)) : kids);
    return el;
  }

  function clear(el) {
    while (el.firstChild) el.removeChild(el.firstChild);
    return el;
  }

  /** UI.wa, inline so stroke="currentColor" follows the button it sits in. */
  function waIcon() {
    var s =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
      '<path d="M20 13.5a2.5 2.5 0 0 1-2.5 2.5H10l-5 4V5.5A2.5 2.5 0 0 1 7.5 3h10A2.5 2.5 0 0 1 20 5.5z"></path>' +
      '<path d="M9 6.5h1.8l.9 2.2-1.1.7a4.9 4.9 0 0 0 2.2 2.2l.7-1.1 2.2.9v1.8a.9.9 0 0 1-.9.9A7.2 7.2 0 0 1 8.1 7.4a.9.9 0 0 1 .9-.9z"></path></svg>';
    var wrap = document.createElement("span");
    wrap.innerHTML = s;
    return wrap.firstChild;
  }

  /* ================================================================== *
   * per-tab memory - lib/client/session.ts
   * ================================================================== */

  var mem = {};

  function readSession(key) {
    if (Object.prototype.hasOwnProperty.call(mem, key)) return mem[key];
    var v = null;
    try {
      v = sessionStorage.getItem(key);
    } catch (e) {
      /* private mode, disabled storage: memory only */
    }
    mem[key] = v;
    return v;
  }

  function writeSession(key, value) {
    mem[key] = value;
    try {
      if (value === null) sessionStorage.removeItem(key);
      else sessionStorage.setItem(key, value);
    } catch (e) {
      /* memory only */
    }
  }

  /* ================================================================== *
   * lib/pricing/format.ts
   * ================================================================== */

  var NUM = new Intl.NumberFormat("en-US");

  function fmtNum(n) {
    return NUM.format(Math.round(n));
  }

  function fmtMoney(n, currency) {
    return currency + " " + fmtNum(n);
  }

  function fmtDay(dt) {
    return dt.toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" });
  }

  function fmtRange(est) {
    return est.from.getTime() === est.to.getTime() ? fmtDay(est.from) : fmtDay(est.from) + " – " + fmtDay(est.to);
  }

  /** 15 -> "3 pm", 0 -> "12 am". */
  function fmtHour(hr) {
    if (hr === null || hr === undefined) return "";
    var n = Number(hr);
    if (!isFinite(n)) return "";
    var ap = n >= 12 ? "pm" : "am";
    var x = n % 12;
    return (x === 0 ? 12 : x) + " " + ap;
  }

  function pad2(n) {
    return String(n).length < 2 ? "0" + n : String(n);
  }

  /** Local calendar date -> "YYYY-MM-DD" for <input type="date">. */
  function inputDate(dt) {
    return dt.getFullYear() + "-" + pad2(dt.getMonth() + 1) + "-" + pad2(dt.getDate());
  }

  /** "YYYY-MM-DD" -> local Date, or null when malformed. */
  function localDateFromInput(v) {
    var m = String(v === null || v === undefined ? "" : v).match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
  }

  /**
   * "Now" on the office's clock, not the visitor's.
   *
   * Pickup, the 3 pm cutoff, the delivery window and the date on a quote id
   * are all Lahore facts. A relative in London booking at 11:00 local is
   * booking at 16:00 in Lahore - after the cutoff - and the site used to
   * promise them today's pickup anyway, in the WhatsApp text and in the
   * stored quote (audit B6, 2026-10-25). Pakistan is UTC+5 with no daylight
   * saving, so the offset is a constant: shift the instant by five hours and
   * rebuild a LOCAL Date from its UTC fields. Every getter the date code uses
   * (getHours, getDate, getDay…) then reads Karachi wall-clock time, whatever
   * zone the device is in.
   */
  var KARACHI_OFFSET_MS = 5 * 60 * 60 * 1000;

  function karachiNow() {
    var k = new Date(Date.now() + KARACHI_OFFSET_MS);
    return new Date(k.getUTCFullYear(), k.getUTCMonth(), k.getUTCDate(), k.getUTCHours(), k.getUTCMinutes(), k.getUTCSeconds(), k.getUTCMilliseconds());
  }

  /** SP-260920-K7QX: date-stamped, short enough to read out on the phone. */
  function quoteId(now, rand) {
    now = now || new Date();
    var r = rand ? rand() : Math.random();
    var tail = r.toString(36).slice(2, 6).toUpperCase();
    while (tail.length < 4) tail += "0";
    return "SP-" + String(now.getFullYear()).slice(2) + pad2(now.getMonth() + 1) + pad2(now.getDate()) + "-" + tail;
  }

  /**
   * Deterministic id for a seed (per-tab salt + destination + weight +
   * service), so one combination keeps its id while it is on screen while
   * two tabs differ. FNV-1a, same constants as format.ts.
   */
  function quoteIdFor(seed, now) {
    var hash = 0x811c9dc5;
    for (var i = 0; i < seed.length; i++) {
      hash ^= seed.charCodeAt(i);
      hash = Math.imul(hash, 0x01000193) >>> 0;
    }
    var tail = (hash % 1679616).toString(36).toUpperCase();
    while (tail.length < 4) tail = "0" + tail;
    return (
      quoteId(now || new Date(), function () {
        return 0;
      }).slice(0, -4) + tail
    );
  }

  /* ================================================================== *
   * lib/pricing/dates.ts
   * ================================================================== */

  var DAY_NAMES = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"];

  /** "3–5", "3 to 5 days", "4" -> [min, max]; nothing numeric -> null. */
  function parseDaysRange(str) {
    var m = String(str === null || str === undefined ? "" : str).match(/\d+/g);
    if (!m) return null;
    var a = Number(m[0]);
    var b = m[1] !== undefined ? Number(m[1]) : a;
    return [Math.min(a, b), Math.max(a, b)];
  }

  /** "3–5" from "3-5" or "3 to 5 days"; "" when the rate carries no number. */
  function daysText(raw) {
    var r = parseDaysRange(raw);
    if (!r) return "";
    return r[0] === r[1] ? String(r[0]) : r[0] + "–" + r[1];
  }

  /** Working days as JS day indexes (0 = Sunday); Mon–Fri when nothing parses. */
  function workingSet(settings) {
    var set = {};
    var any = false;
    var txt = String(settings.workingDays === null || settings.workingDays === undefined ? "" : settings.workingDays).toLowerCase();
    // Expand ranges such as "mon-sat" or "monday to friday".
    txt = txt.replace(
      /(sun|mon|tue|wed|thu|fri|sat)[a-z]*\s*(?:-|–|\u2014|to|through|till|until)\s*(sun|mon|tue|wed|thu|fri|sat)[a-z]*/g,
      function (_m, a, b) {
        var i = DAY_NAMES.indexOf(a);
        var j = DAY_NAMES.indexOf(b);
        var out = [];
        var guard = 0;
        while (guard++ < 8) {
          out.push(DAY_NAMES[i]);
          if (i === j) break;
          i = (i + 1) % 7;
        }
        return out.join(" ");
      },
    );
    var toks = txt.split(/[^a-z]+/);
    for (var t = 0; t < toks.length; t++) {
      var i2 = DAY_NAMES.indexOf(toks[t].slice(0, 3));
      if (i2 >= 0) {
        set[i2] = true;
        any = true;
      }
    }
    if (!any) [1, 2, 3, 4, 5].forEach(function (d) {
      set[d] = true;
    });
    return set;
  }

  /** Holidays as "YYYY-MM-DD" keys; lines that do not start with a date are ignored. */
  function holidaySet(settings) {
    var out = {};
    var ls = String(settings.holidays === null || settings.holidays === undefined ? "" : settings.holidays).split(/\r?\n/);
    for (var i = 0; i < ls.length; i++) {
      var m = ls[i].trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (m) out[m[1] + "-" + m[2] + "-" + m[3]] = true;
    }
    return out;
  }

  function dayKey(d) {
    return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate());
  }

  function addDays(dt, n) {
    var x = new Date(dt.getFullYear(), dt.getMonth(), dt.getDate());
    x.setDate(x.getDate() + n);
    return x;
  }

  /** Port of estimateDelivery: pickup day, the window, and whether pickup moved. */
  function estimateDelivery(daysStr, settings, shipDate, now) {
    var range = parseDaysRange(daysStr);
    if (!range) return null;
    var ws = workingSet(settings);
    var hol = holidaySet({ holidays: settings.holidays || "" });
    var working = function (d) {
      return !!ws[d.getDay()] && !hol[dayKey(d)];
    };
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var start = shipDate ? new Date(shipDate.getFullYear(), shipDate.getMonth(), shipDate.getDate()) : today;
    if (start < today) start = today;
    var moved = false;
    var cutoff = settings.cutoffHour;
    if (start.getTime() === today.getTime() && cutoff !== null && cutoff !== undefined && isFinite(cutoff) && now.getHours() >= cutoff) {
      start = addDays(start, 1);
      moved = true;
    }
    var guard = 0;
    while (!working(start) && guard++ < 60) {
      start = addDays(start, 1);
      moved = true;
    }
    var addWorking = function (d, n) {
      var x = d;
      var c = 0;
      var g = 0;
      while (c < n && g++ < 400) {
        x = addDays(x, 1);
        if (working(x)) c++;
      }
      return x;
    };
    return { pickup: start, from: addWorking(start, range[0]), to: addWorking(start, range[1]), moved: moved, range: range };
  }

  /* ================================================================== *
   * lib/pricing/quote.ts
   * ================================================================== */

  var LB = 0.45359237;
  var IN = 2.54;

  function f2(v) {
    return String(Math.round(v * 100) / 100);
  }

  /** "2 × 1 kg (30×20×10 cm); 1 × 0.5 kg" in the customer's units. */
  function piecesText(w, units) {
    var imp = units === "imperial";
    return w.lines
      .map(function (l) {
        var kg = imp ? f2(l.kg / LB) + " lb" : f2(l.kg) + " kg";
        var dims = "";
        if (l.volG) {
          dims = imp
            ? " (" + [l.L, l.W, l.H].map(function (x) { return f2(x / IN); }).join("×") + " in)"
            : " (" + [l.L, l.W, l.H].map(f2).join("×") + " cm)";
        }
        return l.qty + " × " + kg + dims;
      })
      .join("; ");
  }

  /*
   * weightSentence() and quoteText() are NOT ported here any more (audit B6,
   * 2026-10-25). Both are server-twinned sentences - the WhatsApp message and
   * the lead row the office reads must word one quote the same way - and
   * pricing.js has always exported both, so adoptFromEngine() replaced the
   * local copies on every page load. The copies never ran, and had drifted
   * from the pair they were copies of - the "Courier" line and the zero
   * add-on rule were fixed here and not there, and the mixed-piece wording
   * was fixed there (B5) and not here. A twin that never runs is a second
   * answer waiting to be believed. boot() now refuses
   * to build the calculator without them, exactly as it does without
   * priceAll, so there is one wording and it is the one the server shares.
   */

  /** "Pickup and service charges" -> "pickup and service charges"; an acronym keeps its case. */
  function midSentence(s) {
    return String(s).replace(/^[A-Z](?=[a-z])/, function (c) {
      return c.toLowerCase();
    });
  }

  /** The one number the customer booked: shipping plus every add-on that was on. */
  function bookedTotal(shipping, addons) {
    return addons.reduce(function (t, a) {
      return t + a.amount;
    }, shipping);
  }

  function waLink(whatsapp, text) {
    return "https://wa.me/" + whatsapp + "?text=" + encodeURIComponent(text);
  }

  /** Message for shipments above the cargo threshold. */
  function cargoText(companyName, from, dest, w, units) {
    return (
      "Hi " + companyName + ", I need a cargo rate." +
      (from ? "\nFrom: " + from : "") +
      "\nTo: " + dest +
      "\nPieces: " + piecesText(w, units) +
      "\nChargeable: about " + P.gToKg(w.billableG) + " kg"
    );
  }

  /**
   * Message for a weight NO service prices - the owner's skippable weights
   * (2026-09-28): a blank box is not a price, so that weight is not offered and
   * the visitor is sent here instead of being quoted a kilogram up.
   *
   * Shaped exactly like cargoText() above: same greeting, same field order,
   * destination before weight. It is the same conversation with a different
   * reason, so the owner reads the same kind of message in WhatsApp whichever
   * way the site ran out of price. `weightLine` is already a whole line (or two)
   * because the two tiers know different things - the quick tier has one tapped
   * kilogram, the detailed tier has pieces and a chargeable weight.
   *
   * It is NOT in pricing.js: nothing on the server writes this message, so it
   * has no twin to stay in step with and no vector to pin it.
   */
  function noRateText(companyName, from, dest, weightLine) {
    return (
      "Hi " + companyName + ", I need a rate." +
      (from ? "\nFrom: " + from : "") +
      "\nTo: " + dest +
      "\n" + weightLine
    );
  }

  /* ================================================================== *
   * calculator/pieces.ts - what was typed, in the unit it was typed in
   *
   * Switching units never rewrites a typed value: the price is always
   * computed from the text itself in its own unit, and the other system only
   * ever sees a two-decimal rendering. Converting in place used to give back
   * 40.01 × 30 × 19.99 cm, and 4 kg shown as 8.82 lb priced as 4.0007 kg and
   * billed as 5.
   * ================================================================== */

  // Rebuilt by adoptFromEngine() if pricing.js carries its own LB/IN.
  var FACTOR = { kg: LB, L: IN, W: IN, H: IN };

  function entry(v, u) {
    return { v: v === undefined ? "" : v, u: u || "metric" };
  }

  function newRow(key, kg) {
    return { key: key, kg: entry(kg || ""), qty: "1", L: entry(), W: entry(), H: entry() };
  }

  /** The typed number, or null when the field is empty or not a number. */
  function num(s) {
    var v = P.toNumLoose(s);
    return v === null || v === undefined || isNaN(v) ? null : v;
  }

  function round2(v) {
    return String(Math.round(v * 100) / 100);
  }

  /** The entry in kg or cm, whatever unit it was typed in. */
  function metricValue(e, field) {
    var v = num(e.v);
    return v !== null && v > 0 && e.u === "imperial" ? v * FACTOR[field] : v;
  }

  /** What the field SHOWS in the current system: the typed text, or a 2dp conversion. */
  function shown(e, units, field) {
    if (e.u === units) return e.v;
    var v = num(e.v);
    if (v === null || !(v > 0)) return e.v;
    var k = FACTOR[field];
    return round2(units === "imperial" ? v / k : v * k);
  }

  /**
   * The engine's rows: metric numbers.
   *
   * The sizes used to be dropped when the shipment was declared as documents.
   * There is no such declaration since 2026-09-27 (owner removed the document
   * rate), so a size the customer typed is always a size the engine sees - which
   * is what makes a large, light box cost what it costs.
   */
  function toRows(pieces) {
    return pieces.map(function (pc) {
      return {
        kg: metricValue(pc.kg, "kg"),
        qty: num(pc.qty) === null ? 1 : num(pc.qty),
        L: metricValue(pc.L, "L"),
        W: metricValue(pc.W, "W"),
        H: metricValue(pc.H, "H"),
      };
    });
  }

  /* ================================================================== *
   * ONE implementation wins: phase 1's pricing.js
   *
   * Everything above this line is a faithful port of format.ts, dates.ts and
   * quote.ts, kept because the brief only promises that window.SpeedatPricing
   * carries "the functions of engine.ts". The pricing.js that phase 1 actually
   * shipped is wider than that - it exports the date, format and message
   * helpers too - and when two copies of quoteText exist, the one that also
   * runs on the server is the one that must win, or a WhatsApp message and the
   * lead row logged beside it could word the same quote differently.
   *
   * So: adopt every helper pricing.js exports, keep the port for the rest.
   * If a future pricing.js narrows back to engine.ts, this file still works -
   * except for quoteText and weightSentence, which are taken from pricing.js
   * or not at all (audit B6, 2026-10-25; see the note above midSentence()).
   * Parity between the two is proven by the PHP suite instead: engine-parity
   * runs the same vectors through pricing.js and App\Domain\Pricing\Engine.
   * ================================================================== */

  // Bound only from pricing.js - see the note above midSentence().
  var weightSentence = null;
  var quoteText = null;

  function adoptFromEngine() {
    if (!P) return;
    weightSentence = typeof P.weightSentence === "function" ? P.weightSentence : null;
    quoteText = typeof P.quoteText === "function" ? P.quoteText : null;
    var take = function (name, set) {
      if (typeof P[name] === "function") set(P[name]);
    };
    take("fmtNum", function (f) { fmtNum = f; });
    take("fmtMoney", function (f) { fmtMoney = f; });
    take("fmtDay", function (f) { fmtDay = f; });
    take("fmtRange", function (f) { fmtRange = f; });
    take("fmtHour", function (f) { fmtHour = f; });
    take("inputDate", function (f) { inputDate = f; });
    take("localDateFromInput", function (f) { localDateFromInput = f; });
    take("quoteId", function (f) { quoteId = f; });
    take("quoteIdFor", function (f) { quoteIdFor = f; });
    take("parseDaysRange", function (f) { parseDaysRange = f; });
    take("estimateDelivery", function (f) { estimateDelivery = f; });
    take("piecesText", function (f) { piecesText = f; });
    take("midSentence", function (f) { midSentence = f; });
    take("bookedTotal", function (f) { bookedTotal = f; });
    take("waLink", function (f) { waLink = f; });
    take("cargoText", function (f) { cargoText = f; });
    if (typeof P.LB === "number") LB = P.LB;
    if (typeof P.IN === "number") IN = P.IN;
    // FACTOR is built from LB/IN, so rebuild it after they may have moved.
    FACTOR = { kg: LB, L: IN, W: IN, H: IN };
  }

  /* ================================================================== *
   * flags - src/components/site/Flag.tsx
   * ================================================================== */

  var flagsBase = "/assets/flags/";
  var flagCodes = {};

  /**
   * A local flag file, or a two-letter badge when the country is not in the
   * map. The name is always printed beside it, so the image is decorative
   * (alt=""). Width and height are set, so nothing shifts as they load, and
   * the tiles' flags are the one symbol a first-time visitor recognises -
   * they are fetched at high priority, never lazily.
   */
  function flag(destId, name, size, lazy) {
    var code = flagCodes[destId] || null;
    var hgt = Math.round(size * 0.75);
    if (!code) {
      var letters = String(name).replace(/[^A-Za-z]/g, "").slice(0, 2).toUpperCase();
      var badge = h("span.flag.flag-badge", { "aria-hidden": "true", text: letters });
      badge.style.width = size + "px";
      badge.style.height = hgt + "px";
      return badge;
    }
    var img = h("img.flag", { src: flagsBase + code + ".svg", alt: "", width: size, height: hgt });
    if (lazy) img.setAttribute("loading", "lazy");
    else img.setAttribute("fetchpriority", "high");
    return img;
  }

  /* ================================================================== *
   * lib/site/copy.ts - the "from PKR …" on a country tile
   * ================================================================== */

  /**
   * Exactly the calculator's own 1 kg quick-rate call, cheapest priced
   * service, plus the add-ons that are on by default. Computed here rather
   * than handed over in the blob so a tile's figure can never disagree with
   * the figure the third tap prints. Null when the destination is not priced.
   */
  function fromPrice(site, destId, addonsOn) {
    var r = P.priceAll(site, { destId: destId, rows: [{ kg: 1, qty: 1 }] });
    if (!r.ok) return null;
    var best = null;
    for (var i = 0; i < site.services.length; i++) {
      var p = r.prices[site.services[i].id];
      if (!p) continue;
      if (!best || p.total < best.total) best = { total: p.total, days: daysText(p.days), serviceId: site.services[i].id };
    }
    if (!best) return null;
    best.total += addonsOn;
    return best;
  }

  /**
   * Does this country price ANY weight, in any service?
   *
   * The question exists because of the owner's skippable weights (2026-09-28).
   * Before them, "has a 1 kg price" and "is served at all" were the same thing,
   * so `fromPrice()` answered both and the quick view used it for both. They
   * came apart the moment a blank box stopped falling to the next one up: a
   * country priced from 2 kg has no 1 kg price and is served perfectly well,
   * and telling that visitor "No service to United Kingdom yet" is wrong in a
   * way that costs a shipment.
   *
   * Priced means exactly what the ENGINE means by priced - it calls
   * `gridPrice()` rather than reading the boxes itself, so a rule change in the
   * engine cannot leave this sentence behind. Grid mode walks the card's own
   * kilograms; slab mode prices every weight from `first` + `addl`, so having
   * both numbers is the whole test.
   */
  function servesAnyWeight(site, destId) {
    var dest = null;
    for (var i = 0; i < site.destinations.length; i++) if (site.destinations[i].id === destId) dest = site.destinations[i];
    if (!dest || dest.active === false) return false;

    var grid = P.isGrid(site.settings);
    var kgs = grid ? P.gridWeights(site.settings) : [];

    for (var s = 0; s < site.services.length; s++) {
      var rate = dest.rates ? dest.rates[site.services[s].id] : null;
      if (!rate) continue;
      if (!grid) {
        if (P.isNum(rate.first) && P.isNum(rate.addl)) return true;
        continue;
      }
      for (var k = 0; k < kgs.length; k++) {
        if (P.gridPrice(rate.grid, kgs[k], site.settings.maxKg)) return true;
      }
    }
    return false;
  }

  /* ================================================================== *
   * the calculator
   * ================================================================== */

  /** The weight buttons on the quick view, in kilograms; only those the rate card can price are shown. */
  var KG_BUTTONS = [1, 2, 3, 5, 10, 15, 20, 25];

  /**
   * How many cells the country grid has. Up to this many destinations are all
   * tiles; with more, the last cell becomes the "More countries" picker, so
   * the grid keeps the same height whatever the count.
   */
  var TILE_MAX = 8;

  function weightOptions(sets) {
    if (P.isGrid(sets)) return P.gridWeights(sets);
    var out = [];
    var k;
    for (k = 0.5; k <= 10; k += 0.5) out.push(k);
    for (k = 11; k <= 30; k += 1) out.push(k);
    var top = sets.maxKg > 0 ? Math.min(sets.maxKg, 200) : 100;
    for (k = 35; k <= top; k += 5) out.push(k);
    return out.filter(function (x) {
      return !(sets.maxKg > 0) || x <= sets.maxKg;
    });
  }

  function Calculator(mount, blob) {
    var site = blob.site;
    var sets = site.settings;
    var cur = sets.currency;
    var quotesUrl = blob.quotesUrl || "/api/quotes";
    var addonDefs = P.addonsList(sets);
    var addonsOnTotal = addonDefs.reduce(function (t, a) {
      return t + (a.on ? a.amount : 0);
    }, 0);
    // SEPARATE CHARGES (owner, 2026-10-26): owner: rates show the courier
    // rate only; the pickup charge is shown under the quote, never added.
    // "Pickup | 1000 | separate" is not in addonDefs (addonsList() leaves it
    // out), so it can never reach addonsOnTotal, a tile, a row, the bar, the
    // log or a tick box; it is only ever PRINTED, by chargesDrill() and as its
    // own line in the WhatsApp text. Guarded so a stale cached pricing.js
    // without it simply shows no drill-down rather than no calculator.
    var separate = typeof P.separateCharges === "function" ? P.separateCharges(sets) : [];
    var cities = (blob.cities || []).slice();
    var act = site.destinations.filter(function (x) {
      return x.active;
    });
    var activeIds = {};
    act.forEach(function (x) {
      activeIds[x.id] = true;
    });

    /* ---- state ---------------------------------------------------- */

    var addons = {};
    addonDefs.forEach(function (a) {
      addons[a.id] = a.on;
    });

    function remembered() {
      var raw = readSession("sp-last");
      if (!raw) return {};
      try {
        var v = JSON.parse(raw);
        return typeof v === "object" && v ? v : {};
      } catch (e) {
        return {};
      }
    }

    function destId() {
      var r = remembered();
      return r.destId && activeIds[r.destId] ? r.destId : "";
    }

    function setDestId(id) {
      var r = remembered();
      r.destId = id;
      writeSession("sp-last", JSON.stringify(r));
      quick.onDest();
      detail.onDest();
    }

    function rememberKg(kg) {
      var r = remembered();
      r.kg = kg;
      writeSession("sp-last", JSON.stringify(r));
    }

    function mode() {
      return readSession("sp-mode") === "detail" ? "detail" : "quick";
    }

    function switchMode(m) {
      writeSession("sp-mode", m);
      applyMode(true);
    }

    function selectedAddons() {
      return addonDefs.filter(function (a) {
        return !!addons[a.id];
      });
    }

    function addonsTotal() {
      return selectedAddons().reduce(function (t, a) {
        return t + a.amount;
      }, 0);
    }

    /**
     * `total` is the number the customer SAW - shipping plus the add-ons that
     * were on; the server recomputes both from the live document. keepalive so
     * the request survives the tab going to WhatsApp, and every failure is
     * swallowed: logging never blocks a booking.
     */
    function logQuote(q, addonList, extra) {
      try {
        var body = {};
        for (var k in q) if (Object.prototype.hasOwnProperty.call(q, k)) body[k] = q[k];
        body.total = bookedTotal(q.total, addonList);
        body.addons = addonList.map(function (a) {
          return a.label;
        });
        for (var e in extra) if (Object.prototype.hasOwnProperty.call(extra, e)) body[e] = extra[e];
        fetch(quotesUrl, {
          method: "POST",
          keepalive: true,
          headers: { "content-type": "application/json" },
          body: JSON.stringify(body),
        }).catch(function () {});
      } catch (err) {
        /* logging never blocks a booking */
      }
    }

    /**
     * THE "PRICE SHOWN" LOG (audit B6, 2026-10-25).
     *
     * The log used to fire only from the Book button, so every row in it was a
     * booking: 500 visitors priced and 40 booked read as "Prices shown: 40"
     * and a 100% WhatsApp share, and the rankings described bookers only.
     * QuoteRepository and the stats were always written for a log that ALSO
     * records the price being seen - this is the half that never fired.
     *
     * "Shown" means a live price that stayed put for SHOWN_MS. Tapping through
     * 1, 2, 3 kg on the way to 5 is not three quotes, so every render re-arms
     * the timer and only the price the visitor stopped on is logged. Each quote
     * id is logged as shown at most once per tab.
     *
     * The id is the one the Book tap will send. It already changes with the
     * inputs (quick: salt + destination + weight + service; detailed: a fresh
     * id per Choose, bound to the inputs' signature), and that matters: the
     * server refuses an id whose destination or service changed under it, so a
     * shown-log must never borrow an id across changed inputs or the Book POST
     * that follows it would be the one refused.
     *
     * Only the view on screen arms it - the hidden view re-renders too (an
     * add-on or a country change renders both) and must not log what nobody
     * can see. A Book tap cancels the pending shown-log; booked:true says more,
     * and QuoteRepository keeps booked once it is set, so even a shown-log that
     * already left cannot undo a booking that lands before it.
     */
    var SHOWN_MS = 1500;
    var shownLog = (function () {
      var timer = 0;
      var sent = {};
      function cancel() {
        if (timer) clearTimeout(timer);
        timer = 0;
      }
      function arm(q, addonList, extra) {
        cancel();
        if (!q || sent[q.id]) return;
        timer = setTimeout(function () {
          timer = 0;
          sent[q.id] = true;
          logQuote(q, addonList, extra);
        }, SHOWN_MS);
      }
      function booked(id) {
        cancel();
        sent[id] = true;
      }
      return { arm: arm, cancel: cancel, booked: booked };
    })();

    /**
     * Give focus back to the control a re-render replaced.
     *
     * The quick view's service radios and the detailed view's service cards
     * are rebuilt by render(), and choosing one IS a render. The focused
     * element was removed under the keyboard user's finger and focus fell to
     * <body>: the next arrow key did nothing and the screen reader lost its
     * place (audit B6, 2026-10-25). `before` is read just ahead of the clear(),
     * `pick` finds the same control in the new markup. preventScroll, so rule
     * 1 still holds: the page never moves.
     */
    function focusedIn(box) {
      var a = document.activeElement;
      return a && a !== document.body && box.contains(a) ? a : null;
    }

    function keepFocus(before, pick) {
      if (!before) return;
      var next = pick();
      if (next && next !== document.activeElement && typeof next.focus === "function") next.focus({ preventScroll: true });
    }

    /* ---- the shared destination <select> (DestOptions) ------------- */

    function fillDestOptions(sel, placeholderText) {
      clear(sel);
      var alpha = act.slice().sort(function (a, b) {
        return a.name.localeCompare(b.name);
      });
      // `selected` as well as `disabled`, the same pair dest-options.php
      // prints. Without it the browser's own rule - select the first option
      // that is NOT disabled - hands the control to the first country, so a
      // select nobody has touched reads "Australia" and a visitor who submits
      // it sends a country they never chose.
      sel.appendChild(h("option", { value: "", disabled: true, selected: true, text: placeholderText || "Choose a country" }));
      var opt = function (x) {
        return h("option", { value: x.id, text: x.name });
      };
      if (act.length <= 8) {
        alpha.forEach(function (x) {
          sel.appendChild(opt(x));
        });
        return;
      }
      // Beyond eight the admin's own order is worth keeping at the top.
      var pop = h("optgroup", { label: "Popular" });
      act.slice(0, 5).forEach(function (x) {
        pop.appendChild(opt(x));
      });
      var all = h("optgroup", { label: "All destinations" });
      alpha.forEach(function (x) {
        all.appendChild(opt(x));
      });
      sel.appendChild(pop);
      sel.appendChild(all);
    }

    /* ---- the separate charges drill-down (owner, 2026-10-26) --------
     * "At the bottom just add a drill down and there show 1000 pickup
     * charges". A native <details>, closed by default, so it is keyboard and
     * screen-reader accessible for free and its closed height is one 48px
     * summary row - which calculator.css reserves in the quick view's result
     * block (.result[data-sep]), so it lands in room that was already there.
     * The words say outright that the figure above does not include it. No
     * dash: " - " (owner, 2026-10-11). Null when there is nothing to show. */
    function chargesDrill() {
      if (!separate.length) return null;
      var ul = h("ul");
      separate.forEach(function (c) {
        ul.appendChild(h("li", { text: P.chargeName(c.label) + ": " + fmtMoney(c.amount, cur) + " - paid separately, not included in the price above." }));
      });
      var title = separate.length === 1 ? P.chargeName(separate[0].label) : "Other charges";
      return h("details.charges", null, [h("summary", { text: title }), ul]);
    }

    /* ---- the add-on checkboxes (detailed view only) ---------------- */

    function addonsBox() {
      if (!addonDefs.length) return null;
      var box = h("div.addons");
      addonDefs.forEach(function (a) {
        var cb = h("input", { type: "checkbox" });
        cb.checked = !!addons[a.id];
        cb.addEventListener("change", function () {
          addons[a.id] = cb.checked;
          quick.render();
          detail.render();
        });
        box.appendChild(add(h("label"), [cb, " +" + fmtMoney(a.amount, cur) + " " + a.label]));
      });
      return box;
    }

    /* ================================================================ *
     * QUICK RATE - three taps: country, weight, the price
     * ================================================================ */

    var quick = (function () {
      var kgv = "";
      var svc = "";
      var contents = "";
      // Quote ids are derived from a per-tab salt plus the inputs, so a
      // combination keeps its id while it is on screen.
      var salt = Math.random().toString(36).slice(2);

      var froms = {};
      // `served` is NOT "froms[id] !== null": a country priced from 2 kg up has
      // no "from PKR …" on its tile and is still a country we ship to. See
      // servesAnyWeight(). Both are computed once, from data that does not
      // change while the page is open.
      var served = {};
      site.destinations.forEach(function (d) {
        froms[d.id] = fromPrice(site, d.id, addonsOnTotal);
        served[d.id] = servesAnyWeight(site, d.id);
      });

      var more = act.length > TILE_MAX;
      var tiles = more ? act.slice(0, TILE_MAX - 1) : act;
      var priced = weightOptions(sets);
      var kgs = KG_BUTTONS.filter(function (k) {
        return priced.indexOf(k) >= 0;
      });

      var panel = h("div.panel.quick");
      var headCountry = h("div.step-head", null, [h("span.step-name", { text: "1 Country" })]);
      var ctiles = h("div.ctiles", { role: "radiogroup", "aria-label": "Country" });
      var headWeight = h("div.step-head", null, [h("span.step-name", { text: "2 Weight" })]);
      var kgRow = h("div.kgs", { role: "group", "aria-label": "Weight" });
      var result = h("div.result");
      // THE ONE LIVE REGION of the quick view (audit B6, 2026-10-25). It sits
      // OUTSIDE .result because render() clears .result, and a live region
      // that is created already holding its words is not announced by most
      // screen readers - which is what every rebuilt pricebar-sum and p.wait
      // used to be. This node is made once, empty, and only ever has its text
      // changed, at the end of render(), to the readout sentence. It is .sr,
      // so it takes no room and cannot shift anything (rule 3).
      // It goes in the panel, not the page, so in the detailed view it is
      // inside the hidden wrapper and says nothing.
      var say = h("p.sr", { role: "status" });
      var said = null;
      var tileButtons = {};
      var tileList = [];
      var kgButtons = {};
      var picker = null;
      // The guided next-step hint that sits at the top of the weight row, and the
      // exact-weight dropdown at the bottom of it (owner, 2026-09-30).
      var kgGuide = h("p.kg-guide", { "data-guide": "weight" });
      var exactSel = null;

      panel.appendChild(h("h2.sr", { text: "Get your price" }));
      // Near the top, not the end: ".panel > :last-child" drops its margin in
      // calculator.css, and that child must stay .result.
      panel.appendChild(say);
      panel.appendChild(headCountry);
      panel.appendChild(ctiles);
      panel.appendChild(headWeight);
      panel.appendChild(kgRow);
      panel.appendChild(result);

      tiles.forEach(function (d, ti) {
        var from = froms[d.id];
        // A spoken name: as a bare role="radio" the tile read out as "button" on
        // some screen readers, so the country - and its from-price when there is
        // one - is stated. The dash in a days range becomes "to" so it is not read
        // as "minus". app/Views/site/partials/calculator.php builds the same label.
        var label = d.name + (from ? ", from " + fmtMoney(from.total, cur) + (from.days ? ", " + String(from.days).replace(/[-\u2013\u2014]/g, " to ") + " days" : "") : "");
        var btn = h("button.ctile", { type: "button", role: "radio", "aria-checked": "false", "aria-label": label }, [
          flag(d.id, d.name, 32),
          h("span.ctile-name", { text: d.name }),
          from
            ? add(h("span.ctile-from"), ["from " + fmtMoney(from.total, cur), from.days ? h("span.ctile-days", { text: " · " + from.days + " days" }) : null])
            : null,
        ]);
        // Synchronous: the tile is checked and the readout repainted in this
        // same task, so the feedback is a repaint away (<80ms), never a frame
        // behind a timer.
        btn.addEventListener("click", function () {
          setDestId(d.id);
        });
        // Position stated outright: the "More countries" <select> shares the
        // grid with the tiles (it is the grid's last cell, by design), and
        // left to count the group's children a screen reader could fold it
        // into "radio 3 of N".
        btn.setAttribute("aria-posinset", String(ti + 1));
        btn.setAttribute("aria-setsize", String(tiles.length));
        btn.addEventListener("keydown", onTileKey);
        tileButtons[d.id] = btn;
        tileList.push(btn);
        ctiles.appendChild(btn);
      });

      /**
       * A radio group behaves like one (audit B6, 2026-10-25). The tiles said
       * role=radiogroup/radio and then behaved like eight buttons: every tile
       * its own Tab stop and the arrow keys doing nothing. Now the group is ONE
       * Tab stop (the checked tile, or the first when none is - roving
       * tabindex, kept by render()) and the arrows move through it, wrapping.
       * Moving checks the tile, which is what arrowing a native radio group
       * does, so the price follows the keyboard exactly as it follows a tap.
       * The More countries select stays its own Tab stop after the group.
       */
      function onTileKey(ev) {
        if (ev.altKey || ev.ctrlKey || ev.metaKey) return;
        var k = ev.key;
        var i = tileList.indexOf(ev.currentTarget);
        if (i < 0) return;
        var n = tileList.length;
        var to = -1;
        if (k === "ArrowRight" || k === "ArrowDown") to = (i + 1) % n;
        else if (k === "ArrowLeft" || k === "ArrowUp") to = (i - 1 + n) % n;
        else if (k === "Home") to = 0;
        else if (k === "End") to = n - 1;
        // Space and Enter need nothing: the tile is a <button>, so both
        // already click it, which checks it.
        if (to < 0) return;
        ev.preventDefault();
        tileList[to].click();
        tileList[to].focus({ preventScroll: true });
      }

      if (more) {
        picker = h("select.ctile-more", { "aria-label": "More countries" });
        fillDestOptions(picker, "More countries");
        picker.addEventListener("change", function () {
          setDestId(picker.value);
        });
        ctiles.appendChild(picker);
      }

      // First child of the row: the eye meets the next-step hint before the
      // buttons. render() sets its words and its state class.
      kgRow.appendChild(kgGuide);

      kgs.forEach(function (k) {
        var btn = h("button.kg", { type: "button", "aria-pressed": "false" }, [h("strong", { text: String(k) }), h("span", { text: "kg" })]);
        btn.addEventListener("click", function () {
          onKg(String(k));
        });
        kgButtons[String(k)] = btn;
        kgRow.appendChild(btn);
      });
      if (sets.maxKg > 0) {
        var btnMore = h("button.kg.more", { type: "button", "aria-pressed": "false", text: "Over " + sets.maxKg + " kg" });
        btnMore.addEventListener("click", function () {
          onKg("more");
        });
        kgButtons.more = btnMore;
        kgRow.appendChild(btnMore);
      }
      // The way out to the detailed view (box sizes, many boxes, an exact
      // weight). It used to offer documents too; the document choice went with
      // the document rate (owner, 2026-09-27). The label MUST match the one
      // app/Views/site/partials/calculator.php paints, or the row reflows the
      // moment this script takes the mount over.
      var btnOther = h("button.kg.other", { type: "button", text: "Box size · many pieces" });
      btnOther.addEventListener("click", function () {
        onKg("exact");
      });
      kgRow.appendChild(btnOther);

      // EXACT WEIGHT (owner, 2026-09-30). The preset buttons are only the round
      // numbers the rate card prices; a 4 kg or 7 kg parcel had no button. This
      // dropdown offers EVERY weight the card prices - `priced` is the same list
      // the buttons are filtered from (weightOptions), so grid cards list whole
      // kilograms and slab cards list their half-kilogram steps, with no figure
      // hard-coded here. Choosing one runs onKg(), the SAME path a button takes,
      // so there is one pricing route and the dropdown and the buttons can never
      // disagree. The placeholder is disabled, so the control cannot be left on a
      // half-made choice, and render() keeps it in step with the tapped weight.
      exactSel = h("select.kg-select", { "aria-label": "Choose an exact weight in kilograms" });
      exactSel.appendChild(h("option", { value: "", disabled: true, selected: true, text: "Exact weight…" }));
      priced.forEach(function (k) {
        exactSel.appendChild(h("option", { value: String(k), text: String(k) + " kg" }));
      });
      exactSel.addEventListener("change", function () {
        if (exactSel.value) onKg(exactSel.value);
      });
      kgRow.appendChild(h("div.kg-exact", null, [add(h("label.kg-exact-lab"), [h("span.kg-exact-text", { text: "Or pick your exact weight" }), exactSel])]));

      /*
       * The tapped weight is remembered for the tab, and "Box size · many
       * pieces" no longer throws it away (audit B6, 2026-10-25). A customer who
       * tapped 5 kg and then went to the detailed view found "Weight per piece"
       * empty, and on Back the quick view said "Now tap a weight" - the same
       * question twice. Now the detailed view's first piece is seeded from it
       * (detail.seedKg) and the quick view keeps it for the way back.
       */
      function onKg(v) {
        if (v === "exact") {
          render();
          switchMode("detail");
          return;
        }
        kgv = v;
        if (v !== "more" && isFinite(Number(v)) && Number(v) > 0) rememberKg(v);
        render();
      }

      /** On the way back from the detailed view: its first piece's weight, if it is one we offer here. */
      function seedKg(v) {
        // "Over N kg" is a choice too, and a stale remembered weight must not
        // quietly replace it.
        if (kgv === "more") return;
        if (v && priced.indexOf(Number(v)) >= 0) kgv = String(Number(v));
      }

      function etaQuick(p) {
        return p.days ? p.days + " days" : "";
      }

      // Pre-select the Normal (economy) service by default, so the lower price
      // shows first and the rates read as low (owner, 2026-10-05). If this
      // weight has no Normal price, fall back to the cheapest service that IS
      // priced here, so a destination that only runs Express still shows one.
      function defaultServiceId(available, res) {
        if (!available.length) return "";
        for (var i = 0; i < site.services.length; i++) {
          var sv = site.services[i];
          if (available.indexOf(sv.id) >= 0 && /normal|econom|standard|saver|regular|ground|surface/i.test(sv.name + " " + sv.id)) return sv.id;
        }
        var best = available[0];
        var bestTotal = res && res.ok && res.prices[best] ? res.prices[best].total : Infinity;
        for (var j = 1; j < available.length; j++) {
          var pr = res && res.ok ? res.prices[available[j]] : null;
          if (pr && pr.total < bestTotal) {
            bestTotal = pr.total;
            best = available[j];
          }
        }
        return best;
      }

      function render() {
        var dest = null;
        for (var i = 0; i < site.destinations.length; i++) {
          if (site.destinations[i].id === destId() && site.destinations[i].active) dest = site.destinations[i];
        }
        var res = dest && kgv && kgv !== "more" && kgv !== "exact" ? P.priceAll(site, { destId: dest.id, rows: [{ kg: Number(kgv), qty: 1 }] }) : null;
        var available = res && res.ok
          ? site.services.filter(function (s) {
              return res.prices[s.id];
            }).map(function (s) {
              return s.id;
            })
          : [];
        var chosen = available.indexOf(svc) >= 0 ? svc : defaultServiceId(available, res);
        var p = res && res.ok && chosen ? res.prices[chosen] : null;
        var addTot = addonsTotal();
        var sum = p ? p.total + addTot : 0;
        var picked = selectedAddons();

        /* --- the tiles and the two step heads ---------------------- */
        for (var id in tileButtons) {
          if (Object.prototype.hasOwnProperty.call(tileButtons, id)) tileButtons[id].setAttribute("aria-checked", id === destId() ? "true" : "false");
        }
        // Roving tabindex: the checked tile is the group's one Tab stop, or
        // the first tile while none is (a country chosen from the select
        // checks no tile). See onTileKey.
        var tabTile = tileButtons[destId()] || tileList[0] || null;
        for (var ti2 = 0; ti2 < tileList.length; ti2++) tileList[ti2].setAttribute("tabindex", tileList[ti2] === tabTile ? "0" : "-1");
        if (picker) {
          var offTile = destId() && !tileButtons[destId()] ? destId() : "";
          picker.value = offTile;
          if (offTile) picker.setAttribute("data-chosen", "true");
          else picker.removeAttribute("data-chosen");
        }
        stepDone(headCountry, dest ? [flag(dest.id, dest.name, 24), dest.name] : null);
        for (var kk in kgButtons) {
          if (Object.prototype.hasOwnProperty.call(kgButtons, kk)) kgButtons[kk].setAttribute("aria-pressed", kk === kgv ? "true" : "false");
        }
        stepDone(headWeight, kgv && kgv !== "more" ? [kgv + " kg"] : null);
        // Keep the exact-weight dropdown showing the current weight. A tapped
        // preset is one of its options too, so the two controls always agree;
        // "more"/"exact"/no weight falls back to the placeholder.
        if (exactSel) {
          if (kgv && kgv !== "more" && kgv !== "exact") exactSel.value = kgv;
          else exactSel.selectedIndex = 0;
        }

        /* --- the one sentence, in plain words ---------------------- *
         * Live, a second short line says when the parcel arrives (the only
         * place the dates are printed). The live note is "Express · 5 kg ·
         * Canada"; its country is a separate span so the CSS can drop it on
         * a phone instead of ellipsizing the whole line. */
        var note;
        var noteTo = "";
        var when = "";
        var state;
        if (!destId()) {
          state = "country";
          note = "Tap a country";
        } else if (!kgv || kgv === "exact") {
          state = "weight";
          note = "Now tap a weight";
        } else if (kgv === "more" || !(res && res.ok)) {
          state = "cargo";
          note = sets.maxKg > 0 ? "Over " + sets.maxKg + " kg? We price it on WhatsApp" : "We price this on WhatsApp";
        } else if (!available.length) {
          state = "none";
          // THE OWNER'S SKIPPABLE WEIGHTS (2026-09-28). The country IS served -
          // it is priced at other weights, which is why a "from PKR …" is
          // printed beside it - this one kilogram simply has no price in any
          // service, and a blank box is no longer charged a kilogram up. So the
          // sentence names the weight, says the rate is not available, and the
          // green button below carries the ask. No dash, no "PKR 0": a weight
          // that is not offered is not offered, not offered at zero.
          note = served[destId()]
            ? "No rate for " + kgv + " kg to " + (dest ? dest.name : "") + " - ask on WhatsApp"
            : "No service to " + (dest ? dest.name : "") + " yet. Ask us on WhatsApp";
        } else {
          state = "live";
          var sv = null;
          for (var s2 = 0; s2 < site.services.length; s2++) if (site.services[s2].id === chosen) sv = site.services[s2];
          var est = sets.showEta && p && p.days ? estimateDelivery(p.days, sets, null, karachiNow()) : null;
          note = (sv ? sv.name : "") + " · " + kgv + " kg";
          noteTo = dest ? dest.name : "";
          when = est ? "Arrives " + fmtRange(est) : "";
        }

        /* --- the green button always has somewhere to go ----------- */
        var bookHref = "https://wa.me/" + site.company.whatsapp;
        var bookLabel = "Ask on WhatsApp";
        var quote = null;
        if (kgv === "more" && dest) {
          // "Over N kg" is all the visitor has said, so the message says exactly
          // that: no piece list (there are no pieces) and no "about N kg".
          bookHref = waLink(site.company.whatsapp, "Hi " + site.company.name + ", I need a cargo rate.\nTo: " + dest.name + "\nWeight: over " + sets.maxKg + " kg");
          bookLabel = "Ask for a cargo rate";
        } else if (state === "none" && dest) {
          // The country and the weight both travel: the owner opens WhatsApp
          // already knowing which box is blank, which is the only way a
          // skippable weight is actionable from the message alone.
          bookHref = waLink(site.company.whatsapp, noRateText(site.company.name, "", dest.name, "Weight: " + kgv + " kg"));
          bookLabel = "Ask for a rate";
        } else if (p && res && res.ok && dest) {
          var svx = null;
          for (var s3 = 0; s3 < site.services.length; s3++) if (site.services[s3].id === chosen) svx = site.services[s3];
          var est2 = sets.showEta && p.days ? estimateDelivery(p.days, sets, null, karachiNow()) : null;
          quote = {
            id: quoteIdFor(salt + "|" + dest.id + "|" + kgv + "|" + chosen, karachiNow()),
            from: "",
            dest: dest.name,
            destId: dest.id,
            service: svx ? svx.name : "",
            serviceId: chosen,
            days: p.days,
            eta: est2 ? fmtRange(est2) : "",
            pickup: est2 ? fmtDay(est2.pickup) : "",
            pieces: 1,
            piecesText: "1 × " + kgv + " kg",
            billableG: res.weights.billableG,
            chargeG: res.weights.chargeG,
            total: p.total,
            version: site.version,
          };
          bookHref = waLink(site.company.whatsapp, quoteText(quote, { companyName: site.company.name, currency: cur, addons: picked, contents: contents, separate: separate }));
          bookLabel = "Book on WhatsApp";
        }

        var waiting = state === "country" || state === "weight";

        /* --- guide the eye to the one next thing (owner, 2026-09-30) ---
         * Three stages, driven off the state that is already worked out above:
         *   country  no country yet - the weight section reads as waiting and
         *            the hint points back up to the tiles.
         *   weight   a country is chosen - the weight section lights up and the
         *            hint says to pick a weight.
         *   chosen   a weight is chosen - the answer below (a price, or the ask)
         *            lights up instead.
         * These classes only add emphasis; they must not change any box's size,
         * or picking a weight would shift the page. */
        var stage = state === "country" ? "country" : state === "weight" ? "weight" : "chosen";
        headWeight.classList.toggle("awaiting", stage === "country");
        headWeight.classList.toggle("is-next", stage === "weight");
        // Step 1 lights up until a country is chosen, so a first-time visitor
        // sees where to start (owner, 2026-10-05). Same emphasis-only classes
        // the weight head uses; the country head's sibling is .ctiles, not
        // .kgs, so the weight buttons never glow from this.
        headCountry.classList.toggle("is-next", stage === "country");
        if (stage === "country") {
          kgGuide.textContent = "First, pick a country above";
          kgGuide.className = "kg-guide awaiting";
        } else if (stage === "weight") {
          kgGuide.textContent = "Now pick your parcel weight";
          kgGuide.className = "kg-guide is-next";
        } else {
          kgGuide.textContent = "Weight set - see below";
          kgGuide.className = "kg-guide done";
        }

        /* --- the result block ------------------------------------- *
         * Always in the page and always the same height: the CSS reserves the
         * room the rows, lines and bar take once live (per tier and per how
         * many services the document has, hence data-n), so the third tap
         * paints into a slot that was already there and nothing below moves. */
        // Read before the clear: was the keyboard on a service radio?
        var focusWas = focusedIn(result);
        var focusSvc = focusWas && focusWas.name === "q-svc" ? focusWas.value : "";
        clear(result);
        result.setAttribute("data-state", state);
        result.setAttribute("data-n", String(Math.min(site.services.length, 4)));
        // Room for the closed drill-down, in EVERY state, exactly as the PHP
        // first paint sets it (calculator.php), so the block keeps one height.
        if (separate.length) result.setAttribute("data-sep", "1");
        else result.removeAttribute("data-sep");
        result.classList.toggle("is-next", stage === "chosen");

        if (state === "live" && res && res.ok) {
          var fs = h("fieldset.opts", null, [h("legend.sr", { text: "Service" })]);
          var inner = h("div");
          site.services.forEach(function (svy) {
            var sp = res.prices[svy.id];
            if (!sp) return;
            var radio = h("input", { type: "radio", name: "q-svc", value: svy.id });
            radio.checked = chosen === svy.id;
            radio.addEventListener("change", function () {
              svc = svy.id;
              render();
            });
            inner.appendChild(
              add(h("label.opt"), [
                radio,
                h("span.opt-name", { text: svy.name }),
                h("span.opt-eta", { text: etaQuick(sp) }),
                // The row's figure IS the visitor's figure: the add-ons that
                // are on sit inside it, exactly as they do inside the bar.
                h("span.opt-price", { text: fmtMoney(sp.total + addTot, cur) }),
              ]),
            );
          });
          fs.appendChild(inner);
          result.appendChild(fs);

          var lines = h("p.lines");
          if (when) lines.appendChild(h("span.twhen", { text: when }));
          // The split the owner asked for (2026-10-05): the courier price and
          // the delivery charge on their own line, so the total in the bar
          // below reads as courier + delivery and nothing is hidden. Each
          // add-on that is on and costs something prints as "label amount"; a
          // zero add-on is never shown (no "+ PKR 0"), and with no add-ons at
          // all there is no split line - just the total.
          if (addTot > 0 && p) {
            var splitBits = ["Courier " + fmtMoney(p.total, cur)];
            picked.forEach(function (a) {
              if (a.amount > 0) splitBits.push(a.label + " " + fmtMoney(a.amount, cur));
            });
            lines.appendChild(h("span.tline", { text: splitBits.join("  +  ") }));
          }
          result.appendChild(lines);

          // "What is inside your parcel?" - optional, shown once there is a
          // price, and carried into the WhatsApp message so the owner books
          // faster (owner, 2026-10-05). Typing it never re-renders: it patches
          // the book link's href in place (see below), so the caret never
          // jumps. The value is re-seeded from `contents` on every render, so
          // it survives a service change.
          var contentsInp = h("input", { type: "text", maxlength: "120", placeholder: "e.g. documents, clothes, electronics" });
          contentsInp.value = contents;
          contentsInp.addEventListener("input", function () {
            contents = contentsInp.value;
            if (quote) book.setAttribute("href", waLink(site.company.whatsapp, quoteText(quote, { companyName: site.company.name, currency: cur, addons: picked, contents: contents, separate: separate })));
          });
          result.appendChild(
            add(h("label.field.contents"), [add(h("span"), ["What is inside your parcel? ", h("span.hint", { text: "(optional)" })]), contentsInp]),
          );
        }

        if (!waiting) {
          // The price bar: the figure and the one green button. On phones it
          // sticks to the bottom of the screen while the calculator is in
          // view; it is the block's LAST child, so it comes to rest at the
          // panel's end and never covers what follows.
          // No aria-live here: this node is new on every render, so it would
          // be created already full and not be read. `say` speaks for it.
          var sumBox = h("div.pricebar-sum");
          if (state === "live") sumBox.appendChild(h("span.tval", { text: fmtMoney(sum, cur) }));
          var tnote = h("span.tnote", null, [note]);
          if (noteTo) tnote.appendChild(h("span.tnote-to", { text: " · " + noteTo }));
          sumBox.appendChild(tnote);
          var book = h("a.btn.book.giant", { href: bookHref, target: "_blank", rel: "noopener" }, [waIcon(), bookLabel]);
          if (quote) {
            book.addEventListener("click", function () {
              shownLog.booked(quote.id);
              logQuote(quote, picked, { booked: true, mode: "quick", contents: contents });
            });
          }
          result.appendChild(h("div.pricebar", { "data-state": state }, [sumBox, book]));
          // Under the price, never above it (rule 3): after the bar in the
          // DOM, so from 1024 it sits below the figure in the room .result
          // reserves for it, and below 1024 (where the bar is pinned to the
          // screen) it follows the contents line. Live only: it explains a
          // price, so it comes with one.
          if (state === "live") add(result, chargesDrill());
        } else {
          // The line that waits, at the TOP of the slot until there is a bar
          // (the CSS pins it there; the bar, when it comes, lands at the end).
          // Not aria-live, for the same reason as the bar; `say` reads it.
          // The server-rendered p.wait in calculator.php keeps its aria-live:
          // that one is in the page from the first byte, which is what a
          // live region needs, and it is replaced by this file on mount.
          result.appendChild(h("p.wait", { text: note }));
        }

        if (focusSvc) {
          keepFocus(focusWas, function () {
            var rs = result.querySelectorAll('input[name="q-svc"]');
            for (var ri = 0; ri < rs.length; ri++) if (rs[ri].value === focusSvc) return rs[ri];
            return null;
          });
        }

        /* --- what the screen reader hears ------------------------- *
         * One sentence, the same words the eye reads: the figure, the note
         * and the arrival line when live, the waiting line otherwise. Set
         * only when it changes, so a re-render that changes nothing (an
         * add-on toggled in the hidden view) does not repeat it. The first
         * render only records it: "Tap a country" is already on the page
         * and announcing it on load would talk over the page title. */
        var sentence = state === "live" ? [fmtMoney(sum, cur), note + (noteTo ? " · " + noteTo : ""), when].filter(Boolean).join(". ") : note;
        if (said === null) said = sentence;
        else if (sentence !== said) {
          said = sentence;
          say.textContent = sentence;
        }

        // Log the price once it has stayed on screen (see shownLog). Only
        // while this view is the one showing.
        if (mode() === "quick") {
          if (quote) shownLog.arm(quote, picked, { booked: false, mode: "quick", contents: contents });
          else shownLog.cancel();
        }
      }

      /** The tick beside a finished step head: one .step-done, replaced in place. */
      function stepDone(head, kids) {
        var old = head.querySelector(".step-done");
        if (old) head.removeChild(old);
        if (kids) head.appendChild(add(h("span.step-done"), kids));
      }

      return {
        el: panel,
        render: render,
        onDest: render,
        seedKg: seedKg,
        // Where focus lands when the quick view is opened by a tap.
        focusTarget: function () {
          return btnOther;
        },
      };
    })();

    /* ================================================================ *
     * DETAILED QUOTE - pieces, sizes, ship date
     *
     * It used to carry a Packages / Documents choice as well. The document rate
     * left the product on 2026-09-27 (owner), so there is nothing to choose
     * between: every piece is weighed and measured the same way.
     * ================================================================ */

    var detail = (function () {
      var from = cities.length ? cities[0] : "";
      var units = "metric";
      var pieces = [newRow(1, remembered().kg || "")];
      var nextKey = 2;
      // The key of the piece whose weight came from the quick view (seedKg)
      // or from the tab's remembered weight at boot, and has not been typed
      // in since. Only that piece, or a blank one, may be re-seeded: a
      // customer who tapped 5 kg, came here, went Back and tapped 10 kg was
      // still priced on 5 kg, because the old rule only filled a blank box
      // (review of audit B6, 2026-10-25). Typing in the box clears it, so
      // a weight the visitor typed is never overwritten.
      var seededKey = remembered().kg ? 1 : 0;
      var shipDateInput = "";
      var chosen = null; // { serviceId, sig, quoteId }
      var contents = "";

      var wrap = document.createDocumentFragment();
      var back = h("button.btn.outline.back", { type: "button" }, [h("span", { "aria-hidden": "true", text: "←" }), " Back to quick price"]);
      back.addEventListener("click", function () {
        switchMode("quick");
      });

      var panelWhere = h("div.panel");
      var panelWhat = h("div.panel");
      var results = h("div.results");
      // The quote card is inserted before this comment rather than into a
      // wrapper div: calculator.css styles .quote as a sibling of the panels
      // (".instrument .panel + .panel, .results, .quote" share the hairline
      // rule below 1024 and the boxed one above it), and a wrapper would put
      // a block between them for no reason.
      var quoteAnchor = document.createComment("quote");
      var quoteCard = null;

      var fromSel = null;
      var toSel = null;
      var piecesBox = null;
      var dateInput = null;
      var svcsBox = null;
      var svcsHead = null;
      var statusBox = null;
      var addonsHost = null;
      var hintVol = null;
      var addBtn = null;
      var unitBtns = {};

      /* ---- 1 Where to? ------------------------------------------- */
      panelWhere.appendChild(h("h2.step-head.step-title", { text: "1 Where to?" }));
      var rowWhere = h("div.row");
      if (cities.length > 1) {
        fromSel = h("select");
        cities.forEach(function (c) {
          fromSel.appendChild(h("option", { value: c, text: c + ", " + site.company.origin }));
        });
        fromSel.value = from;
        fromSel.addEventListener("change", function () {
          from = fromSel.value;
          render();
        });
        rowWhere.appendChild(add(h("label.field"), [h("span", { text: "From" }), fromSel]));
      } else {
        rowWhere.appendChild(
          add(h("div.field"), [h("span", { text: "From" }), h("div.static", { text: (cities.length ? cities[0] + ", " : "") + site.company.origin })]),
        );
      }
      var labTo = h("span.lab", null, ["To"]);
      toSel = h("select");
      fillDestOptions(toSel);
      toSel.addEventListener("change", function () {
        setDestId(toSel.value);
      });
      rowWhere.appendChild(add(h("label.field"), [labTo, toSel]));
      panelWhere.appendChild(rowWhere);

      /* ---- 2 What are you sending? -------------------------------
       *
       * No Packages / Documents segmented control: the document rate left the
       * product on 2026-09-27 (owner), so the answer to "what are you sending?"
       * is the weight and the size of each piece, which is what follows. */
      panelWhat.appendChild(h("h2.step-head.step-title", { text: "2 What are you sending?" }));

      var unitsRow = h("div.units", null, [h("span.hint", { text: "Units" })]);
      [["metric", "kg · cm"], ["imperial", "lb · in"]].forEach(function (pair) {
        var b = h("button.ubtn", { type: "button", "aria-pressed": pair[0] === "metric" ? "true" : "false", text: pair[1] });
        b.addEventListener("click", function () {
          units = pair[0];
          // The toggle only changes how the OTHER system's fields are shown;
          // it never rewrites a stored value, so it never moves a price.
          showPieceValues();
          renderPieceLabels();
          render();
        });
        unitBtns[pair[0]] = b;
        unitsRow.appendChild(b);
      });
      panelWhat.appendChild(unitsRow);

      piecesBox = h("div.pieces");
      panelWhat.appendChild(piecesBox);

      addBtn = h("button.btn.addpiece", { type: "button" });
      addBtn.addEventListener("click", function () {
        pieces.push(newRow(nextKey++));
        renderPieces();
        render();
      });
      hintVol = h("span.hint");
      panelWhat.appendChild(h("div.addrow", null, [addBtn, hintVol]));

      dateInput = h("input", { type: "date" });
      dateInput.addEventListener("change", function () {
        shipDateInput = dateInput.value;
        render();
      });
      panelWhat.appendChild(add(h("label.field.date"), [h("span", { text: "Ship date" }), dateInput]));

      /* ---- 3 Choose a service ----------------------------------- */
      // The head is held in a variable because there is now a state with no
      // service to choose: a weight no service prices (the owner's skippable
      // weights, 2026-09-28). "3 Choose a service" over an empty box is an
      // instruction the page cannot honour, so both go and the status line
      // says what happened instead.
      svcsHead = h("h2.step-head.step-title", { text: "3 Choose a service" });
      results.appendChild(svcsHead);
      svcsBox = h("div.svcs");
      results.appendChild(svcsBox);
      addonsHost = h("div.addons-detail");
      results.appendChild(addonsHost);
      statusBox = h("div.status", { "aria-live": "polite" });
      results.appendChild(statusBox);

      wrap.appendChild(back);
      wrap.appendChild(panelWhere);
      wrap.appendChild(panelWhat);
      wrap.appendChild(results);
      wrap.appendChild(quoteAnchor);

      /* ---- the fine print, printed once ------------------------- *
       * What is included, the owner's notes and the disclaimer are plain text
       * at the END of the detailed view. The quick view carries none of it -
       * the page prints the one-line promise under the calculator. */
      (function () {
        var includes = String(site.company.includes || "").trim();
        var notes = P.lines(site.company.notes);
        var disclaimer = String(sets.disclaimer || "").trim();
        if (includes) wrap.appendChild(h("p.includes", { text: includes }));
        if (notes.length) {
          var ul = h("ul.notes");
          notes.forEach(function (x) {
            ul.appendChild(h("li", { text: x }));
          });
          wrap.appendChild(ul);
        }
        if (disclaimer) wrap.appendChild(h("p.disclaimer", { text: disclaimer }));
      })();

      /* ---- piece rows ------------------------------------------ */

      var pieceEls = [];

      function inputSig() {
        return JSON.stringify({ destId: destId(), units: units, pieces: pieces, shipDate: shipDate(), from: from });
      }

      // Lahore's today, not the device's: see karachiNow().
      function today() {
        return inputDate(karachiNow());
      }

      function shipDate() {
        return shipDateInput || today();
      }

      // Every piece is a package now: "Envelope" belonged to the document
      // choice, which went with the document rate on 2026-09-27.
      var WORD = "Package";

      function measureInput(pc, field, attrs) {
        var inp = h("input", attrs);
        inp.value = shown(pc[field], units, field);
        inp.addEventListener("input", function () {
          // Store what was typed WITH the system it was typed in; never write
          // back to the box, so the caret never jumps.
          pc[field] = entry(inp.value, units);
          if (field === "kg" && pc.key === seededKey) seededKey = 0;
          if (field === "kg" && pieces.length && pieces[0].key === pc.key) rememberKg(shown(entry(inp.value, units), "metric", "kg"));
          render();
        });
        return inp;
      }

      function renderPieces() {
        clear(piecesBox);
        pieceEls = [];
        piecesBox.className = "pieces";
        var imp = units === "imperial";
        pieces.forEach(function (pc, i) {
          var row = h("div.piece");
          var title = h("div.ptitle");
          if (pieces.length === 1) title.hidden = true;
          var pnum = h("span.pnum", { text: WORD + " " + (i + 1) });
          var rm = h("button.btn.p-remove", { type: "button", text: "Remove" });
          rm.addEventListener("click", function () {
            if (pieces.length > 1) {
              pieces = pieces.filter(function (x) {
                return x.key !== pc.key;
              });
              renderPieces();
              render();
            }
          });
          title.appendChild(pnum);
          title.appendChild(rm);
          row.appendChild(title);

          var kgLab = h("span", { text: "Weight per piece (" + (imp ? "lb" : "kg") + ")" });
          var kgInp = measureInput(pc, "kg", { type: "number", inputmode: "decimal", min: "0.01", step: "any", placeholder: "e.g. 2.5" });

          var qtyInp = h("input", { type: "number", inputmode: "numeric", min: "1", step: "1" });
          qtyInp.value = pc.qty;
          qtyInp.addEventListener("input", function () {
            pc.qty = qtyInp.value;
            render();
          });

          var dimsLab = h("span", { text: "Size per piece in " + (imp ? "inches" : "cm") + " (optional)" });
          var dims = h("div.dims", null, [
            measureInput(pc, "L", { type: "number", inputmode: "decimal", min: "0.1", step: "any", placeholder: "L", "aria-label": "Length" }),
            h("span.x", { text: "×" }),
            measureInput(pc, "W", { type: "number", inputmode: "decimal", min: "0.1", step: "any", placeholder: "W", "aria-label": "Width" }),
            h("span.x", { text: "×" }),
            measureInput(pc, "H", { type: "number", inputmode: "decimal", min: "0.1", step: "any", placeholder: "H", "aria-label": "Height" }),
          ]);

          row.appendChild(
            h("div.row.three", null, [
              add(h("label.field"), [kgLab, kgInp]),
              add(h("label.field"), [h("span", { text: "Quantity" }), qtyInp]),
              add(h("div.field.dims-field"), [dimsLab, dims]),
            ]),
          );
          piecesBox.appendChild(row);
          pieceEls.push({ pc: pc, kgLab: kgLab, dimsLab: dimsLab, pnum: pnum, inputs: { kg: kgInp, L: dims.children[0], W: dims.children[2], H: dims.children[4] } });
        });
      }

      /** After a units change: repaint every measured box from `shown`. */
      function showPieceValues() {
        pieceEls.forEach(function (rec) {
          ["kg", "L", "W", "H"].forEach(function (f) {
            rec.inputs[f].value = shown(rec.pc[f], units, f);
          });
        });
      }

      /** After a units change: the labels that name the unit. */
      function renderPieceLabels() {
        var imp = units === "imperial";
        pieceEls.forEach(function (rec, i) {
          rec.kgLab.textContent = "Weight per piece (" + (imp ? "lb" : "kg") + ")";
          rec.dimsLab.textContent = "Size per piece in " + (imp ? "inches" : "cm") + " (optional)";
          rec.pnum.textContent = WORD + " " + (i + 1);
        });
      }

      /* ---- the whole detailed view ----------------------------- */

      function render() {
        var imp = units === "imperial";
        var rows = toRows(pieces);
        var res = P.priceAll(site, { destId: destId(), rows: rows });
        var sig = inputSig();
        var active = chosen && chosen.sig === sig ? chosen : null;
        var shipDateObj = localDateFromInput(shipDate());
        var picked = selectedAddons();
        var addTot = addonsTotal();

        /* --- the controls that echo state --------------------- */
        if (fromSel) fromSel.value = from;
        toSel.value = destId();
        var destSel = null;
        for (var i = 0; i < site.destinations.length; i++) if (site.destinations[i].id === destId() && site.destinations[i].active) destSel = site.destinations[i];
        var oldTick = labTo.querySelector(".step-done");
        if (oldTick) labTo.removeChild(oldTick);
        if (destSel) labTo.appendChild(add(h("span.step-done"), [flag(destSel.id, destSel.name, 24)]));

        unitBtns.metric.setAttribute("aria-pressed", units === "metric" ? "true" : "false");
        unitBtns.imperial.setAttribute("aria-pressed", units === "imperial" ? "true" : "false");
        addBtn.textContent = "Add another package";
        hintVol.textContent = "Large, light boxes are charged by volume (L×W×H" + (imp ? " in cm" : "") + " ÷ " + sets.volumetricDivisor + ").";
        dateInput.min = today();
        dateInput.value = shipDate();
        if (!addonsHost.firstChild) {
          var box = addonsBox();
          if (box) addonsHost.appendChild(box);
          else addonsHost.hidden = true;
        }

        /* --- the status line ---------------------------------- *
         * Which services this weight actually has. A blank price box is not a
         * price since 2026-09-28 (owner), so a weight can be priced by some
         * services, by one, or by none at all - and "none" is a sentence, not
         * an empty list. */
        var offered = res.ok
          ? site.services.filter(function (sv) {
              return !!res.prices[sv.id];
            })
          : [];
        var nothingPriced = res.ok && offered.length === 0;

        var statusClass = "status";
        clear(statusBox);
        if (!res.ok) {
          if (res.reason === "overmax") {
            statusClass = "status warn";
            var link = h("a", {
              href: waLink(site.company.whatsapp, cargoText(site.company.name, from, res.dest.name, res.weights, units)),
              target: "_blank",
              rel: "noopener",
              text: "Message us on WhatsApp",
            });
            add(statusBox, [
              "Shipments over " + (res.limitKg || sets.maxKg) + " kg get a cargo rate. " + weightSentence(res.weights, sets) + " ",
              link,
              " and we will price it within the hour.",
            ]);
          } else if (res.reason === "destination") {
            statusBox.textContent = "Choose a destination to see prices and delivery dates.";
          } else {
            // No weight yet. The engine judges the threshold on the WHOLE
            // shipment, never per piece: 2 × 13 kg is a 26 kg shipment. The
            // line says so, or someone reads "per piece" beside Quantity and
            // is refused after typing two boxes that each fit.
            statusBox.textContent = sets.maxKg > 0 ? "Up to " + sets.maxKg + " kg per shipment, all pieces together. Heavier is priced on WhatsApp." : "";
          }
        } else if (nothingPriced) {
          // THE OWNER'S SKIPPABLE WEIGHTS. No service prices this weight, so
          // there is nothing to choose and nothing to quote. The shape is the
          // cargo path's, deliberately: the same warn class, the same "here is
          // the sentence, here is the WhatsApp link, here is what happens
          // next", because it is the same conversation with a different reason.
          // The message carries the country AND the weight, so the owner can
          // answer it without asking anything back.
          statusClass = "status warn";
          var noRateLink = h("a", {
            href: waLink(
              site.company.whatsapp,
              noRateText(
                site.company.name,
                from,
                res.dest.name,
                "Pieces: " + piecesText(res.weights, units) + "\nChargeable: about " + P.gToKg(res.weights.billableG) + " kg",
              ),
            ),
            target: "_blank",
            rel: "noopener",
            text: "Message us on WhatsApp",
          });
          add(statusBox, [
            "We do not have a rate for " + P.gToKg(res.weights.billableG) + " kg to " + res.dest.name + ". " + weightSentence(res.weights, sets) + " ",
            noRateLink,
            " and we will price it within the hour.",
          ]);
        } else {
          var bits = [weightSentence(res.weights, sets)];
          if (sets.showEta) {
            var firstP = null;
            for (var s = 0; s < site.services.length; s++) {
              var pp = res.prices[site.services[s].id];
              if (pp && pp.days) {
                firstP = pp;
                break;
              }
            }
            var estFirst = firstP ? estimateDelivery(firstP.days, sets, shipDateObj, karachiNow()) : null;
            if (estFirst && estFirst.moved) {
              var now = karachiNow();
              var startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
              var afterCutoff =
                sets.cutoffHour !== null && sets.cutoffHour !== undefined && now.getHours() >= sets.cutoffHour && shipDateObj !== null && shipDateObj.getTime() <= startOfToday;
              bits.push((afterCutoff ? "Booked after the " + fmtHour(sets.cutoffHour) + " cutoff, so pickup is " : "Pickup is ") + fmtDay(estFirst.pickup) + ".");
            }
          }
          if (res.weights.volumetricWins) statusClass = "status warn";
          var isPicked = !!active && !!res.prices[active.serviceId];
          statusBox.textContent = isPicked ? bits.join(" ") : bits.join(" ") + " Choose a service to get your quote.";
        }
        statusBox.className = statusClass;

        /* --- the service cards -------------------------------- *
         * Before a weight is typed every service shows itself with its note:
         * that is the "here is what we offer" state and it is unchanged.
         *
         * Once the shipment IS priced, only the services that price THIS
         * weight appear. A service whose box is blank at this kilogram is not
         * offered here, and the owner's rule is to say nothing about it - no
         * dash, no "PKR 0", no greyed card implying it might be had for
         * something. When that leaves nothing at all, the head goes with the
         * box and the status line above carries the whole answer. */
        // Choosing a card IS a render, which rebuilds the cards: remember
        // which one had the keyboard and give it back below.
        var focusCard = focusedIn(svcsBox);
        var focusCardId = focusCard ? focusCard.getAttribute("data-svc") || "" : "";
        clear(svcsBox);
        svcsHead.hidden = nothingPriced;
        svcsBox.hidden = nothingPriced;
        (res.ok ? offered : site.services).forEach(function (sv) {
          var sp = res.ok ? res.prices[sv.id] : null;
          var e = sp && sp.days && sets.showEta ? estimateDelivery(sp.days, sets, shipDateObj, karachiNow()) : null;
          var isChosen = !!active && active.serviceId === sv.id;
          var btn = h("button.svc", { type: "button", "aria-pressed": isChosen ? "true" : "false", "data-svc": sv.id });
          if (!sp) btn.disabled = true;
          var name = h("span.svc-name", null, [sv.name]);
          if (sp) name.appendChild(h("span.svc-cta", { text: isChosen ? "Chosen" : "Choose" }));
          var eta = h("span.svc-eta");
          if (sp) {
            if (e) add(eta, ["Delivered ", h("strong", { text: fmtRange(e) })]);
            else if (sp.days) eta.textContent = sp.days + " working days";
            else eta.textContent = sv.note;
          } else eta.textContent = sv.note;
          btn.appendChild(add(h("span.svc-main"), [name, eta]));
          btn.appendChild(h("span.svc-price", { text: sp ? fmtMoney(sp.total, cur) : "" }));
          btn.addEventListener("click", function () {
            chosen = { serviceId: sv.id, sig: inputSig(), quoteId: quoteId(karachiNow()) };
            render();
          });
          svcsBox.appendChild(btn);
        });
        if (focusCardId) {
          keepFocus(focusCard, function () {
            // A walk, not a selector: a service id is the admin's text.
            for (var ci = 0; ci < svcsBox.children.length; ci++) if (svcsBox.children[ci].getAttribute("data-svc") === focusCardId) return svcsBox.children[ci];
            return null;
          });
        }

        /* --- the quote card ---------------------------------- */
        if (quoteCard && quoteCard.parentNode) quoteCard.parentNode.removeChild(quoteCard);
        quoteCard = null;
        if (!(res.ok && active && res.prices[active.serviceId])) {
          if (mode() === "detail") shownLog.cancel();
          return;
        }
        var price = res.prices[active.serviceId];
        var svp = null;
        for (var s4 = 0; s4 < site.services.length; s4++) if (site.services[s4].id === active.serviceId) svp = site.services[s4];
        var est = sets.showEta && price.days ? estimateDelivery(price.days, sets, shipDateObj, karachiNow()) : null;
        var quote = {
          id: active.quoteId,
          from: from,
          dest: res.dest.name,
          destId: res.dest.id,
          service: svp ? svp.name : "",
          serviceId: active.serviceId,
          days: price.days,
          eta: est ? fmtRange(est) : "",
          pickup: est ? fmtDay(est.pickup) : shipDateObj ? fmtDay(shipDateObj) : "",
          pieces: res.weights.pieces,
          piecesText: piecesText(res.weights, units),
          billableG: res.weights.billableG,
          chargeG: res.weights.chargeG,
          total: price.total,
          version: site.version,
        };
        var text = quoteText(quote, { companyName: site.company.name, currency: cur, addons: picked, contents: contents, separate: separate });

        /* how the price was reached, line by line */
        var how = [];
        res.weights.lines.forEach(function (l) {
          how.push(
            l.qty + " × " + P.gToKg(l.actualG) + " kg actual" +
              (l.volG
                ? "; volumetric " +
                  [l.L, l.W, l.H].map(function (x) { return Math.round(x * 10) / 10; }).join("×") +
                  " cm ÷ " + sets.volumetricDivisor + " = " + P.gToKg(l.volG) + " kg" +
                  (l.volumetricWins ? " (volumetric applies)" : "")
                : "") +
              (l.qty > 1 ? ", each" : ""),
          );
        });
        if (P.isGrid(sets))
          how.push("Chargeable weight " + P.gToKg(res.weights.chargeG) + " kg, billed as " + P.gToKg(res.weights.billableG) + " kg (rounded up to the next whole kilogram)");
        else
          how.push(
            "Chargeable weight " + P.gToKg(res.weights.chargeG) + " kg, billed as " + P.gToKg(res.weights.billableG) +
              " kg (" + sets.stepKg + " kg steps, minimum " + sets.firstKg + " kg)",
          );
        if (P.isGrid(sets))
          how.push(quote.service + " price for " + (price.gridKg === undefined || price.gridKg === null ? P.gToKg(res.weights.billableG) : price.gridKg) + " kg: " + fmtMoney(price.base, cur));
        else
          how.push(
            "First " + sets.firstKg + " kg " + fmtMoney(price.first || 0, cur) +
              (price.steps ? " + " + price.steps + " × " + sets.stepKg + " kg at " + fmtMoney(price.addl || 0, cur) : "") +
              " = " + fmtMoney(price.base, cur),
          );
        if (price.tax) how.push("Tax " + sets.taxPct + "% = " + fmtMoney(price.tax, cur));
        if (Number(sets.roundTo) > 1) how.push("Rounded to the nearest " + fmtNum(sets.roundTo));
        if (est)
          how.push(
            "Delivery estimate counts " + price.days + " working days (" +
              String(sets.workingDays || "Mon–Fri").replace(/\s+/g, " ") +
              (String(sets.holidays || "").trim() ? ", holidays skipped" : "") +
              ") from pickup on " + fmtDay(est.pickup),
          );

        var card = h("div.quote", { id: "quote" });
        var hd = h("h2", null, [quote.service + " to " + quote.dest]);
        card.appendChild(hd);

        var dl = h("dl");
        // Only add-ons that cost something get a row - the quick view's split
        // line has the same rule. A Delivery left on at 0 printed "Delivery
        // PKR 0", the one figure rule 4 says never appears; with nothing paid
        // the card falls back to a single Price, as the quick bar does.
        var paid = picked.filter(function (a) {
          return a.amount > 0;
        });
        if (paid.length) {
          dl.appendChild(h("dt", { text: "Courier" }));
          dl.appendChild(h("dd", { text: fmtMoney(quote.total, cur) }));
          paid.forEach(function (a) {
            dl.appendChild(h("dt", { text: a.label }));
            dl.appendChild(h("dd", { text: fmtMoney(a.amount, cur) }));
          });
          dl.appendChild(h("dt", { text: "Total" }));
          dl.appendChild(h("dd.display", { text: fmtMoney(quote.total + addTot, cur) }));
        } else {
          dl.appendChild(h("dt", { text: "Price" }));
          dl.appendChild(h("dd.display", { text: fmtMoney(quote.total, cur) }));
        }
        if (quote.eta) {
          dl.appendChild(h("dt", { text: "Delivered" }));
          dl.appendChild(add(h("dd"), [quote.eta + " ", h("span.hint", { text: "estimated" })]));
        } else if (quote.days) {
          dl.appendChild(h("dt", { text: "Transit" }));
          dl.appendChild(h("dd", { text: quote.days + " working days" }));
        }
        if (quote.pickup) {
          dl.appendChild(h("dt", { text: "Pickup" }));
          dl.appendChild(h("dd", { text: quote.pickup }));
        }
        dl.appendChild(h("dt", { text: "Route" }));
        dl.appendChild(h("dd", { text: (quote.from ? quote.from + ", " : "") + site.company.origin + " → " + quote.dest }));
        dl.appendChild(h("dt", { text: "Packages" }));
        dl.appendChild(h("dd", { text: quote.piecesText }));
        dl.appendChild(h("dt", { text: "Charged on" }));
        dl.appendChild(h("dd", { text: P.gToKg(quote.billableG) + " kg" }));
        dl.appendChild(h("dt", { text: "Quote" }));
        dl.appendChild(add(h("dd"), [h("span.qid", { text: quote.id }), " ", h("span.hint", { text: "valid today" })]));
        card.appendChild(dl);
        // The separate charges, directly under the Price / Total they are NOT
        // part of (owner, 2026-10-26). The card is new on every render, so a
        // drill-down here cannot move anything that was already on screen.
        add(card, chargesDrill());

        var howUl = h("ul");
        how.forEach(function (x) {
          howUl.appendChild(h("li", { text: x }));
        });
        card.appendChild(h("details.how", null, [h("summary", { text: "How this price is calculated" }), howUl]));

        var contentsInp = h("input", { type: "text", maxlength: "120", placeholder: "e.g. clothes and a gift" });
        contentsInp.value = contents;
        contentsInp.addEventListener("input", function () {
          contents = contentsInp.value;
          // Only the WhatsApp text and the log change, so nothing is
          // re-rendered under the caret: patch the link in place.
          var href = waLink(site.company.whatsapp, quoteText(quote, { companyName: site.company.name, currency: cur, addons: picked, contents: contents, separate: separate }));
          bookLink.setAttribute("href", href);
        });
        card.appendChild(
          add(h("label.field"), [
            add(h("span"), ["What is inside? ", h("span.hint", { text: "(optional, helps us book faster)" })]),
            contentsInp,
          ]),
        );

        var bookLink = h("a.btn.book.giant", { target: "_blank", rel: "noopener", href: waLink(site.company.whatsapp, text) }, [waIcon(), "Book on WhatsApp"]);
        bookLink.addEventListener("click", function () {
          shownLog.booked(quote.id);
          logQuote(quote, picked, { booked: true, mode: "detail", contents: contents });
        });
        var actions2 = h("div.actions-2");
        if (typeof navigator.share === "function") {
          var shareBtn = h("button.btn.outline", { type: "button", text: "Share" });
          shareBtn.addEventListener("click", function () {
            var payload = { title: site.company.name + " quote " + quote.id, text: quoteText(quote, { companyName: site.company.name, currency: cur, addons: picked, contents: contents, separate: separate }) };
            var pr = navigator.share(payload);
            if (pr && pr.catch) pr.catch(function () {});
          });
          actions2.appendChild(shareBtn);
        }
        var copyBtn = h("button.btn.outline", { type: "button", text: "Copy" });
        copyBtn.addEventListener("click", function () {
          var body = quoteText(quote, { companyName: site.company.name, currency: cur, addons: picked, contents: contents, separate: separate });
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(body).then(
              function () {
                if (UI.toast) UI.toast("Quote copied");
              },
              function () {
                if (UI.toast) UI.toast("Copy failed. Select the text instead");
              },
            );
          } else if (UI.toast) UI.toast("Copy is not supported here");
        });
        actions2.appendChild(copyBtn);
        card.appendChild(h("div.actions", null, [bookLink, actions2]));

        quoteCard = card;
        if (quoteAnchor.parentNode) quoteAnchor.parentNode.insertBefore(card, quoteAnchor);

        // The card is the detailed view's price on screen (see shownLog).
        if (mode() === "detail") shownLog.arm(quote, picked, { booked: false, mode: "detail", contents: contents });
      }

      renderPieces();

      /**
       * On the way in from the quick view: a tapped weight fills the first
       * piece when it is blank OR still holds an untouched seeded weight
       * (seededKey). Never overwrites what was typed here. The second half
       * matters because the box is rarely blank any more: it is pre-filled
       * from the tab's remembered weight at boot, and every quick tap now
       * updates that weight, so "tap 5 kg, reload, tap 3 kg, Box size"
       * showed 5 kg here while the quick view had said 3 kg.
       */
      function seedKg(v) {
        if (!v || !pieces.length) return;
        var first = pieces[0];
        if (String(first.kg.v).trim() !== "" && first.key !== seededKey) return;
        first.kg = entry(String(v), "metric");
        seededKey = first.key;
        renderPieces();
        render();
      }

      return {
        el: wrap,
        render: render,
        onDest: render,
        seedKg: seedKg,
        // Where focus lands when the detailed view is opened by a tap: the
        // Back button, the first control of the view and the one that says
        // where the visitor now is.
        focusTarget: function () {
          return back;
        },
      };
    })();

    /* ---- the two views, one at a time (React's `hidden` wrappers) -- */

    var quickWrap = h("div", null, [quick.el]);
    var detailWrap = h("div", null, [detail.el]);

    /**
     * `byTap` is true when the visitor switched views (Box size · many pieces,
     * Back), false at boot. On a tap, hiding the wrapper hid the very button
     * that had focus, and focus fell to <body>: Tab went on from somewhere
     * unpredictable and nothing said a new form had opened (audit B6,
     * 2026-10-25). So focus moves into the view that opened - preventScroll,
     * rule 1 - and the weight travels with it (see onKg). At boot nothing is
     * focused: the page opens where the visitor is.
     *
     * The newly visible view is re-rendered so its price, if it has one,
     * counts as shown (shownLog) and the hidden one's pending log is dropped.
     */
    function applyMode(byTap) {
      var m = mode();
      quickWrap.hidden = m !== "quick";
      detailWrap.hidden = m !== "detail";
      if (!byTap) return;
      shownLog.cancel();
      var kg = remembered().kg;
      if (m === "detail") {
        detail.seedKg(kg);
        detail.render();
      } else {
        quick.seedKg(kg);
        quick.render();
      }
      var to = m === "detail" ? detail.focusTarget() : quick.focusTarget();
      if (to && typeof to.focus === "function") to.focus({ preventScroll: true });
    }

    clear(mount);
    mount.appendChild(quickWrap);
    mount.appendChild(detailWrap);
    applyMode();
    quick.render();
    detail.render();
  }

  /* ================================================================== *
   * HOLD PANEL - src/components/site/HoldPanel.tsx
   *
   * What the quote page shows while prices are on hold: the admin's message
   * and a short form that turns into a WhatsApp message. It never receives a
   * price - the rate document stays on the server, and the blob for this
   * panel carries names only.
   * ================================================================== */

  function HoldPanel(mount, blob) {
    var companyName = blob.companyName;
    var whatsapp = blob.whatsapp;
    var maxKg = Number(blob.maxKg) || 0;
    var dests = (blob.destinations || []).slice().sort(function (a, b) {
      return a.name.localeCompare(b.name);
    });
    var destId = "";
    var kg = "";
    var contents = "";

    function holdText(dest, kgStr) {
      // pricing.js owns this sentence when it has it: the server logs the same
      // by-hand enquiry and the two must read alike.
      if (P && typeof P.holdText === "function") return P.holdText(companyName, dest, kgStr, contents);
      var out = ["Hi " + companyName + ", please price a parcel for me."];
      if (dest) out.push("To: " + dest);
      if (kgStr) out.push("Weight: about " + kgStr + " kg");
      if (contents.trim()) out.push("Contents: " + contents.trim());
      return out.join("\n");
    }

    var labCountry = h("span.lab", null, ["Country"]);
    var sel = h("select");
    // `selected` as well as `disabled` - see fillDestOptions. This is the one
    // the owner saw: the hold panel's enquiry form opened reading "Australia",
    // the first destination, as though a country had already been chosen, and
    // the WhatsApp message it built said so. The server-rendered form in
    // hold-panel.php always had the pair; this, which replaces it on mount,
    // did not.
    sel.appendChild(h("option", { value: "", disabled: true, selected: true, text: "Tap to choose" }));
    dests.forEach(function (d) {
      sel.appendChild(h("option", { value: d.id, text: d.name }));
    });
    var kgInp = h("input", {
      type: "number",
      inputmode: "decimal",
      min: "0.1",
      step: "0.1",
      max: "10000",
      placeholder: maxKg > 0 ? "1 to " + maxKg + ", or more for cargo" : "e.g. 2.5",
    });
    var kgHint = h("span.hint", { text: "Enter a weight above 0." });
    var contentsInp = h("input", { type: "text", maxlength: "120", placeholder: "Clothes, documents, gifts…" });
    var link = h("a.btn.wa.giant", { target: "_blank", rel: "noopener" }, [waIcon(), "Ask for today's rate on WhatsApp"]);
    var foot = h("p.hint");

    var kgField = add(h("label.field"), [h("span.lab", { text: "Weight (kg)" }), kgInp]);

    function render() {
      var dest = null;
      for (var i = 0; i < dests.length; i++) if (dests[i].id === destId) dest = dests[i];
      var kgNum = Number(kg);
      var kgOk = kg === "" || (isFinite(kgNum) && kgNum > 0 && kgNum <= 10000);
      var ready = !!dest && kg !== "" && kgOk;
      var old = labCountry.querySelector(".step-done");
      if (old) labCountry.removeChild(old);
      if (dest) labCountry.appendChild(add(h("span.step-done"), [flag(dest.id, dest.name, 24), dest.name]));
      if (kgOk) {
        if (kgHint.parentNode) kgField.removeChild(kgHint);
      } else if (!kgHint.parentNode) kgField.appendChild(kgHint);
      link.setAttribute("href", waLink(whatsapp, holdText(dest ? dest.name : "", kgOk && kg ? String(kgNum) : "")));
      foot.textContent = ready ? "We reply with a price by hand." : "Add the country and weight so we can quote straight away.";
    }

    sel.addEventListener("change", function () {
      destId = sel.value;
      render();
    });
    kgInp.addEventListener("input", function () {
      kg = kgInp.value;
      render();
    });
    contentsInp.addEventListener("input", function () {
      contents = contentsInp.value.slice(0, 120);
      render();
    });

    var panel = h("div.panel.quick.hold-panel", null, [
      h("h2.step-head", null, [h("span.step-name", { text: "Prices are being updated" })]),
      h("p.hold-msg", { text: blob.message || "" }),
      add(h("label.field"), [labCountry, sel]),
      kgField,
      add(h("label.field"), [h("span.lab", { text: "What is inside (optional)" }), contentsInp]),
      link,
      foot,
    ]);

    clear(mount);
    mount.appendChild(panel);
    render();
  }

  /* ================================================================== *
   * boot
   * ================================================================== */

  function boot() {
    P = window.SpeedatPricing || P;
    adoptFromEngine();

    var holdMount = document.querySelector("[data-hold-panel]");
    if (holdMount) {
      var holdBlob = UI.readBlob ? UI.readBlob(holdMount.getAttribute("data-hold-panel") || "speedat-hold") : null;
      if (holdBlob) HoldPanel(holdMount, holdBlob);
    }

    var mount = document.querySelector("[data-calculator]");
    if (!mount) return;
    var blob = UI.readBlob ? UI.readBlob(mount.getAttribute("data-calculator") || "speedat-calculator") : null;
    if (!blob || !blob.site) return;
    if (!P || typeof P.priceAll !== "function" || !quoteText || !weightSentence) {
      // Leave whatever PHP printed in the mount (the plain WhatsApp way out).
      // Half a calculator is worse than none, and a calculator that words the
      // quote differently from the server is worse than half.
      if (window.console && console.warn) console.warn("calculator.js: window.SpeedatPricing is missing (or lacks quoteText/weightSentence); leaving the no-script fallback in place.");
      return;
    }
    flagsBase = blob.flagsBase || flagsBase;
    flagCodes = blob.flags || {};
    Calculator(mount, blob);
  }

  if (UI.ready) UI.ready(boot);
  else if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
