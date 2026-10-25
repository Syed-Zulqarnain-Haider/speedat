/**
 * Golden-vector generator for the PHP/JS port of the pricing engine.
 *
 *   cd /d/speedat
 *   TZ=Asia/Karachi pnpm exec tsx php/tools/make-vectors.ts
 *
 * It imports the REAL TypeScript engine (never a copy of it) and writes every
 * answer it gives to php/tests/vectors/pricing.json. The PHP engine and the
 * browser engine are then run against that file; a disagreement is a bug in
 * the port, never a reason to edit a vector.
 *
 * Determinism: the only clock-sensitive functions are the delivery estimates
 * and the date formatters, and every case here pins its own `now`. Vectors are
 * generated in Asia/Karachi, which is the application time zone; the test
 * runners pin the same zone.
 */
import { writeFileSync, mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

import {
  addonsList,
  computeWeights,
  effectiveSteps,
  gridPrice,
  gridWeights,
  kgToG,
  gToKg,
  parts,
  priceAll,
  priceService,
  roundTo,
  slabToGrid,
  toNumLoose,
} from "@/lib/pricing/engine";
import { badHolidayLines, estimateDelivery, holidaySet, parseDays, parseDaysRange, workingSet } from "@/lib/pricing/dates";
import {
  fmtDate,
  fmtDateTime,
  fmtDay,
  fmtHour,
  fmtMoney,
  fmtNum,
  fmtPhone,
  fmtRange,
  inputDate,
  localDateFromInput,
  nameKey,
  quoteId,
  quoteIdFor,
  slug,
} from "@/lib/pricing/format";
import {
  bookedTotal,
  cargoText,
  leadSummary,
  midSentence,
  piecesText,
  quoteText,
  resolveAddons,
  waLink,
  weightSentence,
  type Quote,
  type Units,
} from "@/lib/pricing/quote";
import { validateSite, warnSite } from "@/lib/site/diff";
import { SEED, gridSeed } from "@/lib/site/seed";
import type { Destination, PieceInput, RateCard, Settings, ShipmentType } from "@/lib/pricing/types";
import type { SiteData } from "@/lib/site/types";

/* ------------------------------------------------------------------ plumbing */

interface Vector {
  name: string;
  input: Record<string, unknown>;
  expected: unknown;
}

interface Group {
  fn: string;
  cases: Vector[];
}

const groups: Group[] = [];
const sites: Record<string, unknown> = {};
const siteObjects = new Map<string, SiteData>();

function group(fn: string): (name: string, input: Record<string, unknown>, expected: unknown) => void {
  const g: Group = { fn, cases: [] };
  groups.push(g);
  return (name, input, expected) => {
    g.cases.push({ name, input, expected });
  };
}

/** Register a site document under a name; vectors refer to it by that name. */
function site(name: string, doc: SiteData): string {
  if (!sites[name]) {
    siteObjects.set(name, doc);
    sites[name] = {
      company: { name: doc.company.name, whatsapp: doc.company.whatsapp },
      settings: doc.settings,
      services: doc.services,
      destinations: doc.destinations,
    };
  }
  return name;
}

/** NaN and undefined are not JSON; the port produces null in the same places. */
const num = (v: unknown): number | null => (typeof v === "number" && Number.isFinite(v) ? v : null);

const clone = (): SiteData => structuredClone(SEED);

function withSettings(base: SiteData, patch: Partial<Settings>): SiteData {
  const s = structuredClone(base);
  Object.assign(s.settings, patch);
  return s;
}

const card = (s: SiteData): RateCard => ({ services: s.services, destinations: s.destinations, settings: s.settings });

/* ------------------------------------------------------- serialisation shims */

function serWeights(w: ReturnType<typeof computeWeights>): unknown {
  return {
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
      volumetricWins: l.volumetricWins,
    })),
  };
}

function serPrice(p: ReturnType<typeof priceService>): unknown {
  if (!p) return null;
  return {
    first: num(p.first),
    addl: num(p.addl),
    doc: num(p.doc),
    docRate: p.docRate,
    steps: p.steps,
    gridKg: num(p.gridKg),
    base: p.base,
    tax: p.tax,
    total: p.total,
    days: p.days,
  };
}

function serResult(r: ReturnType<typeof priceAll>): unknown {
  if (!r.ok) {
    return r.reason === "overmax"
      ? { ok: false, reason: r.reason, destId: r.dest.id, weights: serWeights(r.weights) }
      : { ok: false, reason: r.reason };
  }
  const prices: Record<string, unknown> = {};
  for (const key of Object.keys(r.prices)) prices[key] = serPrice(r.prices[key] ?? null);
  return { ok: true, destId: r.dest.id, type: r.type, weights: serWeights(r.weights), prices };
}

const ymd = (d: Date): string => inputDate(d);

/* ------------------------------------------------------------- the documents */

const SEED_SITE = site("seed", SEED);
const GRID_SITE = site("grid", gridSeed());

// A grid document whose boxes stop at 3 kg: nothing heavier can be priced.
const topOnly = structuredClone(gridSeed());
topOnly.destinations[0]!.rates.express!.grid = { "1": 4000, "2": 6000, "3": 8000 };
const TOP_ONLY = site("gridTopsAt3", topOnly);

// A grid document with holes below the last priced kilogram.
const gappy = structuredClone(gridSeed());
gappy.destinations[0]!.rates.express!.grid = { "1": 4000, "2": 6000, "5": 9000, "24": 20000 };
const GAPPY = site("gridWithGaps", gappy);

