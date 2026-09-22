/**
 * The detailed view's piece rows: what the customer typed, in the unit system
 * they typed it in.
 *
 * Switching units never rewrites a typed value. The price is always computed
 * from it in its own unit, and the other system only sees a two-decimal
 * rendering of it. The toggle used to convert every field in place, rounded,
 * in each direction: 40 × 30 × 20 cm came back as 40.01 × 30 × 19.99 cm and
 * that is what the courier then read; worse, 4 kg shown as 8.82 lb priced as
 * 4.0007 kg and was billed as 5 kg, and 25 kg shown as 55.12 lb tipped into
 * the cargo message.
 */
import { toNumLoose } from "@/lib/pricing/engine";
import { IN, LB, type Units } from "@/lib/pricing/quote";
import type { PieceInput, ShipmentType } from "@/lib/pricing/types";

/** A field as the customer typed it, with the unit system it was typed in. */
export interface Entry {
  v: string;
  u: Units;
}

export interface PieceRow {
  key: number;
  kg: Entry;
  qty: string;
  L: Entry;
  W: Entry;
  H: Entry;
}

/** The fields that carry a unit. */
export type Measure = "kg" | "L" | "W" | "H";

/** Metric units per imperial unit for each measured field. */
export const FACTOR: Record<Measure, number> = { kg: LB, L: IN, W: IN, H: IN };

export const entry = (v = "", u: Units = "metric"): Entry => ({ v, u });

/** A fresh row; `kg` is the remembered kilograms from the quick view, so it is metric. */
export const newRow = (key: number, kg = ""): PieceRow => ({ key, kg: entry(kg), qty: "1", L: entry(), W: entry(), H: entry() });

/** The typed number, or null when the field is empty or not a number. */
export function num(s: string): number | null {
  const v = toNumLoose(s);
  return v == null || Number.isNaN(v) ? null : v;
}

const round2 = (v: number): string => String(Math.round(v * 100) / 100);

/**
 * The entry's number in kilograms or centimetres, whatever unit it was typed in.
 * Only a positive number is converted; the engine ignores the rest.
 */
export function metricValue(e: Entry, field: Measure): number | null {
  const v = num(e.v);
  return v != null && v > 0 && e.u === "imperial" ? v * FACTOR[field] : v;
}

/**
 * What the field shows in the current unit system: the typed text itself when it
 * was typed in that system, otherwise a two-decimal rendering of the conversion.
 */
export function shown(e: Entry, units: Units, field: Measure): string {
  if (e.u === units) return e.v;
  const v = num(e.v);
  if (v == null || !(v > 0)) return e.v;
  const k = FACTOR[field];
  return round2(units === "imperial" ? v / k : v * k);
}

/** The engine's rows: metric numbers, sizes dropped for documents. */
export function toRows(pieces: PieceRow[], type: ShipmentType): PieceInput[] {
  return pieces.map((pc) => ({
    kg: metricValue(pc.kg, "kg"),
    qty: num(pc.qty) ?? 1,
    L: type === "doc" ? null : metricValue(pc.L, "L"),
    W: type === "doc" ? null : metricValue(pc.W, "W"),
    H: type === "doc" ? null : metricValue(pc.H, "H"),
  }));
}
