/**
 * Pure pricing engine — no DOM, no I/O. Runs identically in the browser
 * (instant quotes) and on the server (quote logging, admin "test a price").
 * All weights are integer grams so 0.5 kg steps never suffer float drift.
 */
import type {
  Addon,
  Destination,
  PieceInput,
  PriceInput,
  PriceResult,
  RateCard,
  ServicePrice,
  Settings,
  ShipmentType,
  WeightLine,
  Weights,
} from "./types";

export const isNum = (v: unknown): v is number => typeof v === "number" && Number.isFinite(v);

export const kgToG = (kg: number): number => Math.round(Number(kg) * 1000);

/** Grams → kg as a short string ("2.5", "12"). */
export const gToKg = (g: number): string => String(Math.round((g / 1000) * 100) / 100);

/** Nearest multiple of `r`. A zero, negative or non-numeric `r` means "no rounding" (nearest 1), never a sign flip or NaN. */
export function roundTo(n: number, r: number): number {
  const step = Number(r) > 0 ? Number(r) : 1;
  return Math.round(n / step) * step;
}

/** Whole-kilogram weights the grid prices, 1 … cargo threshold (30 when there is no threshold). */
export function gridWeights(settings: Pick<Settings, "maxKg">): number[] {
  const top = settings.maxKg > 0 ? Math.min(Math.floor(settings.maxKg), 200) : 30;
  return Array.from({ length: Math.max(top, 1) }, (_, i) => i + 1);
}

export const isGrid = (settings: Pick<Settings, "pricingMode">): boolean => settings.pricingMode === "grid";

/** Grid price for a billable weight: the entered price at that kg, or the next heavier kg that has one. */
export function gridPrice(grid: Record<string, number> | undefined, kg: number, maxKg: number): { kg: number; price: number } | null {
  if (!grid) return null;
  const top = maxKg > 0 ? Math.floor(maxKg) : 200;
  for (let k = Math.max(1, Math.ceil(kg)); k <= top; k++) {
    const p = grid[String(k)];
    if (isNum(p) && p > 0) return { kg: k, price: p };
  }
  return null;
}

/**
 * Slab settings the weight maths should use: grid mode bills whole kilograms from 1 kg up.
 * Anything that explains a rounding to the customer must read these, not `settings.stepKg` alone.
 */
export const effectiveSteps = (settings: Pick<Settings, "pricingMode" | "firstKg" | "stepKg">): { firstKg: number; stepKg: number } =>
  isGrid(settings) ? { firstKg: 1, stepKg: 1 } : { firstKg: settings.firstKg, stepKg: settings.stepKg };

/**
 * Grams billed for `chargeG` chargeable grams: the first slab, plus whole steps counted from the end of it.
 * Steps are counted from `firstKg`, never from zero, so the billed weight is always one the price formula
 * (`first + steps × addl`) describes: with a 0.5 kg first slab and 1 kg steps a 1.2 kg parcel is billed as
 * 1.5 kg (first + 1 step), not 2 kg (which `priceService` would have charged as first + 2 steps). When `firstKg`
 * is a multiple of `stepKg` (the usual card) this equals rounding up to the next step.
 */
export function billableFor(settings: Pick<Settings, "pricingMode" | "firstKg" | "stepKg">, chargeG: number): number {
  const eff = effectiveSteps(settings);
  const firstG = kgToG(eff.firstKg);
  const stepG = kgToG(eff.stepKg);
  if (!(chargeG > firstG) || !(stepG > 0)) return firstG;
  return firstG + Math.ceil((chargeG - firstG) / stepG) * stepG;
}

/**
 * True when `g` is a weight the engine can bill: the first slab or the first slab plus whole steps (slab mode),
 * or a whole kilogram (grid mode, which may also bill the next priced kilogram up — see `chargedG`). The quote
 * log uses it to refuse a billed weight no calculator could have produced.
 */
export function isBillableG(settings: Pick<Settings, "pricingMode" | "firstKg" | "stepKg">, g: number): boolean {
  if (!Number.isInteger(g) || g <= 0) return false;
  if (isGrid(settings)) return g % 1000 === 0;
  const firstG = kgToG(settings.firstKg);
  const stepG = kgToG(settings.stepKg);
  if (g === firstG) return true;
  return g > firstG && stepG > 0 && (g - firstG) % stepG === 0;
}

