/*!
 * Speedat pricing engine - browser twin.
 *
 * This is what makes the price appear the moment the customer stops typing.
 * The server prices the same shipment again with app/Domain/Pricing/Engine.php
 * before anything is stored, and the two must never disagree: both are checked
 * against tests/vectors/pricing.json, which is generated from the TypeScript
 * engine the Next.js site runs today. See docs/engine-parity.md.
 *
 * Plain ES2019. No modules, no bundler, no framework, no dependencies: it is a
 * <script src> on a shared host. Everything hangs off one global.
 *
 * THE ONE DELIBERATE DIVERGENCE FROM THE TYPESCRIPT: there is no document rate.
 * No flat price, no weight limit, no shipment type - the owner removed it from
 * the product on 2026-09-27 and everything is priced by weight. The vector file
 * has had its document cases removed to match; docs/engine-parity.md says
 * exactly which, and why regenerating from the TypeScript could not express it.
 *
 * Three rules are load-bearing and must not be "tidied up":
 *   1. Weights are integer grams throughout; kilograms are for display only.
 *   2. The order is base -> tax -> round.
 *   3. A blank grid box is NOT A PRICE: that service is not offered at that
 *      weight (owner, 2026-09-28). It used to fall upward to the next priced
 *      box, which charged a number nobody had typed.
 */