// Tax and rounding variants of the slab document.
const TAXED_16 = site("seedTax16Round10", withSettings(SEED, { taxPct: 16 }));
const TAXED_3 = site("seedTax3Round10", withSettings(SEED, { taxPct: 3 }));
const TAX_17_ROUND_0 = site("seedTax17Round0", withSettings(SEED, { taxPct: 17, roundTo: 0 }));
const TAX_17_ROUND_1 = site("seedTax17Round1", withSettings(SEED, { taxPct: 17, roundTo: 1 }));
const TAX_17_ROUND_50 = site("seedTax17Round50", withSettings(SEED, { taxPct: 17, roundTo: 50 }));
const NO_MAX = site("seedNoCargoLimit", withSettings(SEED, { maxKg: 0 }));
const MAX_25 = site("seedCargoAt25", withSettings(SEED, { maxKg: 25 }));
const HEAVY_FIRST = site("seedFirstKg1", withSettings(SEED, { firstKg: 1, stepKg: 1 }));
const MIN_1KG = site("seedFirst1Step05", withSettings(SEED, { firstKg: 1, stepKg: 0.5 }));

// A destination that offers Express only, and one whose Express has no document price.
const partial = clone();
partial.destinations = [{ id: "x", name: "X", active: true, rates: { express: { first: 1000, addl: 100 } } } as Destination];
const PARTIAL = site("expressOnly", partial);

const noDoc = clone();
delete noDoc.destinations[0]!.rates.express!.doc;
const NO_DOC = site("seedNoDocPrice", noDoc);

const hidden = clone();
hidden.destinations = hidden.destinations.map((d) => ({ ...d, active: false }));
const HIDDEN = site("allHidden", hidden);

const emptyGrid = structuredClone(gridSeed());
emptyGrid.destinations[0]!.rates.express = { grid: {} };
const EMPTY_GRID = site("gridEmptyExpress", emptyGrid);

// Documents that fail validation or raise warnings.
const docOnly = clone();
docOnly.destinations[0]!.rates.express = { doc: 3000 };
const DOC_ONLY = site("badDocOnly", docOnly);

const badDoc = clone();
badDoc.destinations[0]!.rates.express!.doc = 9000;
const BAD_DOC = site("warnDocDearerThanParcel", badDoc);

const badSettings = clone();
badSettings.settings.cutoffHour = 25;
badSettings.settings.workingDays = "xyz";
const BAD_SETTINGS = site("badSettings", badSettings);

const dupName = clone();
dupName.destinations[1]!.name = "united kingdom";
dupName.company.whatsapp = "12";
const DUP_NAME = site("badDuplicateName", dupName);

const suspicious = clone();
suspicious.destinations[0]!.rates.express!.first = 3000;
suspicious.destinations[1]!.rates.normal!.addl = 9999;
const SUSPICIOUS = site("warnSuspiciousRates", suspicious);

const zeroBox = structuredClone(gridSeed());
zeroBox.destinations[0]!.rates.express!.grid!["3"] = 0;
const ZERO_BOX = site("badZeroGridBox", zeroBox);

const cheaper4 = structuredClone(gridSeed());
cheaper4.destinations[0]!.rates.express!.grid!["4"] = 100;
const CHEAPER_4 = site("warnCheaperHeavierBox", cheaper4);

const gridNoMax = structuredClone(gridSeed());
gridNoMax.settings.maxKg = 0;
const GRID_NO_MAX = site("badGridWithoutThreshold", gridNoMax);

/* ------------------------------------------------------------------ roundTo */

{
  const v = group("roundTo");
  const cases: Array<[number, number]> = [
    [8034, 10],
    [8035, 10],
    [8036, 10],
    [8034, 0],
    [8034, 1],
    [8034, 50],
    [8025, 50],
    [8024.999, 50],
    [0, 10],
    [5, 10],
    [4.999, 10],
    [12345.6789, 1],
    [12345.6789, 0.5],
    [7800.5, 10],
    [1, 100],
    [49, 100],
    [50, 100],
    [150, 100],
    [250, 100],
  ];
  for (const [n, r] of cases) v(`${n} to ${r}`, { n, r }, roundTo(n, r));
}

/* ----------------------------------------------------------- gram conversion */

{
  const v = group("kgToG");
  for (const kg of [0, 0.001, 0.1, 0.3, 0.5, 0.51, 0.9, 1, 1.2, 2.5, 2.51, 4.6, 12.345, 24.9, 25, 25.1, 70.5, 500]) {
    v(`${kg} kg`, { kg }, kgToG(kg));
  }
}

{
  const v = group("gToKg");
  for (const g of [0, 5, 300, 500, 1000, 1200, 2500, 12000, 24000, 24900, 25100, 70500, 500000, 1]) {
    v(`${g} g`, { g }, gToKg(g));
  }
}

/* --------------------------------------------------------------- toNumLoose */

{
  const v = group("toNumLoose");
  const serNum = (x: number | null): unknown => (x === null ? { value: null } : Number.isNaN(x) ? { nan: true } : { value: x });
  const cases: Array<[string, unknown]> = [
    ["string Rs", "Rs 4,500/-"],
    ["string PKR", "PKR 4,500"],
    ["decimal", "4,500.50"],
    ["padded", " 12.5 "],
    ["dash", "-"],
    ["spaced dash", " - "],
    ["en dash", "–"],
    ["em dash", "—"],
    ["na", "N/A"],
    ["na slashless", "na"],
    ["empty", ""],
    ["null", null],
    ["junk", "abc"],
    ["number", 7],
    ["number big", 1234],
    ["negative", "-250"],
    ["leading text", "price 900 rupees"],
  ];
  for (const [name, raw] of cases) v(name, { v: raw }, serNum(toNumLoose(raw)));
}

/* ------------------------------------------------------------------- parts */

