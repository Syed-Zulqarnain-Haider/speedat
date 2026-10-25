"use strict";

/**
 * The walks themselves: the tap sequences a customer makes, written once so the
 * desktop and phone flows cannot drift apart, and so a change to the markup is
 * one edit rather than twelve.
 *
 * The selectors here are the ones public/assets/js/calculator.js builds, which
 * are the ones src/components/calculator built before it - the CSS is
 * byte-identical to src/styles, so the class names have to be too. If a flow
 * suddenly cannot find a tile, suspect the view, not this file.
 *
 *   [data-calculator]   the mount PHP prints, with the site JSON in its value
 *   button.ctile        a country tile (.ctile-name holds the name)
 *   button.kg           a weight tile (.more = "Over N kg", .other = box size)
 *   div.result          the quick panel's answer block, carrying data-state
 *   .pricebar .tval     the quick panel's price. The largest text on the page.
 *   a.btn.book          the green WhatsApp button
 *   div.results         the detailed panel's "3 Choose a service" block
 *   div.quote           the detailed panel's answer card, which appears only
 *                       AFTER a service is chosen; dd.display holds the price
 */

const { NotReady } = require("./harness.cjs");

const SEL = {
  mount: "[data-calculator]",
  holdMount: "[data-hold-panel]",
  countryTile: "button.ctile",
  countryName: ".ctile-name",
  countryPicker: "select.ctile-more",
  weightTile: "button.kg:not(.more):not(.other)",
  weightMore: "button.kg.more",
  weightOther: "button.kg.other",
  result: "div.result",
  results: "div.results",
  price: ".pricebar .tval",
  priceNote: ".pricebar .tnote",
  pricebar: ".pricebar",
  book: "a.btn.book",
  serviceRadio: "fieldset.opts input[type=radio]",
  serviceRow: "fieldset.opts label.opt",
  wait: "p.wait",
  quoteUrl: "/api/quotes",

  /* ---- the detailed panel --------------------------------------------
   *
   * `page.$("select")` used to stand in for "the destination". It is not: the
   * detailed panel prints TWO selects and "From" (the pickup city) comes
   * first in the DOM, so every detailed-quote check was trying to choose
   * "United Kingdom" in a list that holds Lahore and Faisalabad. Playwright
   * waited thirty seconds and reported "did not find some options", which
   * reads exactly like a broken product and was nothing of the kind - and the
   * four checks behind it then failed on a panel that had never been filled
   * in. Neither select carries a class, so the destination is found by what
   * is IN it (chooseDestination below), not by its position.
   */
  detailSelect: "select:visible",
  detailPiece: "div.piece",
  detailKg: "div.piece input[type=number]",
  detailUnits: "button.ubtn",
  detailAddPiece: "button.btn.addpiece",
  detailRemovePiece: "button.btn.p-remove",
  detailBack: "button.btn.outline.back",
  serviceCard: "button.svc",
  serviceName: ".svc-name",
  servicePrice: ".svc-price",
  /* The detailed panel's own price. NOT `.tval` - that is the quick panel's
   * price bar, which the detailed view does not render at all. */
  detailPrice: ".quote dd.display",
  detailCard: "div.quote",
  detailContents: ".quote input[type=text][maxlength]",
  /* The quote card's OWN WhatsApp link. The page carries other wa.me links -
   * the header, the footer, the rates board - and they are plain "message us"
   * links with no ?text=. Reading the last visible one on the page found one
   * of those and reported the detailed quote as carrying no message at all. */
  detailBook: ".quote a.btn.book",
  detailCopy: ".quote button.btn.outline",
};

/**
 * Is the calculator on the page at all?
 *
 * Three things have to be true before any quick-rate check means anything: the
 * mount is printed, the pricing engine is loaded, and the tiles got built. When
 * one is missing this is NOT READY and it says which - a flow that reported
 * "no price appeared" while the page was still a placeholder would send
 * somebody hunting a bug that does not exist.
 */
async function requireCalculator(t) {
  const page = t.page;

  const mount = await page.$(SEL.mount);
  if (!mount) {
    throw new NotReady(
      "no " + SEL.mount + " on this page - the calculator view (Views/site) has not shipped; the page is still the placeholder",
    );
  }

  const engine = await page.evaluate(() => ({
    pricing: typeof window.SpeedatPricing === "object" && window.SpeedatPricing !== null,
    priceAll: !!(window.SpeedatPricing && typeof window.SpeedatPricing.priceAll === "function"),
  }));
  if (!engine.priceAll) {
    throw new NotReady("window.SpeedatPricing.priceAll is missing - pricing.js is not loaded, so the calculator left the no-script fallback in place");
  }

  await page.waitForSelector(SEL.countryTile, { timeout: 5000 }).catch(() => {});
  const tiles = await page.$$(SEL.countryTile);
  if (!tiles.length) {
    throw new NotReady("the calculator mounted but built no " + SEL.countryTile + " - the site JSON in the mount has no active destinations");
  }

  return { tiles: tiles.length };
}

