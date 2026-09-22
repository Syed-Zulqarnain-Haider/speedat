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

export function roundTo(n: number, r: number): number {
  const step = Number(r) || 1;
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

export function computeWeights(settings: Settings, rows: PieceInput[]): Weights {
  const eff = effectiveSteps(settings);
  const stepG = kgToG(eff.stepKg);
  const firstG = kgToG(eff.firstKg);
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
  const billableG = Math.max(firstG, Math.ceil(chargeG / stepG) * stepG);
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
  const docLimitG = kgToG(settings.docMaxKg > 0 ? settings.docMaxKg : eff.firstKg);
  if (type === "doc" && isNum(r.doc) && r.doc > 0 && Math.min(chargeG, billableG) <= docLimitG) {
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
  const maxG = card.settings.maxKg > 0 ? kgToG(card.settings.maxKg) : 0;
  if (maxG && weights.billableG > maxG) return { ok: false, reason: "overmax", dest, weights };
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
    for (const kg of gridWeights(settings)) {
      if (isNum(out[String(kg)])) continue;
      const g = kg * 1000;
      const steps = g <= firstG ? 0 : Math.ceil((g - firstG) / stepG);
      out[String(kg)] = roundTo(rate.first + steps * rate.addl, settings.roundTo);
    }
  }
  return Object.keys(out).length ? out : undefined;
}
