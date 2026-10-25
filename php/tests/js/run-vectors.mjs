/**
 * The browser engine's half of the parity suite.
 *
 *   cd php && node tests/js/run-vectors.mjs
 *
 * It loads public/assets/js/pricing.js the way a browser would - as a classic
 * script, into a context whose only global is `window` - and runs every vector
 * in tests/vectors/pricing.json through it. bin/test.php shells out to this
 * after the PHP half, so `php bin/test.php` checks both engines at once.
 *
 * The vectors were generated in Asia/Karachi and two of the formatters print a
 * wall-clock date, so the runner pins the zone before it touches a Date.
 */
process.env.TZ = "Asia/Karachi";

import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import vm from "node:vm";

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, "..", "..");

/* ------------------------------------------------------------- load the two files */

const vectorsFile = join(root, "tests", "vectors", "pricing.json");
const engineFile = join(root, "public", "assets", "js", "pricing.js");

let data;
try {
  data = JSON.parse(readFileSync(vectorsFile, "utf8"));
} catch (e) {
  console.error(`Cannot read ${vectorsFile}: ${e.message}`);
  console.error("Regenerate it - see php/docs/engine-parity.md.");
  process.exit(2);
}

const sandbox = { window: {}, console };
vm.createContext(sandbox);
try {
  vm.runInContext(readFileSync(engineFile, "utf8"), sandbox, { filename: "pricing.js" });
} catch (e) {
  console.error(`pricing.js did not load: ${e.message}`);
  process.exit(2);
}

const P = sandbox.window.SpeedatPricing;
if (!P) {
  console.error("pricing.js loaded but did not define window.SpeedatPricing.");
  process.exit(2);
}

// The generated vectors were produced in Asia/Karachi. A different zone would
// silently shift two of the date formatters, so refuse rather than mislead.
if (new Date(2026, 8, 19).getTimezoneOffset() !== -300) {
  console.error(`Run this with TZ=${data.timezone}: the process is in another zone.`);
  process.exit(2);
}

/* ------------------------------------------------------------------- helpers */

const num = (v) => (typeof v === "number" && Number.isFinite(v) ? v : null);

const site = (name) => {
  const s = data.sites[name];
  if (!s) throw new Error(`the vector file has no site called "${name}"`);
  return s;
};

const card = (name) => {
  const s = site(name);
  return { services: s.services, destinations: s.destinations, settings: s.settings };
};

const settings = (name) => site(name).settings;

const serWeights = (w) => ({
  pieces: w.pieces,
  actualG: w.actualG,
  volG: w.volG,
  chargeG: w.chargeG,
  billableG: w.billableG,
  volumetricWins: w.volumetricWins,
  lines: w.lines.map((l) => ({
    qty: l.qty,
    kg: num(l.kg),
    L: num(l.L),
    W: num(l.W),
    H: num(l.H),
    actualG: l.actualG,
    volG: l.volG,
    pieceG: l.pieceG,
    volumetricWins: l.volumetricWins
  }))
});

const serPrice = (p) =>
  p
    ? {
        first: num(p.first),
        addl: num(p.addl),
        steps: p.steps,
        gridKg: num(p.gridKg),
        base: p.base,
        tax: p.tax,
        total: p.total,
        days: p.days
      }
    : null;

const serResult = (r) => {
  if (!r.ok) {
    return r.reason === "overmax"
      ? { ok: false, reason: r.reason, destId: r.dest.id, weights: serWeights(r.weights) }
      : { ok: false, reason: r.reason };
  }
  const prices = {};
  for (const key of Object.keys(r.prices)) prices[key] = serPrice(r.prices[key] ?? null);
  return { ok: true, destId: r.dest.id, weights: serWeights(r.weights), prices };
};

const dateOf = (iso) => (iso ? P.localDateFromInput(iso) : null);
const ymd = (d) => P.inputDate(d);

