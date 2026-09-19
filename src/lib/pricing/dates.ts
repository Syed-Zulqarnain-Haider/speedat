/**
 * Transit-time parsing and delivery-date estimates. Pure; `now` is injected
 * so estimates are testable and so the server and browser agree.
 */
import type { Settings } from "./types";

const DAY_NAMES = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"] as const;

/** "3–5", "3 to 5 days", "4" → [min, max]; nothing numeric → null. */
export function parseDaysRange(str: string | null | undefined): [number, number] | null {
  const m = String(str ?? "").match(/\d+/g);
  if (!m) return null;
  const a = Number(m[0]);
  const b = m[1] != null ? Number(m[1]) : a;
  return [Math.min(a, b), Math.max(a, b)];
}

/** Normalise a typed transit time: "3 to 5 working days" → "3–5". */
export function parseDays(v: unknown): string {
  const s = String(v ?? "").trim();
  if (!s) return "";
  return s
    .replace(/\s*(working|business)?\s*days?\s*$/i, "")
    .replace(/\s*(-|to|–|—)\s*/g, "–")
    .trim();
}

/** Working days as a set of JS day indexes (0 = Sunday). Defaults to Mon–Fri when nothing parses. */
export function workingSet(settings: Pick<Settings, "workingDays">): Set<number> {
  const set = new Set<number>();
  let txt = String(settings.workingDays ?? "").toLowerCase();
  // Expand ranges such as "mon-sat" or "monday to friday".
  txt = txt.replace(
    /(sun|mon|tue|wed|thu|fri|sat)[a-z]*\s*(?:-|–|—|to|through|till|until)\s*(sun|mon|tue|wed|thu|fri|sat)[a-z]*/g,
    (_m, a: string, b: string) => {
      let i = DAY_NAMES.indexOf(a as (typeof DAY_NAMES)[number]);
      const j = DAY_NAMES.indexOf(b as (typeof DAY_NAMES)[number]);
      const out: string[] = [];
      let guard = 0;
      while (guard++ < 8) {
        out.push(DAY_NAMES[i]);
        if (i === j) break;
        i = (i + 1) % 7;
      }
      return out.join(" ");
    },
  );
  for (const t of txt.split(/[^a-z]+/)) {
    const i = DAY_NAMES.indexOf(t.slice(0, 3) as (typeof DAY_NAMES)[number]);
    if (i >= 0) set.add(i);
  }
  if (!set.size) [1, 2, 3, 4, 5].forEach((i) => set.add(i));
  return set;
}

export function addDays(dt: Date, n: number): Date {
  const x = new Date(dt.getFullYear(), dt.getMonth(), dt.getDate());
  x.setDate(x.getDate() + n);
  return x;
}

export interface DeliveryEstimate {
  /** First working day the parcel can be collected. */
  pickup: Date;
  from: Date;
  to: Date;
  /** True when pickup moved off the requested day (cutoff passed or non-working day). */
  moved: boolean;
  range: [number, number];
}

export function estimateDelivery(
  daysStr: string | null | undefined,
  settings: Pick<Settings, "workingDays" | "cutoffHour">,
  shipDate: Date | null,
  now: Date,
): DeliveryEstimate | null {
  const range = parseDaysRange(daysStr);
  if (!range) return null;
  const ws = workingSet(settings);
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  let start = shipDate ? new Date(shipDate.getFullYear(), shipDate.getMonth(), shipDate.getDate()) : today;
  if (start < today) start = today;
  let moved = false;
  const cutoff = settings.cutoffHour;
  if (start.getTime() === today.getTime() && cutoff != null && Number.isFinite(cutoff) && now.getHours() >= cutoff) {
    start = addDays(start, 1);
    moved = true;
  }
  let guard = 0;
  while (!ws.has(start.getDay()) && guard++ < 14) {
    start = addDays(start, 1);
    moved = true;
  }
  const addWorking = (d: Date, n: number): Date => {
    let x = d;
    let c = 0;
    let g = 0;
    while (c < n && g++ < 400) {
      x = addDays(x, 1);
      if (ws.has(x.getDay())) c++;
    }
    return x;
  };
  return { pickup: start, from: addWorking(start, range[0]), to: addWorking(start, range[1]), moved, range };
}