{
  const v = group("parts");
  const cases: Array<[string, number]> = [
    ["Pickup and service charges | 500 | on", 3],
    ["Insurance|250", 3],
    ["Only a label", 3],
    ["  a  |  b  |  c  |  d  ", 3],
    ["", 3],
  ];
  for (const [line, n] of cases) v(line || "(empty)", { line, n }, parts(line, n));
}

/* -------------------------------------------------------------- addonsList */

{
  const v = group("addonsList");
  const cases: Array<[string, string]> = [
    ["seed", SEED.settings.addons],
    ["two with a broken line", "Pickup and service charges | 500 | on\nBroken line\nInsurance | 250 | off"],
    ["every truthy word", "A | 1 | on\nB | 2 | yes\nC | 3 | true\nD | 4 | 1\nE | 5 | checked\nF | 6 | default\nG | 7 | nope"],
    ["zero is allowed", "Free service | 0 | on"],
    ["negative is dropped", "Discount | -100 | on"],
    ["no label", " | 500 | on"],
    ["messy amounts", "Fuel | Rs 1,250/- | on\nOdd | n/a | on"],
    ["blank", ""],
    ["crlf", "A | 1 | on\r\nB | 2 | off\r\n"],
  ];
  for (const [name, addons] of cases) v(name, { addons }, addonsList({ addons }));
}

/* -------------------------------------------------- grid weights and lookup */

{
  const v = group("gridWeights");
  for (const maxKg of [0, 1, 25, 30, 70, 199, 200, 250, 25.9, -5]) v(`maxKg ${maxKg}`, { maxKg }, gridWeights({ maxKg }));
}

{
  const v = group("gridPrice");
  const g = { "1": 100, "2": 200, "5": 500 };
  const holes = { "1": 4000, "2": 6000, "5": 9000, "24": 20000 };
  const cases: Array<[string, Record<string, number> | undefined, number, number]> = [
    ["below one", g, 0.4, 25],
    ["exactly one", g, 1, 25],
    ["just over one", g, 1.2, 25],
    ["gap falls upward", g, 3, 25],
    ["above the top", g, 6, 25],
    ["missing grid", undefined, 1, 25],
    ["zero weight", g, 0, 25],
    ["negative weight", g, -3, 25],
    ["no threshold", g, 3, 0],
    ["holes at 3", holes, 3, 25],
    ["holes at 6", holes, 6, 25],
    ["holes at 24", holes, 24, 25],
    ["holes at 25", holes, 25, 25],
    ["zero priced box is skipped", { "1": 0, "2": 700 }, 1, 25],
  ];
  for (const [name, grid, kg, maxKg] of cases) v(name, { grid: grid ?? null, kg, maxKg }, gridPrice(grid, kg, maxKg));
}

/* ----------------------------------------------------------- effectiveSteps */

{
  const v = group("effectiveSteps");
  const cases: Array<Pick<Settings, "pricingMode" | "firstKg" | "stepKg">> = [
    { pricingMode: "slab", firstKg: 0.5, stepKg: 0.5 },
    { pricingMode: "slab", firstKg: 1, stepKg: 0.5 },
    { pricingMode: "grid", firstKg: 0.5, stepKg: 0.5 },
    { pricingMode: "grid", firstKg: 2, stepKg: 3 },
  ];
  for (const s of cases) v(`${s.pricingMode} ${s.firstKg}/${s.stepKg}`, { settings: s }, effectiveSteps(s));
}

/* ------------------------------------------------------------ computeWeights */

const WEIGHT_ROWS: Array<[string, PieceInput[]]> = [
  ["tiny", [{ kg: 0.1 }]],
  ["0.3", [{ kg: 0.3 }]],
  ["0.5", [{ kg: 0.5 }]],
  ["0.51", [{ kg: 0.51 }]],
  ["0.9", [{ kg: 0.9 }]],
  ["1.2", [{ kg: 1.2 }]],
  ["2.3", [{ kg: 2.3 }]],
  ["2.5", [{ kg: 2.5 }]],
  ["2.51", [{ kg: 2.51 }]],
  ["4.6", [{ kg: 4.6 }]],
  ["24.9", [{ kg: 24.9 }]],
  ["25.1", [{ kg: 25.1 }]],
  ["volumetric wins", [{ kg: 1, L: 50, W: 40, H: 30 }]],
  ["volumetric loses", [{ kg: 20, L: 20, W: 20, H: 20 }]],
  ["two of three dims", [{ kg: 1, L: 50, W: 40 }]],
  ["zero dim", [{ kg: 2.5, L: 50, W: 40, H: 0 }]],
  ["qty three volumetric", [{ kg: 1, qty: 3, L: 30, W: 20, H: 10 }]],
  ["qty zero means one", [{ kg: 1, qty: 0 }]],
  ["qty fractional", [{ kg: 1, qty: 2.7 }]],
  ["qty negative", [{ kg: 1, qty: -4 }]],
  ["mixed rows", [{ kg: 2, qty: 2 }, { kg: 1, qty: 1, L: 50, W: 40, H: 30 }]],
  ["skips weightless rows", [{ kg: 0 }, { kg: null }, { kg: 1 }]],
  ["two rows same weight", [{ kg: 13 }, { kg: 13 }]],
  ["four small rows", [{ kg: 0.2 }, { kg: 0.2 }, { kg: 0.2 }, { kg: 0.2 }]],
  ["heavy", [{ kg: 70.5 }]],
];

{
  const v = group("computeWeights");
  for (const siteName of [SEED_SITE, GRID_SITE, HEAVY_FIRST, MIN_1KG]) {
    const s = siteObjects.get(siteName)!;
    for (const [name, rows] of WEIGHT_ROWS) {
      v(`${siteName}: ${name}`, { site: siteName, rows }, serWeights(computeWeights(s.settings, rows)));
    }
    for (let kg = 1; kg <= 25; kg++) {
      v(`${siteName}: ${kg} kg`, { site: siteName, rows: [{ kg }] }, serWeights(computeWeights(s.settings, [{ kg }])));
    }
  }
}