/** First difference between an expected JSON value and what the engine produced. */
function difference(expected, actual, path) {
  const where = path ? ` at ${path}` : "";

  if (Array.isArray(expected) || (expected && typeof expected === "object")) {
    if (!actual || typeof actual !== "object") return `expected an object${where}, got ${JSON.stringify(actual)}`;
    if (Array.isArray(expected) !== Array.isArray(actual)) return `array/object mismatch${where}`;

    const ek = Object.keys(expected);
    const ak = Object.keys(actual);
    if (ek.length !== ak.length) return `expected ${ek.length} entries${where}, got ${ak.length}: ${JSON.stringify(actual)}`;

    for (const k of ek) {
      if (!(k in actual)) return `missing key "${k}"${where}`;
      const problem = difference(expected[k], actual[k], path ? `${path}.${k}` : k);
      if (problem) return problem;
    }
    return null;
  }

  if (typeof expected === "number") {
    if (typeof actual !== "number") return `expected the number ${expected}${where}, got ${JSON.stringify(actual)}`;
    if (Number.isNaN(actual)) return `expected ${expected}${where}, got NaN`;
    return expected === actual ? null : `expected ${expected}${where}, got ${actual}`;
  }

  // JSON has no undefined; the generator wrote null wherever the engine has one.
  const a = actual === undefined ? null : actual;
  return expected === a ? null : `expected ${JSON.stringify(expected)}${where}, got ${JSON.stringify(a)}`;
}

/* ------------------------------------------------------------------ the groups */

const handlers = {
  roundTo: (i) => P.roundTo(i.n, i.r),
  kgToG: (i) => P.kgToG(i.kg),
  gToKg: (i) => P.gToKg(i.g),

  toNumLoose: (i) => {
    const v = P.toNumLoose(i.v);
    return v === null ? { value: null } : Number.isNaN(v) ? { nan: true } : { value: v };
  },

  parts: (i) => P.parts(i.line, i.n),
  addonsList: (i) => P.addonsList({ addons: i.addons }),

  gridWeights: (i) => P.gridWeights({ maxKg: i.maxKg }),
  gridPrice: (i) => P.gridPrice(i.grid === null ? undefined : i.grid, i.kg, i.maxKg) ?? null,
  effectiveSteps: (i) => P.effectiveSteps(i.settings),
  slabToGrid: (i) => P.slabToGrid(settings(i.site), JSON.parse(JSON.stringify(i.rate))) ?? null,

  computeWeights: (i) => serWeights(P.computeWeights(settings(i.site), i.rows)),
  priceAll: (i) => serResult(P.priceAll(card(i.site), i.input)),

  // No shipment type and no chargeable grams: both were inputs only to the
  // document rate, which left the product on 2026-09-27 (owner). The vector
  // file has had those keys removed to match.
  priceService: (i) => {
    const c = card(i.site);
    const dest = c.destinations.find((d) => d.id === i.destId);
    if (!dest) throw new Error(`the vector names a destination the document does not have: ${i.destId}`);
    return serPrice(P.priceService(c.settings, dest, i.serviceId, i.billableG));
  },

  parseDaysRange: (i) => P.parseDaysRange(i.s),
  parseDays: (i) => P.parseDays(i.v),
  workingSet: (i) => P.workingSet({ workingDays: i.workingDays }),
  holidaySet: (i) => Object.keys(P.holidaySet({ holidays: i.holidays })).sort(),
  badHolidayLines: (i) => P.badHolidayLines({ holidays: i.holidays }),

  estimateDelivery: (i) => {
    const est = P.estimateDelivery(
      i.days,
      { workingDays: i.workingDays, cutoffHour: i.cutoffHour, holidays: i.holidays },
      dateOf(i.shipDate),
      new Date(i.now[0], i.now[1] - 1, i.now[2], i.now[3], i.now[4])
    );
    return est === null
      ? null
      : { pickup: ymd(est.pickup), from: ymd(est.from), to: ymd(est.to), moved: est.moved, range: est.range };
  },

  fmtNum: (i) => P.fmtNum(i.n),
  fmtMoney: (i) => P.fmtMoney(i.n, i.currency),
  fmtDay: (i) => P.fmtDay(new Date(i.y, i.m - 1, i.d)),
  fmtRange: (i) => P.fmtRange({ from: dateOf(i.from), to: dateOf(i.to), pickup: dateOf(i.from), moved: false, range: [1, 2] }),
  fmtDate: (i) => P.fmtDate(i.iso),
  fmtDateTime: (i) => P.fmtDateTime(i.iso),
  fmtPhone: (i) => P.fmtPhone(i.n),
  fmtHour: (i) => P.fmtHour(i.h),
  inputDate: (i) => P.inputDate(new Date(i.y, i.m - 1, i.d)),
  localDateFromInput: (i) => {
    const d = P.localDateFromInput(i.v);
    return d === null ? null : ymd(d);
  },

  quoteId: (i) => P.quoteId(new Date(i.y, i.m - 1, i.d), () => 0),
  quoteIdFor: (i) => P.quoteIdFor(i.seed, new Date(i.y, i.m - 1, i.d)),
  nameKey: (i) => P.nameKey(i.name),
  slug: (i) => P.slug(i.name),

  piecesText: (i) => P.piecesText(P.computeWeights(settings(i.site), i.rows), i.units),
  weightSentence: (i) => P.weightSentence(P.computeWeights(settings(i.site), i.rows), settings(i.site)),
  midSentence: (i) => P.midSentence(i.s),

  bookedTotal: (i) => P.bookedTotal(i.shipping, i.amounts.map((amount) => ({ amount }))),
  resolveAddons: (i) => P.resolveAddons({ addons: i.addons }, i.labels ?? undefined),

  waLink: (i) => P.waLink(i.whatsapp, i.text),

  quoteText: (i) =>
    P.quoteText(i.quote, {
      companyName: i.companyName,
      currency: i.currency,
      addons: P.resolveAddons({ addons: i.addonSettings }, i.addonLabels),
      contents: i.contents ?? undefined
    }),

  leadSummary: (i) =>
    P.leadSummary({
      service: i.service,
      dest: i.dest,
      billableG: i.billableG,
      shipping: i.shipping,
      addons: P.resolveAddons({ addons: i.addonSettings }, i.addonLabels),
      currency: i.currency,
      piecesText: i.piecesText
    }),

  cargoText: (i) => P.cargoText(i.companyName, i.from, i.dest, P.computeWeights(settings(i.site), i.rows), i.units),
  holdText: (i) => P.holdText(i.companyName, i.dest, i.kg, i.contents),

  validateSite: (i) => P.validateSite(site(i.site)),
  warnSite: (i) => P.warnSite(site(i.site))
};