/** The names on the country tiles, in the order a visitor sees them. */
async function countryNames(page) {
  return page.evaluate(
    ({ tile, name }) =>
      Array.from(document.querySelectorAll(tile)).map((el) => {
        const n = el.querySelector(name);
        return (n ? n.textContent : el.textContent || "").replace(/\s+/g, " ").trim();
      }),
    { tile: SEL.countryTile, name: SEL.countryName },
  );
}

/**
 * The quick rate, tap by tap, measured.
 *
 * Returns everything the assertions need: the numbers from measure() for the
 * tap that produced the price, the price text, the service the site chose, the
 * booking href, and the tap count. The taps are counted because "three taps:
 * country, weight, the price" is the product, and a fourth tap appearing in
 * this sequence is a regression whether or not anything else still works.
 */
async function quickRateToPrice(t, { country = null, kg = null } = {}) {
  const page = t.page;
  await requireCalculator(t);

  const names = await countryNames(page);
  if (!names.length) throw new NotReady("no country tiles to tap");

  const wantCountry = country && names.includes(country) ? country : names[0];
  let taps = 0;

  /* ---- tap one: the country --------------------------------------- */

  await page.locator(SEL.countryTile, { hasText: wantCountry }).first().click();
  taps += 1;
  await page.waitForTimeout(250);

  const afterCountry = await page.getAttribute(SEL.result, "data-state");
  if (afterCountry === "live") {
    throw new Error("a price appeared after only the country was tapped - no weight was chosen, so it is a guess, not a quote");
  }

  const weights = await page.evaluate(
    (sel) => Array.from(document.querySelectorAll(sel)).map((el) => (el.textContent || "").replace(/\s+/g, " ").trim()),
    SEL.weightTile,
  );
  if (!weights.length) throw new NotReady("the country was tapped but no weight tiles appeared");

  /* ---- instrument, then tap two: the weight ----------------------- */

  const wantKg = kg && weights.some((w) => w.replace(/\s+/g, "").startsWith(String(kg))) ? String(kg) : null;
  const weightLocator = wantKg
    ? page.locator(SEL.weightTile, { hasText: new RegExp("^\\s*" + wantKg + "\\s*kg\\s*$") }).first()
    : page.locator(SEL.weightTile).nth(Math.min(1, weights.length - 1));

  const measured = await t.measure(SEL.price);
  await weightLocator.click();
  taps += 1;
  const m = await measured.settle({ timeout: 6000 });

  const state = await page.getAttribute(SEL.result, "data-state");
  if (state !== "live") {
    const note = await page.textContent(SEL.priceNote).catch(() => "");
    if (state === "none" || state === "cargo") {
      throw new NotReady(
        "two taps produced no price because the rates say so, not because the page is broken: data-state=" +
          state +
          (note ? ' ("' + note.trim() + '")' : "") +
          ". Publish a rate for " + wantCountry + " and run this again.",
      );
    }
    throw new Error("after two taps data-state is " + JSON.stringify(state) + ", expected \"live\"" + (note ? ' - the page says "' + note.trim() + '"' : ""));
  }

  /* ---- what the visitor is now looking at ------------------------- */

  const shown = await page.evaluate(
    (sel) => {
      const price = document.querySelector(sel.price);
      const book = document.querySelector(sel.book);
      const chosen = document.querySelector(sel.serviceRadio + ":checked");
      const row = chosen ? chosen.closest("label.opt") : null;
      const services = Array.from(document.querySelectorAll(sel.serviceRow)).map((r) => ({
        name: (r.querySelector(".opt-name") || {}).textContent || "",
        eta: ((r.querySelector(".opt-eta") || {}).textContent || "").trim(),
        price: ((r.querySelector(".opt-price") || {}).textContent || "").trim(),
      }));
      return {
        price: price ? (price.textContent || "").replace(/\s+/g, " ").trim() : null,
        note: ((document.querySelector(sel.priceNote) || {}).textContent || "").replace(/\s+/g, " ").trim(),
        bookHref: book ? book.getAttribute("href") : null,
        bookLabel: book ? (book.textContent || "").replace(/\s+/g, " ").trim() : null,
        service: row ? ((row.querySelector(".opt-name") || {}).textContent || "").trim() : "",
        services,
        lines: Array.from(document.querySelectorAll(".lines .twhen, .lines .tline")).map((s) => (s.textContent || "").trim()),
      };
    },
    { price: SEL.price, book: SEL.book, serviceRadio: SEL.serviceRadio, serviceRow: SEL.serviceRow, priceNote: SEL.priceNote },
  );

  if (!shown.price) throw new Error("data-state is \"live\" but " + SEL.price + " holds no text");

  t.log("two taps: " + wantCountry + " + " + (wantKg || weights[Math.min(1, weights.length - 1)]) + " → " + shown.price);

  return { ...shown, measure: m, taps, country: wantCountry, kg: wantKg || weights[Math.min(1, weights.length - 1)].replace(/[^0-9.]/g, "") };
}