/* ------------------------------------------------------------------ priceAll */

{
  const v = group("priceAll");
  const add = (siteName: string, destId: string, rows: PieceInput[], type?: ShipmentType) => {
    const s = siteObjects.get(siteName)!;
    const input = { destId, rows, ...(type ? { type } : {}) };
    v(`${siteName}: ${destId} ${JSON.stringify(rows)}${type ? ` ${type}` : ""}`, { site: siteName, input }, serResult(priceAll(card(s), input)));
  };

  // Every whole kilogram on both documents, package and document.
  for (const siteName of [SEED_SITE, GRID_SITE]) {
    for (let kg = 1; kg <= 25; kg++) {
      add(siteName, "gb", [{ kg }]);
      add(siteName, "ca", [{ kg }]);
    }
    for (const kg of [0.3, 0.4, 0.5, 0.6, 0.9, 1.2, 4.6, 24.9, 25.1]) {
      add(siteName, "gb", [{ kg }]);
      add(siteName, "gb", [{ kg }], "doc");
      add(siteName, "gb", [{ kg }], "pkg");
    }
    add(siteName, "gb", [{ kg: 1, L: 50, W: 40, H: 30 }]);
    add(siteName, "gb", [{ kg: 2, qty: 2 }, { kg: 1, qty: 1, L: 50, W: 40, H: 30 }]);
    add(siteName, "ae", [{ kg: 2.5, qty: 2 }]);
    add(siteName, "my", [{ kg: 3 }], "doc");
  }

  // Tax and rounding.
  for (const siteName of [TAXED_16, TAXED_3, TAX_17_ROUND_0, TAX_17_ROUND_1, TAX_17_ROUND_50]) {
    for (const kg of [0.5, 1, 2, 2.5, 7, 13.5]) add(siteName, "gb", [{ kg }]);
    add(siteName, "gb", [{ kg: 0.3 }], "doc");
  }

  // Cargo threshold.
  add(SEED_SITE, "gb", [{ kg: 70 }]);
  add(SEED_SITE, "gb", [{ kg: 70.5 }]);
  add(SEED_SITE, "gb", [{ kg: 71 }]);
  add(SEED_SITE, "gb", [{ kg: 36, qty: 2 }]);
  add(MAX_25, "gb", [{ kg: 13, qty: 2 }]);
  add(MAX_25, "gb", [{ kg: 12, qty: 2 }]);
  add(MAX_25, "gb", [{ kg: 25 }]);
  add(MAX_25, "gb", [{ kg: 13 }, { kg: 13 }]);
  add(NO_MAX, "gb", [{ kg: 500 }]);
  add(GRID_SITE, "gb", [{ kg: 25.5 }]);

  // Refusals and missing rates.
  add(SEED_SITE, "xx", [{ kg: 1 }]);
  add(SEED_SITE, "gb", [{ kg: 0 }]);
  add(SEED_SITE, "gb", [{ kg: null }]);
  add(HIDDEN, "gb", [{ kg: 1 }]);
  add(PARTIAL, "x", [{ kg: 1 }]);
  add(NO_DOC, "gb", [{ kg: 0.3 }], "doc");
  add(EMPTY_GRID, "gb", [{ kg: 1 }]);
  add(TOP_ONLY, "gb", [{ kg: 5 }]);
  add(TOP_ONLY, "gb", [{ kg: 3 }]);
  add(GAPPY, "gb", [{ kg: 3 }]);
  add(GAPPY, "gb", [{ kg: 25 }]);

  // The first slab heavier than the document limit.
  add(HEAVY_FIRST, "gb", [{ kg: 0.3 }], "doc");
  add(HEAVY_FIRST, "gb", [{ kg: 0.6 }], "doc");
  add(MIN_1KG, "gb", [{ kg: 0.3 }]);
  add(MIN_1KG, "gb", [{ kg: 0.7 }]);
}

/* ---------------------------------------------------------------- priceService
 * The server re-prices a booked quote from the two weights the browser sent.
 * The document limit is judged on the CHARGEABLE grams, so both are vectored,
 * including the trap: the same call without chargeG loses the document rate.
 */
{
  const v = group("priceService");
  const add = (siteName: string, destId: string, serviceId: string, billableG: number, type: ShipmentType, chargeG?: number) => {
    const s = siteObjects.get(siteName)!;
    const dest = s.destinations.find((d) => d.id === destId);
    if (!dest) throw new Error(`no destination ${destId}`);
    const expected = chargeG === undefined
      ? priceService(s.settings, dest, serviceId, billableG, type)
      : priceService(s.settings, dest, serviceId, billableG, type, chargeG);
    v(
      `${siteName}: ${destId}/${serviceId} ${billableG}g ${type}${chargeG === undefined ? " (no chargeG)" : ` charge ${chargeG}g`}`,
      { site: siteName, destId, serviceId, billableG, type, chargeG: chargeG ?? null },
      serPrice(expected),
    );
  };

  for (const siteName of [SEED_SITE, GRID_SITE, HEAVY_FIRST]) {
    for (const g of [500, 1000, 1500, 2500, 5000, 12000, 25000]) {
      add(siteName, "gb", "express", g, "pkg");
      add(siteName, "gb", "normal", g, "pkg");
      add(siteName, "gb", "express", g, "doc");
    }
    add(siteName, "gb", "express", 1000, "doc", 300);
    add(siteName, "gb", "express", 1000, "doc", 600);
    add(siteName, "gb", "express", 500, "doc", 300);
  }
  add(GRID_SITE, "gb", "express", 1000, "doc"); // the trap: billed grams only
  add(SEED_SITE, "gb", "missing", 1000, "pkg");
  add(PARTIAL, "x", "normal", 1000, "pkg");
}