/* ---------------------------------------------------------------------- run it */

let passed = 0;
const failures = [];
const unhandled = [];

for (const group of data.groups) {
  const handler = handlers[group.fn];
  if (!handler) {
    unhandled.push(group.fn);
    continue;
  }

  for (const testCase of group.cases) {
    const label = `${group.fn} / ${testCase.name}`;
    let actual;

    try {
      actual = handler(testCase.input);
    } catch (e) {
      failures.push(`${label} - threw ${e.message}`);
      continue;
    }

    const problem = difference(testCase.expected, actual, "");
    if (problem) failures.push(`${label} - ${problem}`);
    else passed++;
  }
}

const total = data.groups.reduce((n, g) => n + g.cases.length, 0);

console.log(`pricing.js: ${passed} of ${total} vectors passed`);

if (unhandled.length) {
  console.error(`\nNo browser handler for: ${unhandled.join(", ")}`);
}

if (failures.length) {
  console.error(`\n${failures.length} failed:`);
  for (const f of failures.slice(0, 40)) console.error(`  - ${f}`);
  if (failures.length > 40) console.error(`  ... and ${failures.length - 40} more`);
}

const ok = failures.length === 0 && unhandled.length === 0 && passed === total && total > 0;
console.log(`RESULT: ${ok ? "PASS" : "FAIL"}`);
process.exit(ok ? 0 : 1);