/**
 * Switch from the quick price to the detailed quote.
 *
 * The way in is the "Box size · many pieces" tile (button.kg.other), which is
 * also the way somebody sending several parcels gets there - the two are the
 * same door on purpose.
 */
async function openDetailed(t) {
  const page = t.page;
  await requireCalculator(t);

  const other = await page.$(SEL.weightOther);
  if (other) {
    await other.click();
  } else {
    const link = page
      .locator("button, a")
      .filter({ hasText: /detailed|exact|box size|many pieces|more option/i })
      .first();
    if (!(await link.count())) {
      throw new NotReady("no way into the detailed quote on this page (no " + SEL.weightOther + " and no link that says so)");
    }
    await link.click();
  }

  await page.waitForTimeout(400);
  const panel = await page.$(SEL.results + ", " + SEL.result);
  if (!panel) throw new NotReady("tapping through did not open a detailed panel");
  const back = await page.$(SEL.detailBack);
  if (!back) {
    throw new NotReady("the detailed panel has no \"Back to quick price\" button - the detailed view has not shipped");
  }
  return true;
}

/**
 * Choose a destination in the detailed panel, by what is in the control rather
 * than by where it sits.
 *
 * The panel prints two selects and "From" comes first, so "the first select"
 * is the pickup city. It is also the wrong thing to hard-code the other way
 * round: neither carries a class, and `label.field:has(span.lab) select` only
 * works until somebody labels the From field the same way. So: look at every
 * visible select, take the one that actually offers this country, and if none
 * does, say what each one DID offer. That message is the difference between
 * "the site is broken" and "the site moved a control".
 */
async function chooseDestination(t, name) {
  const page = t.page;
  const selects = page.locator(SEL.detailSelect);
  const count = await selects.count();

  if (!count) throw new NotReady("the detailed panel shows no destination control");

  const offered = [];
  for (let i = 0; i < count; i += 1) {
    const select = selects.nth(i);
    // eslint-disable-next-line no-await-in-loop
    const options = (await select.locator("option").allTextContents()).map((o) => o.replace(/\s+/g, " ").trim());
    offered.push(options.slice(0, 4).join(" / ") + (options.length > 4 ? " …" : ""));

    if (options.includes(name)) {
      // eslint-disable-next-line no-await-in-loop
      await select.selectOption({ label: name });
      // eslint-disable-next-line no-await-in-loop
      await page.waitForTimeout(250);
      return { index: i, of: count };
    }
  }

  throw new Error(
    [
      'none of the ' + count + " visible select(s) in the detailed panel offers " + JSON.stringify(name) + ":",
      ...offered.map((o, i) => "    select " + i + ": " + o),
    ].join("\n"),
  );
}

/**
 * Press a service card and wait for the quote card it builds.
 *
 * Changing the shipment - a piece added, a weight edited, the units switched -
 * takes the quote card away again and leaves the three service cards showing
 * the NEW prices. That is the panel asking "still this one?", and it is what a
 * customer does: change something, look at the three prices, press one. So a
 * check that changes the shipment has to press again before there is a price
 * to read, exactly as a person would.
 *
 * @param {number|string} service a card index, or a service name
 */
async function chooseService(t, service = 0) {
  const page = t.page;

  await page.waitForSelector(SEL.serviceCard, { timeout: 6000 }).catch(() => {});
  const cards = page.locator(SEL.serviceCard);
  const offered = await cards.count();
  if (!offered) throw new Error("the detailed panel is showing no service cards to choose from");

  const names = (await cards.locator(SEL.serviceName).allTextContents()).map((n) =>
    n.replace(/\s+/g, " ").trim().replace(/\s*(Choose|Chosen)$/i, ""),
  );

  let index;
  if (typeof service === "number") {
    index = Math.min(service, offered - 1);
  } else {
    index = names.findIndex((n) => n.toLowerCase() === String(service).toLowerCase());
    if (index < 0) {
      throw new Error("the panel no longer offers " + JSON.stringify(String(service)) + " - it offers: " + names.join(", "));
    }
  }

  await cards.nth(index).click();
  await page.waitForSelector(SEL.detailCard, { timeout: 6000 }).catch(() => {});

  return { price: await detailedPrice(page), service: names[index] || "", services: names };
}