/* --------------------------------------------------------------- slabToGrid */

{
  const v = group("slabToGrid");
  const cases: Array<[string, string, { first?: number | null; addl?: number | null; grid?: Record<string, number> }]> = [
    ["seed express", SEED_SITE, { first: 4500, addl: 1100 }],
    ["seed normal", SEED_SITE, { first: 3200, addl: 850 }],
    ["cargo 25", MAX_25, { first: 4500, addl: 1100 }],
    ["keeps existing boxes", MAX_25, { first: 4500, addl: 1100, grid: { "1": 1, "2": 2 } }],
    ["no slab prices", MAX_25, { grid: { "7": 700 } }],
    ["nothing at all", MAX_25, {}],
    ["first only", MAX_25, { first: 4500 }],
    ["no threshold", NO_MAX, { first: 1000, addl: 100 }],
    ["whole kilogram slabs", HEAVY_FIRST, { first: 4500, addl: 1100 }],
    ["tax rounds the boxes", TAX_17_ROUND_50, { first: 4500, addl: 1100 }],
  ];
  for (const [name, siteName, rate] of cases) {
    const s = siteObjects.get(siteName)!;
    v(name, { site: siteName, rate }, slabToGrid(s.settings, structuredClone(rate)) ?? null);
  }
}

/* ------------------------------------------------------------ transit times */

{
  const v = group("parseDaysRange");
  for (const s of ["3–5", "3-5", "5-3", "4", "", "3 to 5 working days", "soon", "1–1", "10–2", "0", "7 ", null]) {
    v(String(s), { s }, parseDaysRange(s));
  }
}

{
  const v = group("parseDays");
  for (const s of ["3 to 5 days", "3-5 working days", "3 to 5 working days", " 4 days ", "4", "", "2—4", "6–9 business days", "next day"]) {
    v(s || "(empty)", { v: s }, parseDays(s));
  }
}

{
  const v = group("workingSet");
  for (const workingDays of [
    "Mon, Tue, Wed, Thu, Fri, Sat",
    "Monday to Friday",
    "Sat-Mon",
    "",
    "Mon–Sat",
    "Monday to Friday, Sunday",
    "xyz",
    "sunday",
    "MON WED FRI",
    "Mon through Thu",
    "Tue till Sat",
  ]) {
    v(workingDays || "(empty)", { workingDays }, [...workingSet({ workingDays })].sort((a, b) => a - b));
  }
}

{
  const v = group("holidaySet");
  for (const holidays of ["", "2026-09-21 | Test holiday\n2026-09-23\nnot a date", "2026-01-01\r\n2026-12-25 | Christmas", "  2026-03-23  | Pakistan Day"]) {
    v(holidays || "(empty)", { holidays }, [...holidaySet({ holidays })].sort());
  }
}

{
  const v = group("badHolidayLines");
  for (const holidays of ["", "2026-09-21 | ok\nnot a date\n2026-13-01 | still text", "2026-09-21|name", "21-09-2026"]) {
    v(holidays || "(empty)", { holidays }, badHolidayLines({ holidays }));
  }
}

/* -------------------------------------------------------- delivery estimates */

{
  const v = group("estimateDelivery");
  const add = (
    days: string | null,
    workingDays: string,
    cutoffHour: number | null,
    holidays: string,
    shipDate: [number, number, number] | null,
    now: [number, number, number, number, number],
  ) => {
    const settings = { workingDays, cutoffHour, holidays };
    const ship = shipDate ? new Date(shipDate[0], shipDate[1] - 1, shipDate[2]) : null;
    const nowDate = new Date(now[0], now[1] - 1, now[2], now[3], now[4]);
    const est = estimateDelivery(days, settings, ship, nowDate);
    v(
      `${days} | ${workingDays} | cutoff ${cutoffHour} | ship ${shipDate?.join("-") ?? "none"} | now ${now.join("-")}`,
      { days, workingDays, cutoffHour, holidays, shipDate: ship ? ymd(ship) : null, now },
      est === null ? null : { pickup: ymd(est.pickup), from: ymd(est.from), to: ymd(est.to), moved: est.moved, range: est.range },
    );
  };

  const SIX = "Mon, Tue, Wed, Thu, Fri, Sat";
  const FIVE = "Mon-Fri";
  const HOL = "2026-09-21 | Test holiday\n2026-09-23\nnot a date";

  add("3–5", SIX, 15, "", null, [2026, 9, 19, 10, 0]); // Saturday morning
  add("3–5", SIX, 15, "", null, [2026, 9, 19, 16, 0]); // after the cutoff
  add("3–5", SIX, 15, "", null, [2026, 9, 19, 15, 0]); // exactly at the cutoff
  add("3–5", SIX, null, "", null, [2026, 9, 19, 23, 0]); // no cutoff at all
  add("3–5", FIVE, 15, "", null, [2026, 9, 19, 10, 0]); // Saturday is not a working day
  add("2", FIVE, null, "", [2026, 9, 1], [2026, 9, 21, 9, 0]); // never in the past
  add("2", FIVE, null, "", [2026, 9, 20], [2026, 9, 19, 10, 0]); // Sunday ship date
  add("1", SIX, 15, "", [2026, 9, 10], [2026, 9, 19, 10, 0]);
  add("3", SIX, 15, HOL, null, [2026, 9, 19, 10, 0]);
  add("1", SIX, 15, HOL, [2026, 9, 21], [2026, 9, 19, 10, 0]);
  add("soon", SIX, 15, "", null, [2026, 9, 19, 10, 0]);
  add(null, SIX, 15, "", null, [2026, 9, 19, 10, 0]);
  add("6–9", SIX, 15, "", null, [2026, 12, 29, 9, 0]); // across a year boundary
  add("4", "Sun", 12, "", null, [2026, 9, 19, 10, 0]); // one working day a week
  add("1", "", 0, "", null, [2026, 9, 21, 0, 0]); // cutoff 0 moves every order
  add("10", SIX, 15, "2026-10-01\n2026-10-02\n2026-10-05", null, [2026, 9, 28, 8, 0]);
  add("0", SIX, 15, "", null, [2026, 9, 19, 10, 0]); // zero transit days
  for (let d = 14; d <= 27; d++) add("3–5", SIX, 15, HOL, null, [2026, 9, d, 9, 0]);
}