(function (root) {
  "use strict";

  /* ------------------------------------------------------------- numbers */

  function isNum(v) {
    return typeof v === "number" && isFinite(v);
  }

  function kgToG(kg) {
    return Math.round(Number(kg) * 1000);
  }

  /** Grams to kilograms as a short string ("2.5", "12"). */
  function gToKg(g) {
    return String(Math.round((g / 1000) * 100) / 100);
  }

  function roundTo(n, r) {
    var step = Number(r) || 1;
    return Math.round(n / step) * step;
  }

  /**
   * Thousands separators, written out rather than taken from Intl.
   * Intl's grouping is locale data, and locale data differs between a browser
   * and a shared host's PHP. The PHP twin prints these same digits.
   */
  function fmtNum(n) {
    var v = Math.round(Number(n));
    if (!isFinite(v)) return String(n);
    var neg = v < 0;
    var s = String(Math.abs(v));
    var out = "";
    for (var i = 0; i < s.length; i++) {
      if (i > 0 && (s.length - i) % 3 === 0) out += ",";
      out += s.charAt(i);
    }
    return (neg ? "-" : "") + out;
  }

  function fmtMoney(n, currency) {
    return currency + " " + fmtNum(n);
  }

  /* --------------------------------------------------------- rate cards */

  function isGrid(settings) {
    return settings.pricingMode === "grid";
  }

  /** Whole kilograms the grid prices, 1 ... cargo threshold (30 when there is none). */
  function gridWeights(settings) {
    var top = settings.maxKg > 0 ? Math.min(Math.floor(settings.maxKg), 200) : 30;
    var out = [];
    for (var i = 1; i <= Math.max(top, 1); i++) out.push(i);
    return out;
  }

  /**
   * Grid mode bills whole kilograms from 1 kg up, whatever the slab step says.
   * Anything that explains a rounding to the customer must read this.
   */
  function effectiveSteps(settings) {
    return isGrid(settings)
      ? { firstKg: 1, stepKg: 1 }
      : { firstKg: settings.firstKg, stepKg: settings.stepKg };
  }

  /**
   * The price entered at THAT kilogram, or nothing.
   *
   * OWNER DECISION, 2026-09-28: a blank box is not a price and the service is
   * not offered at that weight. It used to fall upward to the next priced
   * kilogram, so an empty 3 kg box quietly charged the 5 kg price. The server
   * half is Engine::gridPrice() and the two must agree to the rupee.
   *
   * Zero is not a price either - the site never prints "PKR 0".
   */
  function gridPrice(grid, kg, maxKg) {
    if (!grid) return null;
    var top = maxKg > 0 ? Math.floor(maxKg) : 200;
    // Whole-kilogram billing first: 1.2 kg reads the 2 kg box. That rounding
    // happens before a box is read and is not the fallback that went.
    var k = Math.max(1, Math.ceil(kg));
    if (k > top) return null;
    var p = grid[String(k)];
    return isNum(p) && p > 0 ? { kg: k, price: p } : null;
  }

  function computeWeights(settings, rows) {
    var eff = effectiveSteps(settings);
    var stepG = kgToG(eff.stepKg);
    var firstG = kgToG(eff.firstKg);

    if (!(stepG > 0)) {
      // The server refuses to publish a document with a step of zero; reaching
      // here means an unvalidated document, and a NaN price is worse than a
      // visible failure.
      throw new Error("The weight step must be greater than 0 kg.");
    }

    var pieces = 0;
    var actualG = 0;
    var volG = 0;
    var chargeG = 0;
    var lines = [];

    for (var i = 0; i < rows.length; i++) {
      var r = rows[i];
      var kg = Number(r.kg);
      // isNum, not `kg > 0`: Infinity passes a bare > 0 test and then prices a
      // parcel at the cheapest box. The PHP engine refuses non-finite weights
      // (Domain\Pricing\EngineGuards); this is the browser's half of that.
      if (!isNum(kg) || kg <= 0) continue;

      var qty = Math.max(1, Math.floor(Number(r.qty) || 1));
      var L = Number(r.L);
      var W = Number(r.W);
      var H = Number(r.H);
      // A divisor of 0 (or a cleared box) divides to Infinity and turns a 1 kg
      // parcel into a cargo enquiry. Settings::effectiveDivisor() does exactly
      // this on the server; keep the two in step.
      var divisor = isNum(settings.volumetricDivisor) && settings.volumetricDivisor > 0 ? settings.volumetricDivisor : 5000;
      var v = L > 0 && W > 0 && H > 0 ? Math.round(((L * W * H) / divisor) * 1000) : 0;
      var a = kgToG(kg);
      var c = Math.max(a, v);

      pieces += qty;
      actualG += a * qty;
      volG += v * qty;
      chargeG += c * qty;

      lines.push({ qty: qty, kg: kg, L: L, W: W, H: H, actualG: a, volG: v, pieceG: c, volumetricWins: v > a });
    }

    return {
      pieces: pieces,
      actualG: actualG,
      volG: volG,
      chargeG: chargeG,
      billableG: Math.max(firstG, Math.ceil(chargeG / stepG) * stepG),
      volumetricWins: chargeG > actualG,
      lines: lines
    };
  }

  /**
   * Price one service from the BILLED grams.
   *
   * The TypeScript also takes a shipment type and the chargeable grams; both
   * existed only to decide whether the flat document price applied. With the
   * document rate gone (owner, 2026-09-27) they are not inputs to a price, and
   * leaving them in the signature would invite a caller to think otherwise.
   */
  function priceService(settings, dest, serviceId, billableG) {
    var r = dest.rates ? dest.rates[serviceId] : null;
    if (!r) return null;

    var eff = effectiveSteps(settings);
    var firstG = kgToG(eff.firstKg);
    var stepG = kgToG(eff.stepKg);

    var base;
    var steps = 0;
    var gridKg;

    if (isGrid(settings)) {
      var hit = gridPrice(r.grid, billableG / 1000, settings.maxKg);
      if (!hit) return null;
      base = hit.price;
      gridKg = hit.kg;
    } else {
      if (!isNum(r.first) || !isNum(r.addl)) return null;
      if (!(stepG > 0)) throw new Error("The weight step must be greater than 0 kg.");
      steps = billableG <= firstG ? 0 : Math.ceil((billableG - firstG) / stepG);
      base = r.first + steps * r.addl;
    }

    var tax = (base * (Number(settings.taxPct) || 0)) / 100;

    return {
      first: r.first,
      addl: r.addl,
      steps: steps,
      gridKg: gridKg,
      base: base,
      tax: tax,
      total: roundTo(base + tax, settings.roundTo),
      days: r.days == null ? "" : r.days
    };
  }

  function priceAll(card, input) {
    var dest = null;
    for (var i = 0; i < card.destinations.length; i++) {
      if (card.destinations[i].id === input.destId && card.destinations[i].active) {
        dest = card.destinations[i];
        break;
      }
    }
    if (!dest) return { ok: false, reason: "destination" };

    var rows = [];
    for (var j = 0; j < input.rows.length; j++) {
      // isNum (finite) AND > 0 - the SAME test computeWeights and the PHP
      // engine (Num::isNum && > 0) apply. A plain `> 0` admitted Infinity,
      // which then fell through computeWeights' own isNum guard to the minimum
      // box, so the browser priced a non-finite weight at the cheapest slab
      // while PHP refused it. The two engines must never disagree.
      var k = Number(input.rows[j].kg);
      if (isNum(k) && k > 0) rows.push(input.rows[j]);
    }
    if (!rows.length) return { ok: false, reason: "weight" };

    var weights = computeWeights(card.settings, rows);
    var maxG = card.settings.maxKg > 0 ? kgToG(card.settings.maxKg) : 0;
    if (maxG && weights.billableG > maxG) return { ok: false, reason: "overmax", dest: dest, weights: weights };

    // `input.type` is ignored: the TypeScript branched on it for the document
    // rate, which left the product on 2026-09-27 (owner). A caller that still
    // passes it gets the weight price, which is now the only price there is.
    var prices = {};
    for (var k = 0; k < card.services.length; k++) {
      var id = card.services[k].id;
      prices[id] = priceService(card.settings, dest, id, weights.billableG);
    }

    return { ok: true, dest: dest, weights: weights, prices: prices };
  }

  /* ---------------------------------------------- settings and sheet text */

  /** Non-empty trimmed lines of a multi-line setting. */
  function lines(text) {
    var out = [];
    var all = String(text == null ? "" : text).split(/\r?\n/);
    for (var i = 0; i < all.length; i++) {
      var t = all[i].trim();
      if (t) out.push(t);
    }
    return out;
  }

  /** Split an `a | b | c` line into at least `n` trimmed parts. */
  function parts(line, n) {
    var p = line.split("|");
    for (var i = 0; i < p.length; i++) p[i] = p[i].trim();
    while (p.length < n) p.push("");
    return p;
  }

  /** "Rs 4,500/-" becomes 4500; "-" and "n/a" become null; junk becomes NaN. */
  function toNumLoose(v) {
    if (v == null) return null;
    if (typeof v === "number") return isFinite(v) ? v : NaN;
    var s = String(v).trim();
    if (!s || /^[-–\u2014]$/.test(s) || /^n\/?a$/i.test(s)) return null;
    var m = s.replace(/,/g, "").match(/-?\d+(\.\d+)?/);
    return m ? Number(m[0]) : NaN;
  }

  function addonsList(settings) {
    var out = [];
    var ls = lines(settings.addons);
    for (var i = 0; i < ls.length; i++) {
      var p = parts(ls[i], 3);
      var amt = toNumLoose(p[1]);
      if (!p[0] || !isNum(amt) || amt < 0) continue;
      out.push({ id: "a" + i, label: p[0], amount: amt, on: /^(on|yes|true|1|checked|default)$/i.test(p[2]) });
    }
    return out;
  }

  /** Whole-kilogram prices derived from slab prices; existing boxes are kept. */
  function slabToGrid(settings, rate) {
    var out = {};
    var key;
    if (rate.grid) for (key in rate.grid) if (Object.prototype.hasOwnProperty.call(rate.grid, key)) out[key] = rate.grid[key];

    if (isNum(rate.first) && isNum(rate.addl)) {
      var firstG = kgToG(settings.firstKg);
      var stepG = kgToG(settings.stepKg);
      if (!(stepG > 0)) throw new Error("The weight step must be greater than 0 kg.");

      var kgs = gridWeights(settings);
      for (var i = 0; i < kgs.length; i++) {
        var kg = kgs[i];
        if (isNum(out[String(kg)])) continue;
        var g = kg * 1000;
        var steps = g <= firstG ? 0 : Math.ceil((g - firstG) / stepG);
        out[String(kg)] = roundTo(rate.first + steps * rate.addl, settings.roundTo);
      }
    }

    return Object.keys(out).length ? out : undefined;
  }

  /* ------------------------------------------------------- transit times */

  var DAY_NAMES = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"];

  function parseDaysRange(str) {
    var m = String(str == null ? "" : str).match(/\d+/g);
    if (!m) return null;
    var a = Number(m[0]);
    var b = m[1] != null ? Number(m[1]) : a;
    return [Math.min(a, b), Math.max(a, b)];
  }

  /** "3 to 5 working days" becomes "3-5" (with an en dash). */
  function parseDays(v) {
    var s = String(v == null ? "" : v).trim();
    if (!s) return "";
    return s
      .replace(/\s*(working|business)?\s*days?\s*$/i, "")
      .replace(/\s*(-|to|–|\u2014)\s*/g, "–")
      .trim();
  }

  /** Working days as day indexes (0 = Sunday); Mon-Fri when nothing parses. */
  function workingSet(settings) {
    var set = {};
    var txt = String(settings.workingDays == null ? "" : settings.workingDays).toLowerCase();

    txt = txt.replace(
      /(sun|mon|tue|wed|thu|fri|sat)[a-z]*\s*(?:-|–|\u2014|to|through|till|until)\s*(sun|mon|tue|wed|thu|fri|sat)[a-z]*/g,
      function (whole, a, b) {
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
      }
    );

    var tokens = txt.split(/[^a-z]+/);
    for (var t = 0; t < tokens.length; t++) {
      var i2 = DAY_NAMES.indexOf(tokens[t].slice(0, 3));
      if (i2 >= 0) set[i2] = true;
    }

    var out2 = Object.keys(set).map(Number);
    if (!out2.length) out2 = [1, 2, 3, 4, 5];
    out2.sort(function (a, b) {
      return a - b;
    });
    return out2;
  }

  /** Holidays as a map of "YYYY-MM-DD" keys. */
  function holidaySet(settings) {
    var out = {};
    var all = String(settings.holidays == null ? "" : settings.holidays).split(/\r?\n/);
    for (var i = 0; i < all.length; i++) {
      var m = all[i].trim().match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (m) out[m[1] + "-" + m[2] + "-" + m[3]] = true;
    }
    return out;
  }

  /** Holiday lines that are not `YYYY-MM-DD`, for the admin's validation. */
  function badHolidayLines(settings) {
    var out = [];
    var all = String(settings.holidays == null ? "" : settings.holidays).split(/\r?\n/);
    for (var i = 0; i < all.length; i++) {
      var l = all[i].trim();
      if (l && !/^\d{4}-\d{2}-\d{2}(\s*\|.*)?$/.test(l)) out.push(l);
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

  /** When the parcel can be collected, and when it should arrive. */
  function estimateDelivery(daysStr, settings, shipDate, now) {
    var range = parseDaysRange(daysStr);
    if (!range) return null;

    var ws = workingSet(settings);
    var hol = holidaySet({ holidays: settings.holidays == null ? "" : settings.holidays });

    var working = function (d) {
      return ws.indexOf(d.getDay()) >= 0 && !hol[dayKey(d)];
    };

    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var start = shipDate ? new Date(shipDate.getFullYear(), shipDate.getMonth(), shipDate.getDate()) : today;
    if (start < today) start = today;

    var moved = false;
    var cutoff = settings.cutoffHour;
    if (start.getTime() === today.getTime() && cutoff != null && isFinite(cutoff) && now.getHours() >= cutoff) {
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

  /* ---------------------------------------------------------- formatting */

  /**
   * en-GB short names, written down rather than asked of Intl: ICU says
   * "Sept" for September in en-GB and has changed its mind between releases,
   * and the PHP twin on the shared host has no ICU to ask.
   */
  var MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sept", "Oct", "Nov", "Dec"];
  var WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

  function pad2(n) {
    return (n < 10 ? "0" : "") + n;
  }

  /** "Wed 23 Sept". */
  function fmtDay(dt) {
    return WEEKDAYS[dt.getDay()] + " " + dt.getDate() + " " + MONTHS[dt.getMonth()];
  }

  /** "Wed 23 Sept - Fri 25 Sept", collapsed when the window is one day. */
  function fmtRange(est) {
    return est.from.getTime() === est.to.getTime()
      ? fmtDay(est.from)
      : fmtDay(est.from) + " – " + fmtDay(est.to);
  }

  /** "23 Sept 2026". */
  function fmtDate(iso) {
    var dt = iso instanceof Date ? iso : new Date(iso);
    if (isNaN(dt.getTime())) return "";
    return dt.getDate() + " " + MONTHS[dt.getMonth()] + " " + dt.getFullYear();
  }

  /** "23 Sept 2026, 14:05". */
  function fmtDateTime(iso) {
    var dt = iso instanceof Date ? iso : new Date(iso);
    if (isNaN(dt.getTime())) return "";
    return (
      dt.getDate() + " " + MONTHS[dt.getMonth()] + " " + dt.getFullYear() + ", " + pad2(dt.getHours()) + ":" + pad2(dt.getMinutes())
    );
  }

  /** "923157667076" becomes "+92 315 766 7076"; anything else becomes "+<digits>". */
  function fmtPhone(n) {
    var s = String(n == null ? "" : n);
    var m = s.match(/^92(3\d{2})(\d{3})(\d{4})$/);
    return m ? "+92 " + m[1] + " " + m[2] + " " + m[3] : "+" + s;
  }

  /** 15 becomes "3 pm", 0 becomes "12 am". */
  function fmtHour(h) {
    if (h == null) return "";
    var n = Number(h);
    if (!isFinite(n)) return "";
    var ap = n >= 12 ? "pm" : "am";
    var x = n % 12;
    return (x === 0 ? 12 : x) + " " + ap;
  }

  /** A local calendar date as `<input type="date">` wants it. */
  function inputDate(dt) {
    return dt.getFullYear() + "-" + pad2(dt.getMonth() + 1) + "-" + pad2(dt.getDate());
  }

  /** "YYYY-MM-DD" becomes a local Date, or null when malformed. */
  function localDateFromInput(v) {
    var m = String(v == null ? "" : v).match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
  }

  /** Quote reference such as SP-260920-K7QX. */
  function quoteId(now, rand) {
    var n = now || new Date();
    var r = typeof rand === "function" ? rand : Math.random;
    var tail = r().toString(36).slice(2, 6).toUpperCase();
    while (tail.length < 4) tail += "0";
    return "SP-" + String(n.getFullYear()).slice(2) + pad2(n.getMonth() + 1) + pad2(n.getDate()) + "-" + tail;
  }

  /** The same seed shows the same id across re-renders, with no stored state. */
  function quoteIdFor(seed, now) {
    var h = 0x811c9dc5;
    for (var i = 0; i < seed.length; i++) {
      h ^= seed.charCodeAt(i);
      h = Math.imul(h, 0x01000193) >>> 0;
    }
    var tail = (h % 1679616).toString(36).toUpperCase();
    while (tail.length < 4) tail = "0" + tail;
    return (
      quoteId(now || new Date(), function () {
        return 0;
      }).slice(0, -4) + tail
    );
  }

  var MARKS = /[̀-ͯ]/g;

  /** Lower-case, accent-free key used to match destination names across sheets. */
  function nameKey(name) {
    return String(name == null ? "" : name)
      .normalize("NFD")
      .replace(MARKS, "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, " ")
      .trim();
  }

  /** URL/ID-safe slug, ASCII only, at most 40 characters. */
  function slug(name) {
    return (
      String(name)
        .toLowerCase()
        .normalize("NFD")
        .replace(MARKS, "")
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-|-$/g, "")
        .slice(0, 40) || "dest"
    );
  }

  /* ------------------------------------------------------- quote messages */

  var LB = 0.45359237;
  var IN = 2.54;

  function f2(v) {
    return String(Math.round(v * 100) / 100);
  }

  /** "2 x 1 kg (30x20x10 cm); 1 x 0.5 kg" in the customer's units. */
  function piecesText(w, units) {
    var imp = units === "imperial";
    var out = [];
    for (var i = 0; i < w.lines.length; i++) {
      var l = w.lines[i];
      var kg = imp ? f2(l.kg / LB) + " lb" : f2(l.kg) + " kg";
      var dims = "";
      if (l.volG) {
        dims = imp
          ? " (" + f2(l.L / IN) + "×" + f2(l.W / IN) + "×" + f2(l.H / IN) + " in)"
          : " (" + f2(l.L) + "×" + f2(l.W) + "×" + f2(l.H) + " cm)";
      }
      out.push(l.qty + " × " + kg + dims);
    }
    return out.join("; ");
  }

  /** One sentence naming the weight the price is charged on, and why. */
  function weightSentence(w, settings) {
    var txt = "Charged on " + gToKg(w.billableG) + " kg" + (w.pieces > 1 ? " for " + w.pieces + " pieces" : "");

    if (w.volumetricWins) {
      return txt + " - volumetric weight (" + gToKg(w.chargeG) + " kg) is higher than actual (" + gToKg(w.actualG) + " kg).";
    }

    if (w.billableG !== w.chargeG) {
      var eff = effectiveSteps(settings);
      var stepG = kgToG(eff.stepKg);
      var byMinimum = Math.ceil(w.chargeG / stepG) * stepG < kgToG(eff.firstKg);
      return (
        txt +
        " (" +
        gToKg(w.actualG) +
        " kg, " +
        (byMinimum ? "minimum " + eff.firstKg + " kg" : "rounded up to the next " + eff.stepKg + " kg") +
        ")."
      );
    }

    return txt + ".";
  }

  /** "Pickup and service charges" reads on mid-sentence; "COD fee" keeps its capitals. */
  function midSentence(s) {
    return s.replace(/^[A-Z](?=[a-z])/, function (c) {
      return c.toLowerCase();
    });
  }

  /** Shipping plus every add-on that was on - the one number the customer booked. */
  function bookedTotal(shipping, addons) {
    var t = shipping;
    for (var i = 0; i < addons.length; i++) t += addons[i].amount;
    return t;
  }

  /** Add-on amounts always come from the document, never from the browser's copy. */
  function resolveAddons(settings, labels) {
    if (!labels || !labels.length) return [];
    var all = addonsList(settings);
    var out = [];
    for (var i = 0; i < all.length; i++) {
      if (labels.indexOf(all[i].label) >= 0) out.push(all[i]);
    }
    return out;
  }

  function quoteText(q, ctx) {
    var out = ["Hi " + ctx.companyName + ", I want to book a shipment.", "Quote: " + q.id];
    if (q.from) out.push("From: " + q.from);
    out.push("To: " + q.dest);
    out.push("Service: " + q.service + (q.days ? " (" + q.days + " working days)" : ""));
    if (q.pickup) out.push("Pickup: " + q.pickup);
    if (q.eta) out.push("Estimated delivery: " + q.eta);
    // One label, because there is one kind of shipment since 2026-09-27.
    out.push("Packages: " + q.piecesText);
    out.push("Charged on: " + gToKg(q.billableG) + " kg");

    var addons = ctx.addons || [];
    if (addons.length) {
      out.push("Shipping: " + fmtMoney(q.total, ctx.currency));
      for (var i = 0; i < addons.length; i++) out.push(addons[i].label + ": " + fmtMoney(addons[i].amount, ctx.currency));
      out.push("Total: " + fmtMoney(bookedTotal(q.total, addons), ctx.currency));
    } else {
      out.push("Price: " + fmtMoney(q.total, ctx.currency));
    }

    var c = ctx.contents ? String(ctx.contents).trim() : "";
    if (c) out.push("Contents: " + c);
    return out.join("\n");
  }

  /** The owner's inbox line, carrying the same Total the customer's message does. */
  function leadSummary(i) {
    var addons = i.addons || [];
    var inside = "";
    if (addons.length) {
      var bits = [];
      for (var n = 0; n < addons.length; n++) bits.push(fmtMoney(addons[n].amount, i.currency) + " " + midSentence(addons[n].label));
      inside = " (includes " + bits.join(", ") + ")";
    }
    var s =
      i.service +
      " to " +
      i.dest +
      " · " +
      gToKg(i.billableG) +
      " kg · " +
      fmtMoney(bookedTotal(i.shipping, addons), i.currency) +
      inside;
    if (i.piecesText) s += " · " + i.piecesText;
    return s;
  }

  function waLink(whatsapp, text) {
    return "https://wa.me/" + whatsapp + "?text=" + encodeURIComponent(text);
  }

  /** The message for a shipment above the cargo threshold. */
  function cargoText(companyName, from, dest, w, units) {
    return (
      "Hi " +
      companyName +
      ", I need a cargo rate." +
      (from ? "\nFrom: " + from : "") +
      "\nTo: " +
      dest +
      "\nPieces: " +
      piecesText(w, units) +
      "\nChargeable: about " +
      gToKg(w.billableG) +
      " kg"
    );
  }

  /** The hold form's message, while prices are off the site. */
  function holdText(companyName, dest, kg, contents) {
    var out = ["Hi " + companyName + ", please price a parcel for me."];
    if (dest) out.push("To: " + dest);
    if (kg) out.push("Weight: about " + kg + " kg");
    var c = String(contents == null ? "" : contents).trim();
    if (c) out.push("Contents: " + c);
    return out.join("\n");
  }

  /* -------------------------------------------- publishing rules (admin) */

  function sortedGridKeys(grid) {
    return Object.keys(grid || {}).sort(function (a, b) {
      return Number(a) - Number(b);
    });
  }

  /** Blocking problems. An empty list means the draft may be published. */
  function validateSite(s) {
    var errs = [];
    var st = s.settings;

    if (!String((s.company && s.company.name) || "").trim()) errs.push("Company name is empty.");
    if (!/^[0-9]{8,15}$/.test(String((s.company && s.company.whatsapp) || "")))
      errs.push("WhatsApp number must be 8–15 digits with the country code, e.g. 923001234567.");
    if (!(st.volumetricDivisor >= 1000 && st.volumetricDivisor <= 10000)) errs.push("Volumetric divisor must be between 1000 and 10000.");
    if (!(st.firstKg > 0)) errs.push("First weight slab must be greater than 0.");
    if (!(st.stepKg > 0)) errs.push("Additional weight step must be greater than 0.");
    if (!(st.taxPct >= 0 && st.taxPct <= 100)) errs.push("Tax must be between 0 and 100%.");
    if (!(st.roundTo >= 1)) errs.push("Rounding must be 1 or more.");
    if (!(st.maxKg >= 0)) errs.push("Cargo threshold must be 0 or more.");

    var addonLines = lines(st.addons);
    for (var i = 0; i < addonLines.length; i++) {
      var p = parts(addonLines[i], 3);
      var amt = toNumLoose(p[1]);
      if (!p[0] || !isNum(amt) || amt < 0)
        errs.push("Optional charge “" + addonLines[i] + "” must be written as Label | Amount | on/off with a positive amount.");
    }

    if (st.cutoffHour != null && !(st.cutoffHour >= 0 && st.cutoffHour <= 23)) errs.push("Cutoff hour must be between 0 and 23, or blank.");
    if (String(st.workingDays == null ? "" : st.workingDays).trim() && !/mon|tue|wed|thu|fri|sat|sun/i.test(String(st.workingDays)))
      errs.push("Working days must be day names such as Mon, Tue, Wed.");
    if (String(st.workingDays == null ? "" : st.workingDays).trim() && !workingSet(st).length)
      errs.push("Working days must be day names such as Mon, Tue, Wed.");
    if (!String(st.currency == null ? "" : st.currency).trim() || String(st.currency).length > 8)
      errs.push("Currency label must be 1–8 characters, such as PKR or Rs.");

    var bad = badHolidayLines(st).slice(0, 5);
    for (var b = 0; b < bad.length; b++)
      errs.push("Holiday “" + bad[b] + "” must be written as YYYY-MM-DD, optionally followed by | a name.");

    if (!s.services.length) errs.push("At least one service is needed.");

    var grid = st.pricingMode === "grid";
    if (grid && !(st.maxKg >= 1))
      errs.push("Grid pricing needs a cargo threshold of at least 1 kg (Settings), which sets the last kilogram box.");

    var seen = {};
    var activeCount = 0;

    for (var d = 0; d < s.destinations.length; d++) {
      var x = s.destinations[d];
      var nm = String(x.name == null ? "" : x.name).trim();

      if (!nm) {
        errs.push("A destination has no name.");
        continue;
      }

      var key = nameKey(nm);
      if (seen[key]) errs.push("“" + nm + "” appears twice.");
      seen[key] = true;

      if (!x.active) continue;
      activeCount++;

      var priced = 0;

      for (var v = 0; v < s.services.length; v++) {
        var sv = s.services[v];
        var r = x.rates ? x.rates[sv.id] : null;
        if (!r) continue;

        var label = nm + " · " + sv.name;

        if (grid) {
          var boxKeys = [];
          var gk = sortedGridKeys(r.grid);
          for (var z = 0; z < gk.length; z++) if (r.grid[gk[z]] != null) boxKeys.push(gk[z]);

          if (!boxKeys.length) continue;

          for (var y = 0; y < boxKeys.length; y++) {
            var bv = r.grid[boxKeys[y]];
            if (!(isNum(bv) && bv > 0)) errs.push(label + " " + boxKeys[y] + " kg: price must be greater than 0 or left blank.");
          }

          priced++;
          continue;
        }

        if (r.first == null && r.addl == null) continue;

        if (!(isNum(r.first) && r.first > 0)) errs.push(label + ": first-slab price must be greater than 0.");
        if (!(isNum(r.addl) && r.addl >= 0)) errs.push(label + ": per-step price is missing.");
        priced++;
      }

      if (!priced) errs.push(nm + " is shown on the site but has no prices.");
    }

    if (!activeCount) errs.push("At least one destination must be shown on the site.");
    return errs;
  }

  /** Non-blocking oddities worth a second look before publishing. */
  function warnSite(s) {
    var w = [];
    var cur = s.settings.currency;
    var grid = s.settings.pricingMode === "grid";
    var ex = s.services[0];
    var no = s.services[1];

    var firstOf = function (q) {
      if (!q) return null;
      if (grid) {
        var ks = sortedGridKeys(q.grid);
        if (!ks.length) return null;
        var v = q.grid[ks[0]];
        return v == null ? null : v;
      }
      return isNum(q.first) ? q.first : null;
    };

    for (var d = 0; d < s.destinations.length; d++) {
      var x = s.destinations[d];
      if (!x.active) continue;
      var r = x.rates || {};

      for (var i = 0; i < s.services.length; i++) {
        var sv = s.services[i];
        var q = r[sv.id];
        if (!q) continue;
        var label = x.name + " · " + sv.name;

        if (!grid && isNum(q.first) && isNum(q.addl) && q.addl > q.first)
          w.push(label + ": the per-step price (" + fmtMoney(q.addl, cur) + ") is higher than the first slab (" + fmtMoney(q.first, cur) + ").");

        if (grid && q.grid) {
          var ks = sortedGridKeys(q.grid);
          for (var k = 1; k < ks.length; k++) {
            var prev = q.grid[ks[k - 1]];
            var here = q.grid[ks[k]];
            if (isNum(prev) && isNum(here) && here < prev) {
              w.push(label + ": " + ks[k] + " kg (" + fmtMoney(here, cur) + ") is cheaper than " + ks[k - 1] + " kg (" + fmtMoney(prev, cur) + ").");
              break;
            }
          }

          var all = gridWeights(s.settings);
          var priced = [];
          var missing = [];
          for (var g = 0; g < all.length; g++) {
            var pv = q.grid[String(all[g])];
            if (isNum(pv) && pv > 0) priced.push(all[g]);
            else missing.push(all[g]);
          }

          if (missing.length && priced.length) {
            var top = priced[priced.length - 1];
            var gaps = [];
            var above = [];
            for (var m = 0; m < missing.length; m++) {
              if (missing[m] < top) gaps.push(missing[m]);
              else if (missing[m] > top) above.push(missing[m]);
            }

            if (gaps.length)
              w.push(
                label +
                  ": no price for " +
                  gaps.length +
                  " weight" +
                  (gaps.length === 1 ? "" : "s") +
                  " (" +
                  gaps.slice(0, 6).join(", ") +
                  (gaps.length > 6 ? "…" : "") +
                  " kg) - those parcels are charged at the next heavier priced kg."
              );

            if (above.length)
              w.push(
                label +
                  ": no price above " +
                  top +
                  " kg - parcels of " +
                  (above.length === 1 ? above[0] + " kg" : above[0] + "–" + above[above.length - 1] + " kg") +
                  " get no price on the site and are sent to WhatsApp."
              );
          }
        }
      }

      if (ex && no) {
        var a = firstOf(r[ex.id]);
        var b = firstOf(r[no.id]);
        if (a != null && b != null && a < b)
          w.push(x.name + ": " + ex.name + " is cheaper than " + no.name + " (" + fmtMoney(a, cur) + " vs " + fmtMoney(b, cur) + ").");
      }

      for (var t = 0; t < s.services.length; t++) {
        var qt = r[s.services[t].id];
        if (qt && firstOf(qt) != null && !qt.days)
          w.push(x.name + " · " + s.services[t].name + ": no transit days, so no delivery date can be shown.");
      }

    }

    return w;
  }

  /* ----------------------------------------------------------- the global */

  root.SpeedatPricing = {
    // numbers
    isNum: isNum,
    kgToG: kgToG,
    gToKg: gToKg,
    roundTo: roundTo,
    fmtNum: fmtNum,
    fmtMoney: fmtMoney,
    // rate cards
    isGrid: isGrid,
    gridWeights: gridWeights,
    effectiveSteps: effectiveSteps,
    gridPrice: gridPrice,
    computeWeights: computeWeights,
    priceService: priceService,
    priceAll: priceAll,
    slabToGrid: slabToGrid,
    // settings text
    lines: lines,
    parts: parts,
    toNumLoose: toNumLoose,
    addonsList: addonsList,
    // dates
    parseDaysRange: parseDaysRange,
    parseDays: parseDays,
    workingSet: workingSet,
    holidaySet: holidaySet,
    badHolidayLines: badHolidayLines,
    addDays: addDays,
    estimateDelivery: estimateDelivery,
    // formatting
    fmtDay: fmtDay,
    fmtRange: fmtRange,
    fmtDate: fmtDate,
    fmtDateTime: fmtDateTime,
    fmtPhone: fmtPhone,
    fmtHour: fmtHour,
    inputDate: inputDate,
    localDateFromInput: localDateFromInput,
    quoteId: quoteId,
    quoteIdFor: quoteIdFor,
    nameKey: nameKey,
    slug: slug,
    // messages
    LB: LB,
    IN: IN,
    piecesText: piecesText,
    weightSentence: weightSentence,
    midSentence: midSentence,
    bookedTotal: bookedTotal,
    resolveAddons: resolveAddons,
    quoteText: quoteText,
    leadSummary: leadSummary,
    waLink: waLink,
    cargoText: cargoText,
    holdText: holdText,
    // publishing rules
    validateSite: validateSite,
    warnSite: warnSite
  };
})(typeof window !== "undefined" ? window : this);