/**
 * The cargo threshold in kg the site actually enforces; 0 = none. `maxKg` when set. In grid mode without
 * `maxKg` the grid itself is the limit: past its heaviest priced kilogram no service can price a parcel, so
 * that kilogram is the threshold and heavier shipments get the cargo message instead of an empty result.
 * Judged over every active destination so the "Over N kg" wording is one number for the whole site.
 */
export function cargoLimitKg(card: Pick<RateCard, "settings" | "destinations">): number {
  const s = card.settings;
  if (s.maxKg > 0) return s.maxKg;
  if (!isGrid(s)) return 0;
  let top = 0;
  for (const d of card.destinations) {
    if (!d.active) continue;
    for (const r of Object.values(d.rates)) {
      for (const [k, v] of Object.entries(r?.grid ?? {})) {
        const kg = Number(k);
        if (isNum(v) && v > 0 && Number.isInteger(kg) && kg > top && kg <= 200) top = kg;
      }
    }
  }
  return top;
}

/**
 * Grams a service actually charged for: the billed weight, or in grid mode the heavier kilogram whose price was
 * used when the billed one has none (a 3 kg parcel on a grid priced at 1, 2 and 5 kg pays the 5 kg price, so it
 * is charged on 5 kg — the quote, the WhatsApp text and the log must say so).
 */
export function chargedG(price: Pick<ServicePrice, "gridKg" | "docRate">, weights: Pick<Weights, "billableG">): number {
  return !price.docRate && price.gridKg != null ? Math.max(weights.billableG, price.gridKg * 1000) : weights.billableG;
}

export function computeWeights(settings: Settings, rows: PieceInput[]): Weights {
  let pieces = 0;
  let actualG = 0;
  let volG = 0;
  let chargeG = 0;
  const lines: WeightLine[] = [];
  for (const r of rows) {
    const kg = Number(r.kg);
    if (!(kg > 0)) continue;
    const qty = Math.max(1, Math.floor(Number(r.qty) || 1));
    const dims = [r.L, r.W, r.H].map(Number) as [number, number, number];
    const v = dims.every((x) => x > 0)
      ? Math.round(((dims[0] * dims[1] * dims[2]) / settings.volumetricDivisor) * 1000)
      : 0;
    const a = kgToG(kg);
    const c = Math.max(a, v);
    pieces += qty;
    actualG += a * qty;
    volG += v * qty;
    chargeG += c * qty;
    lines.push({ qty, kg, L: dims[0], W: dims[1], H: dims[2], actualG: a, volG: v, pieceG: c, volumetricWins: v > a });
  }
  const billableG = billableFor(settings, chargeG);
  return { pieces, actualG, volG, chargeG, billableG, volumetricWins: chargeG > actualG, lines };
}

export function priceService(
  settings: Settings,
  dest: Destination,
  serviceId: string,
  billableG: number,
  type: ShipmentType,
  /** Chargeable grams before step rounding; the document limit is judged on this (a 0.3 kg letter is not a 1 kg parcel). */
  chargeG: number = billableG,
): ServicePrice | null {
  const r = dest.rates[serviceId];
  if (!r) return null;
  const eff = effectiveSteps(settings);
  const firstG = kgToG(eff.firstKg);
  const stepG = kgToG(eff.stepKg);
  let base: number;
  let steps = 0;
  let docRate = false;
  let gridKg: number | undefined;
  // A document limit of 0 means "no document rate": every document is charged like a package. (It used to
  // fall back to the first slab, while the site said "up to 0 kg gets the document rate".)
  const docLimitG = settings.docMaxKg > 0 ? kgToG(settings.docMaxKg) : 0;
  if (type === "doc" && docLimitG > 0 && isNum(r.doc) && r.doc > 0 && Math.min(chargeG, billableG) <= docLimitG) {
    base = r.doc;
    docRate = true;
  } else if (isGrid(settings)) {
    const hit = gridPrice(r.grid, billableG / 1000, settings.maxKg);
    if (!hit) return null;
    base = hit.price;
    gridKg = hit.kg;
  } else {
    if (!isNum(r.first) || !isNum(r.addl)) return null;
    steps = billableG <= firstG ? 0 : Math.ceil((billableG - firstG) / stepG);
    base = r.first + steps * r.addl;
  }
  const tax = (base * (Number(settings.taxPct) || 0)) / 100;
  return {
    first: r.first,
    addl: r.addl,
    doc: r.doc,
    docRate,
    steps,
    gridKg,
    base,
    tax,
    total: roundTo(base + tax, settings.roundTo),
    days: r.days ?? "",
  };
}