/* --------------------------------------------------------------- formatting */

{
  const v = group("fmtNum");
  for (const n of [0, 5, 500, 1000, 1234, 5600, 12500, 1234567, 8034.4, 8034.5, 8035.5, -2.5, -1234.5, 0.4, 0.5]) {
    v(String(n), { n }, fmtNum(n));
  }
}

{
  const v = group("fmtMoney");
  for (const [n, currency] of [[5600, "PKR"], [0, "PKR"], [1234567, "Rs"], [19620, "PKR"], [500, "USD"]] as Array<[number, string]>) {
    v(`${currency} ${n}`, { n, currency }, fmtMoney(n, currency));
  }
}

{
  const v = group("fmtDay");
  const days: Array<[number, number, number]> = [
    [2026, 1, 1],
    [2026, 2, 28],
    [2026, 3, 9],
    [2026, 4, 30],
    [2026, 5, 15],
    [2026, 6, 1],
    [2026, 7, 4],
    [2026, 8, 31],
    [2026, 9, 23],
    [2026, 9, 25],
    [2026, 10, 12],
    [2026, 11, 3],
    [2026, 12, 25],
    [2027, 2, 1],
  ];
  for (const [y, m, d] of days) v(`${y}-${m}-${d}`, { y, m, d }, fmtDay(new Date(y, m - 1, d)));
}

{
  const v = group("fmtRange");
  const cases: Array<[[number, number, number], [number, number, number]]> = [
    [[2026, 9, 23], [2026, 9, 25]],
    [[2026, 9, 23], [2026, 9, 23]],
    [[2026, 12, 30], [2027, 1, 4]],
  ];
  for (const [a, b] of cases) {
    const from = new Date(a[0], a[1] - 1, a[2]);
    const to = new Date(b[0], b[1] - 1, b[2]);
    v(`${a.join("-")}..${b.join("-")}`, { from: ymd(from), to: ymd(to) }, fmtRange({ from, to, pickup: from, moved: false, range: [1, 2] }));
  }
}

{
  const v = group("fmtDate");
  for (const iso of ["2026-09-23", "2026-09-23T14:05:00+05:00", "2026-01-01T00:00:00Z", "2026-12-31T23:59:59+05:00", "nonsense", ""]) {
    v(iso || "(empty)", { iso }, fmtDate(iso));
  }
}

{
  const v = group("fmtDateTime");
  for (const iso of ["2026-09-23T14:05:00+05:00", "2026-09-23T00:30:00+05:00", "2026-01-01T09:00:00+05:00", "2026-09-23T12:00:00+05:00", "nonsense"]) {
    v(iso || "(empty)", { iso }, fmtDateTime(iso));
  }
}

{
  const v = group("fmtPhone");
  for (const n of ["923157667076", "923001234567", "4412345", "", null, "92315766707", "9231576670765"]) {
    v(String(n), { n }, fmtPhone(n));
  }
}

{
  const v = group("fmtHour");
  for (const h of [0, 1, 9, 11, 12, 13, 15, 23, null]) v(String(h), { h }, fmtHour(h));
}

{
  const v = group("inputDate");
  for (const [y, m, d] of [[2026, 9, 5], [2026, 12, 31], [2027, 1, 1]] as Array<[number, number, number]>) {
    v(`${y}-${m}-${d}`, { y, m, d }, inputDate(new Date(y, m - 1, d)));
  }
}

{
  const v = group("localDateFromInput");
  for (const s of ["2026-09-05", "2026-2-3", "", "not a date", "2026-09-05T10:00:00"]) {
    const d = localDateFromInput(s);
    v(s || "(empty)", { v: s }, d === null ? null : ymd(d));
  }
}

{
  const v = group("quoteId");
  for (const [y, m, d] of [[2026, 9, 20], [2026, 1, 2], [2027, 12, 31]] as Array<[number, number, number]>) {
    v(`${y}-${m}-${d}`, { y, m, d }, quoteId(new Date(y, m - 1, d), () => 0));
  }
}

{
  const v = group("quoteIdFor");
  const seeds = ["gb|express|2500", "ca|normal|5000", "", "x", "a-very-long-seed-string-that-keeps-going-0123456789"];
  for (const seed of seeds) v(seed || "(empty)", { seed, y: 2026, m: 9, d: 22 }, quoteIdFor(seed, new Date(2026, 8, 22)));
}

{
  const v = group("nameKey");
  for (const n of ["Türkiye", "U.K.", "United Arab Emirates", "  Korea, Republic of  ", "Côte d'Ivoire", "", null, "ÅLAND", "São Tomé"]) {
    v(String(n), { name: n }, nameKey(n));
  }
}

{
  const v = group("slug");
  for (const n of ["Türkiye", "United Arab Emirates", "!!!", "Korea, Republic of", "A name that is definitely longer than forty characters in total", "Côte d'Ivoire", "  spaced  "]) {
    v(n, { name: n }, slug(n));
  }
}

