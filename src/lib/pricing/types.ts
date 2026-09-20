/**
 * Rate-card model. A published version of this whole object is what the
 * customer site prices against; the admin edits a draft of it.
 * Ported from the Speedat prototype (schema 5) — field names are kept so the
 * prototype's data and import profiles load unchanged.
 */

export interface Service {
  id: string;
  name: string;
  /** One-line description shown under the service name before a price exists. */
  note: string;
}

/** Prices a destination charges for one service. `null`/missing = not offered. */
export interface Rate {
  /** Price of the first weight slab (`settings.firstKg`). */
  first?: number | null;
  /** Price of each additional `settings.stepKg` step. */
  addl?: number | null;
  /** Transit time as typed, e.g. "3–5"; parsed with `parseDaysRange`. */
  days?: string;
  /** Flat price for documents up to `settings.docMaxKg`; blank = charge like a package. */
  doc?: number | null;
  /** Grid pricing: price per whole kilogram, keyed "1", "2", … up to the cargo threshold. Used when `settings.pricingMode === "grid"`. */
  grid?: Record<string, number>;
}

export interface Destination {
  id: string;
  name: string;
  /** Hidden destinations keep their rates but are not shown or priced on the site. */
  active: boolean;
  rates: Partial<Record<string, Rate>>;
}

export type PricingMode = "slab" | "grid";

export interface Settings {
  /** "slab": first slab + per-step price. "grid": a price per whole kg from 1 kg to the cargo threshold. */
  pricingMode: PricingMode;
  /** Label printed with prices, e.g. "PKR". */
  currency: string;
  /** L×W×H in cm ÷ this = volumetric kg. */
  volumetricDivisor: number;
  firstKg: number;
  stepKg: number;
  /** Document rate applies up to this weight (kg). */
  docMaxKg: number;
  taxPct: number;
  /** Final price is rounded to the nearest multiple of this. */
  roundTo: number;
  /** Above this billable weight the site asks the customer to message for a cargo rate; 0 = no limit. */
  maxKg: number;
  showEta: boolean;
  /** Same-day pickup cutoff, 0–23; null = none. */
  cutoffHour: number | null;
  /** Day names counted for delivery estimates, e.g. "Mon, Tue, Wed, Thu, Fri, Sat". */
  workingDays: string;
  /** Dates that are not working days, one per line: `YYYY-MM-DD` optionally followed by `| label`. */
  holidays: string;
  /** Optional charges, one per line: `Label | Amount | on`. */
  addons: string;
  disclaimer: string;
}

export interface RateCard {
  services: Service[];
  destinations: Destination[];
  settings: Settings;
}

export type ShipmentType = "pkg" | "doc";

/** One line of the detailed form: `qty` identical pieces of `kg` each, dims in cm. */
export interface PieceInput {
  kg: number | null;
  qty?: number | null;
  L?: number | null;
  W?: number | null;
  H?: number | null;
}

export interface PriceInput {
  destId: string;
  type?: ShipmentType;
  rows: PieceInput[];
}

export interface WeightLine {
  qty: number;
  kg: number;
  L: number;
  W: number;
  H: number;
  actualG: number;
  volG: number;
  /** Chargeable grams for one piece: max(actual, volumetric). */
  pieceG: number;
  volumetricWins: boolean;
}

export interface Weights {
  pieces: number;
  actualG: number;
  volG: number;
  /** Sum of chargeable grams before rounding up to the step. */
  chargeG: number;
  /** Grams actually billed: rounded up to the next step, at least the first slab. */
  billableG: number;
  volumetricWins: boolean;
  lines: WeightLine[];
}

export interface ServicePrice {
  first: number | null | undefined;
  addl: number | null | undefined;
  doc: number | null | undefined;
  docRate: boolean;
  steps: number;
  /** Grid mode: the kilogram whose price was charged (billable weight rounded up to a priced kg). */
  gridKg?: number;
  base: number;
  tax: number;
  total: number;
  days: string;
}

export type PriceResult =
  | { ok: false; reason: "destination" | "weight" }
  | { ok: false; reason: "overmax"; dest: Destination; weights: Weights }
  | {
      ok: true;
      dest: Destination;
      weights: Weights;
      prices: Partial<Record<string, ServicePrice | null>>;
      type: ShipmentType;
    };

export interface Addon {
  id: string;
  label: string;
  amount: number;
  /** Ticked by default. */
  on: boolean;
}