export function priceAll(card: RateCard, input: PriceInput): PriceResult {
  const dest = card.destinations.find((x) => x.id === input.destId && x.active);
  if (!dest) return { ok: false, reason: "destination" };
  const rows = input.rows.filter((r) => Number(r.kg) > 0);
  if (!rows.length) return { ok: false, reason: "weight" };
  const weights = computeWeights(card.settings, rows);
  // Judged on the chargeable weight, not the billed one: with a 0.5 kg first slab and 1 kg steps a 25 kg
  // parcel bills as 25.5 kg, and "up to 25 kg" must still price it.
  const maxG = kgToG(cargoLimitKg(card));
  if (maxG && weights.chargeG > maxG) return { ok: false, reason: "overmax", dest, weights };
  const type: ShipmentType = input.type ?? "pkg";
  const prices: Partial<Record<string, ServicePrice | null>> = {};
  for (const s of card.services) prices[s.id] = priceService(card.settings, dest, s.id, weights.billableG, type, weights.chargeG);
  return { ok: true, dest, weights, prices, type };
}

/* ---------- small text helpers shared by settings/content parsing ---------- */

/** Non-empty trimmed lines of a multi-line setting. */
export const lines = (text: string | null | undefined): string[] =>
  String(text ?? "")
    .split(/\r?\n/)
    .map((x) => x.trim())
    .filter(Boolean);

/** Split a `a | b | c` line into at least `n` trimmed parts. */
export function parts(line: string, n: number): string[] {
  const p = line.split("|").map((x) => x.trim());
  while (p.length < n) p.push("");
  return p;
}

/** Lenient number parse for spreadsheet cells: "Rs 4,500/-" → 4500; "-" or "n/a" → null; junk → NaN. */
export function toNumLoose(v: unknown): number | null {
  if (v == null) return null;
  if (typeof v === "number") return Number.isFinite(v) ? v : NaN;
  const s = String(v).trim();
  if (!s || /^[-–—]$/.test(s) || /^n\/?a$/i.test(s)) return null;
  const m = s.replace(/,/g, "").match(/-?\d+(\.\d+)?/);
  return m ? Number(m[0]) : NaN;
}

export function addonsList(settings: Pick<Settings, "addons">): Addon[] {
  const out: Addon[] = [];
  lines(settings.addons).forEach((ln, i) => {
    const p = parts(ln, 3);
    const amt = toNumLoose(p[1]);
    if (!p[0] || !isNum(amt) || amt < 0) return;
    out.push({ id: `a${i}`, label: p[0], amount: amt, on: /^(on|yes|true|1|checked|default)$/i.test(p[2]) });
  });
  return out;
}

/**
 * Derive whole-kilogram grid prices from slab prices (first slab + steps),
 * so switching a document to grid mode keeps the same prices until the
 * boxes are edited. Existing grid values are kept.
 */
export function slabToGrid(settings: Settings, rate: { first?: number | null; addl?: number | null; grid?: Record<string, number> }): Record<string, number> | undefined {
  const out: Record<string, number> = { ...(rate.grid ?? {}) };
  if (isNum(rate.first) && isNum(rate.addl)) {
    const firstG = kgToG(settings.firstKg);
    const stepG = kgToG(settings.stepKg);
    // A grid box holds the price before tax, and the engine taxes and rounds it again. Rounding the box too
    // only reproduces the slab total when there is no tax (rounding is then idempotent); with tax it rounded
    // twice (4,560 → 4,600 + 10% → 5,100 instead of 5,016 → 5,000), so the box keeps the exact slab base then.
    const taxed = (Number(settings.taxPct) || 0) !== 0;
    for (const kg of gridWeights(settings)) {
      if (isNum(out[String(kg)])) continue;
      const g = kg * 1000;
      const steps = g <= firstG ? 0 : Math.ceil((g - firstG) / stepG);
      const base = rate.first + steps * rate.addl;
      out[String(kg)] = taxed ? base : roundTo(base, settings.roundTo);
    }
  }
  return Object.keys(out).length ? out : undefined;
}