/* -------------------------------------------------------- quote text builders */

{
  const v = group("piecesText");
  const units: Units[] = ["metric", "imperial"];
  for (const u of units) {
    for (const [name, rows] of WEIGHT_ROWS) {
      const w = computeWeights(SEED.settings, rows);
      if (!w.lines.length) continue;
      v(`${u}: ${name}`, { site: SEED_SITE, rows, units: u }, piecesText(w, u));
    }
  }
}

{
  const v = group("weightSentence");
  for (const siteName of [SEED_SITE, GRID_SITE, MIN_1KG, HEAVY_FIRST]) {
    const s = siteObjects.get(siteName)!;
    for (const kg of [0.3, 0.5, 0.7, 1, 1.2, 2, 2.5, 5]) {
      const w = computeWeights(s.settings, [{ kg, qty: 1 }]);
      v(`${siteName}: ${kg} kg`, { site: siteName, rows: [{ kg, qty: 1 }] }, weightSentence(w, s.settings));
    }
    const vol = computeWeights(s.settings, [{ kg: 1, qty: 2, L: 50, W: 40, H: 30 }]);
    v(`${siteName}: volumetric two pieces`, { site: siteName, rows: [{ kg: 1, qty: 2, L: 50, W: 40, H: 30 }] }, weightSentence(vol, s.settings));
  }
}

{
  const v = group("midSentence");
  for (const s of ["Pickup and service charges", "COD fee", "insurance", "", "A", "Ab", "Éclair"]) v(s || "(empty)", { s }, midSentence(s));
}

{
  const v = group("bookedTotal");
  const cases: Array<[number, number[]]> = [
    [19120, []],
    [19120, [500, 250]],
    [0, [500]],
    [7800, [0]],
  ];
  for (const [shipping, amounts] of cases) {
    v(`${shipping} + ${amounts.join("+") || "nothing"}`, { shipping, amounts }, bookedTotal(shipping, amounts.map((amount) => ({ amount }))));
  }
}

{
  const v = group("resolveAddons");
  const settings = { addons: "Pickup and service charges | 500 | on\nInsurance | 250 | off" };
  const cases: Array<[string, string[] | null]> = [
    ["both, out of order", ["Insurance", "Pickup and service charges", "Gift wrap"]],
    ["one", ["Insurance"]],
    ["none", []],
    ["null", null],
    ["unknown only", ["Gift wrap"]],
  ];
  for (const [name, labels] of cases) {
    v(name, { addons: settings.addons, labels }, resolveAddons(settings, labels ?? undefined));
  }
  v("no add-ons configured", { addons: "", labels: ["Insurance"] }, resolveAddons({ addons: "" }, ["Insurance"]));
  v("seed settings", { addons: SEED.settings.addons, labels: ["Pickup and service charges"] }, resolveAddons(SEED.settings, ["Pickup and service charges"]));
}

{
  const v = group("waLink");
  const cases: Array<[string, string]> = [
    ["923157667076", "Hi Speedat, I want to book a shipment."],
    ["923157667076", "Line one\nLine two"],
    ["923157667076", "Total: PKR 19,620 (100% sure) a+b&c=d/e?f#g"],
    ["923157667076", "Türkiye · 5 kg"],
    ["923157667076", "quotes 'single' \"double\" ~tilde !bang *star (parens)"],
  ];
  for (const [wa, text] of cases) v(text.slice(0, 30), { whatsapp: wa, text }, waLink(wa, text));
}

/** The quote object the site hands to the message builders, priced for real. */
function makeQuote(siteName: string, destId: string, serviceId: string, kg: number, type: ShipmentType, extra: Partial<Quote> = {}): Quote {
  const s = siteObjects.get(siteName)!;
  const res = priceAll(card(s), { destId, type, rows: [{ kg, qty: 1 }] });
  if (!res.ok) throw new Error(`expected ok, got ${res.reason}`);
  const p = res.prices[serviceId];
  if (!p) throw new Error(`${serviceId} not priced`);
  const service = s.services.find((x) => x.id === serviceId)!;
  return {
    id: "SP-260922-TEST",
    from: "",
    dest: res.dest.name,
    destId,
    type,
    service: service.name,
    serviceId,
    days: p.days,
    eta: "",
    pickup: "",
    pieces: res.weights.pieces,
    piecesText: piecesText(res.weights, "metric"),
    billableG: res.weights.billableG,
    chargeG: res.weights.chargeG,
    total: p.total,
    docRate: p.docRate,
    version: 1,
    ...extra,
  };
}

{
  const v = group("quoteText");
  const addonSets: Array<[string, string[]]> = [
    ["no add-ons", []],
    ["pickup", ["Pickup and service charges"]],
  ];
  const quotes: Array<[string, Quote, string | undefined]> = [
    ["canada express 5kg", makeQuote(SEED_SITE, "ca", "express", 5, "pkg"), undefined],
    ["uk normal 2.5kg with eta", makeQuote(SEED_SITE, "gb", "normal", 2.5, "pkg", { eta: "Wed 23 Sept – Fri 25 Sept", pickup: "Sat 19 Sept", from: "Lahore" }), "Two shirts"],
    ["uk document", makeQuote(SEED_SITE, "gb", "express", 0.3, "doc"), undefined],
    ["grid uk 1.2kg", makeQuote(GRID_SITE, "gb", "express", 1.2, "pkg"), "  "],
    ["no transit days", makeQuote(PARTIAL, "x", "express", 1, "pkg"), "Papers"],
  ];
  for (const [name, q, contents] of quotes) {
    for (const [aName, labels] of addonSets) {
      const addons = resolveAddons(SEED.settings, labels);
      v(
        `${name} / ${aName}`,
        { quote: q, companyName: "Speedat", currency: "PKR", addonSettings: SEED.settings.addons, addonLabels: labels, contents: contents ?? null },
        quoteText(q, { companyName: "Speedat", currency: "PKR", addons, contents }),
      );
    }
  }
}