/**
 * One piece row's WEIGHT box.
 *
 * A piece row holds five number inputs - kg, quantity, then L, W and H - so
 * `div.piece input[type=number]` is five boxes per row, and "the last one" is
 * the height of the last piece. The check that added a piece and typed 3 into
 * "the last number input" was setting a height with no length or width, which
 * changes no price, and then reported that adding a piece does not change the
 * price. The weight is the FIRST number box in the row, and this is the one
 * place that knows it.
 *
 * @param {number} index 0-based, or negative to count back from the last row
 */
function pieceWeightInput(page, index = 0) {
  const rows = page.locator(SEL.detailPiece);
  const row = index < 0 ? rows.last() : rows.nth(index);

  return row.locator('input[type="number"]').first();
}

/** The detailed panel's price, as text, or "" before a service is chosen. */
async function detailedPrice(page) {
  return page.evaluate(
    (sel) => {
      const el = document.querySelector(sel);
      return el ? (el.textContent || "").replace(/\s+/g, " ").trim() : "";
    },
    SEL.detailPrice,
  );
}

/**
 * Fill the detailed panel in and choose a service, which is what makes the
 * quote card - price, contents field, Copy, WhatsApp link - exist at all.
 *
 * THE MISSING TAP. Until a service card is pressed the panel shows three
 * prices to choose BETWEEN and no quote; `div.quote` is not hidden, it has not
 * been built. Every check that read ".results .tval" was reading a selector
 * that has never existed in this markup, on a card that had not been created,
 * after a destination that had not been chosen. Three layers of nothing, one
 * failure message about a price.
 */
async function detailedQuoteToPrice(t, { country, kg = "5", service = 0 } = {}) {
  const page = t.page;

  const where = await chooseDestination(t, country);

  const kgInput = pieceWeightInput(page, 0);
  if (!(await kgInput.count())) throw new NotReady("no weight input in the piece row");
  await kgInput.fill(String(kg));
  await kgInput.dispatchEvent("input");

  await page.waitForSelector(SEL.serviceCard, { timeout: 6000 }).catch(() => {});
  if (!(await page.locator(SEL.serviceCard).count())) {
    throw new NotReady(
      "no service cards for " + country + " at " + kg + " kg - either no rate is published for it or the panel is not wired up",
    );
  }

  const chosen = await chooseService(t, service);
  if (!chosen.price) {
    throw new Error(
      "a service was chosen and no quote card appeared (" + SEL.detailPrice + " is empty) for " + country + " at " + kg + " kg",
    );
  }

  t.log(
    "detailed: " + country + " at " + kg + " kg, " + (chosen.service || "the first service") +
      ", through select " + where.index + " of " + where.of + " → " + chosen.price,
  );

  return chosen;
}

/**
 * Capture the POST the calculator makes to /api/quotes.
 *
 * Install before the tap that books. Returns a reader that waits for the
 * request and hands back the body the browser sent and the answer it got.
 */
function watchQuoteLog(t, { url = SEL.quoteUrl } = {}) {
  const seen = [];
  const page = t.page;

  const onRequest = (req) => {
    if (req.method() === "POST" && req.url().includes(url)) {
      let body = null;
      try {
        body = JSON.parse(req.postData() || "null");
      } catch (e) {
        body = { unparseable: String(req.postData() || "").slice(0, 200) };
      }
      seen.push({ body, url: req.url(), at: Date.now() });
    }
  };
  page.on("request", onRequest);

  return {
    async wait({ timeout = 4000 } = {}) {
      const until = Date.now() + timeout;
      while (!seen.length && Date.now() < until) {
        // eslint-disable-next-line no-await-in-loop
        await page.waitForTimeout(100);
      }
      page.off("request", onRequest);
      return seen;
    },
    stop() {
      page.off("request", onRequest);
      return seen;
    },
  };
}

module.exports = {
  SEL,
  requireCalculator,
  countryNames,
  quickRateToPrice,
  openDetailed,
  chooseDestination,
  chooseService,
  pieceWeightInput,
  detailedPrice,
  detailedQuoteToPrice,
  watchQuoteLog,
};