{
  const v = group("leadSummary");
  const addons = resolveAddons(SEED.settings, ["Pickup and service charges"]);
  const cases: Array<[string, { service: string; dest: string; billableG: number; shipping: number; addonLabels: string[]; currency: string; piecesText?: string }]> = [
    ["with add-on and pieces", { service: "Express", dest: "Canada", billableG: 5000, shipping: 19120, addonLabels: ["Pickup and service charges"], currency: "PKR", piecesText: "1 × 5 kg" }],
    ["plain", { service: "Express", dest: "Canada", billableG: 5000, shipping: 19120, addonLabels: [], currency: "PKR" }],
    ["fractional kilograms", { service: "Normal", dest: "United Kingdom", billableG: 2500, shipping: 6600, addonLabels: [], currency: "PKR", piecesText: "2 × 1 kg; 1 × 0.5 kg" }],
    ["two add-ons", { service: "Express", dest: "Germany", billableG: 1000, shipping: 5850, addonLabels: ["Pickup and service charges", "Insurance"], currency: "PKR" }],
  ];
  const twoAddons = { addons: "Pickup and service charges | 500 | on\nInsurance | 250 | off" };
  for (const [name, c] of cases) {
    const list = name === "two add-ons" ? resolveAddons(twoAddons, c.addonLabels) : c.addonLabels.length ? addons : [];
    v(
      name,
      { ...c, addonSettings: name === "two add-ons" ? twoAddons.addons : SEED.settings.addons },
      leadSummary({ ...c, addons: list }),
    );
  }
}

{
  const v = group("cargoText");
  const units: Units[] = ["metric", "imperial"];
  for (const u of units) {
    for (const [name, rows, from] of [
      ["one heavy piece", [{ kg: 80 }], ""],
      ["with origin", [{ kg: 80 }], "Lahore"],
      ["several pieces", [{ kg: 30, qty: 2 }, { kg: 12, L: 60, W: 50, H: 40 }], "Faisalabad"],
    ] as Array<[string, PieceInput[], string]>) {
      const w = computeWeights(SEED.settings, rows);
      v(`${u}: ${name}`, { site: SEED_SITE, rows, units: u, companyName: "Speedat", from, dest: "United Kingdom" }, cargoText("Speedat", from, "United Kingdom", w, u));
    }
  }
}

/**
 * The hold form's WhatsApp message (components/site/HoldPanel.tsx). It is the
 * only message builder that does not live in lib/, so it is reproduced here
 * exactly as the component writes it — and the PHP/JS ports must match.
 */
function holdText(companyName: string, dest: string, kg: string, contents: string): string {
  const out = [`Hi ${companyName}, please price a parcel for me.`];
  if (dest) out.push(`To: ${dest}`);
  if (kg) out.push(`Weight: about ${kg} kg`);
  if (contents.trim()) out.push(`Contents: ${contents.trim()}`);
  return out.join("\n");
}

{
  const v = group("holdText");
  const cases: Array<[string, string, string, string]> = [
    ["Speedat", "United Kingdom", "5", "Documents"],
    ["Speedat", "United Kingdom", "5", ""],
    ["Speedat", "United Kingdom", "", ""],
    ["Speedat", "", "", ""],
    ["Speedat", "Canada", "0.5", "  two books  "],
    ["Speedat International Courier", "Türkiye", "12.5", "Spare parts"],
  ];
  for (const [companyName, dest, kg, contents] of cases) {
    v(`${dest || "no destination"} ${kg || "no weight"}`, { companyName, dest, kg, contents }, holdText(companyName, dest, kg, contents));
  }
}

/* ------------------------------------------------------- validation and warnings */

{
  const v = group("validateSite");
  for (const name of [SEED_SITE, GRID_SITE, DOC_ONLY, BAD_SETTINGS, DUP_NAME, ZERO_BOX, HIDDEN, EMPTY_GRID, GRID_NO_MAX, PARTIAL, TOP_ONLY, GAPPY]) {
    v(name, { site: name }, validateSite(siteObjects.get(name)!));
  }
}

{
  const v = group("warnSite");
  for (const name of [SEED_SITE, GRID_SITE, BAD_DOC, SUSPICIOUS, CHEAPER_4, TOP_ONLY, GAPPY, PARTIAL, EMPTY_GRID]) {
    v(name, { site: name }, warnSite(siteObjects.get(name)!));
  }
}

/* -------------------------------------------------------------------- output */

const total = groups.reduce((n, g) => n + g.cases.length, 0);
const payload = {
  note: "Generated from the TypeScript engine by php/tools/make-vectors.ts. Do not hand-edit: regenerate.",
  generator: "php/tools/make-vectors.ts",
  timezone: "Asia/Karachi",
  count: total,
  sites,
  groups,
};

const here = dirname(fileURLToPath(import.meta.url));
const out = join(here, "..", "tests", "vectors", "pricing.json");
mkdirSync(dirname(out), { recursive: true });
writeFileSync(out, JSON.stringify(payload, null, 1) + "\n", "utf8");

const offset = new Date(2026, 8, 19).getTimezoneOffset();
if (offset !== -300) {
  throw new Error(`Generate with TZ=Asia/Karachi: this run was at UTC${offset > 0 ? "-" : "+"}${Math.abs(offset) / 60}.`);
}

process.stdout.write(`${total} vectors in ${groups.length} groups → ${out}\n`);
for (const g of groups) process.stdout.write(`  ${String(g.cases.length).padStart(4)}  ${g.fn}\n`);
