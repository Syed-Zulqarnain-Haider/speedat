/**
 * The business clock. Cutoffs, delivery estimates, "today" for the intake
 * check and once-a-day alerts are all judged in the office's time zone, never
 * the browser's or the server's (Vercel runs in UTC). Pure and isomorphic.
 */

/** IANA zone the office works in; override with NEXT_PUBLIC_BUSINESS_TZ. */
export const BUSINESS_TZ = process.env.NEXT_PUBLIC_BUSINESS_TZ || "Asia/Karachi";

export interface ZonedParts {
  year: number;
  /** 1–12 */
  month: number;
  day: number;
  /** 0–23 */
  hour: number;
  minute: number;
}

/** Wall-clock parts of `at` in `tz`. */
export function zonedParts(at: Date, tz: string = BUSINESS_TZ): ZonedParts {
  const f = new Intl.DateTimeFormat("en-GB", { timeZone: tz, year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hourCycle: "h23" });
  const p = Object.fromEntries(f.formatToParts(at).map((x) => [x.type, x.value]));
  return { year: Number(p.year), month: Number(p.month), day: Number(p.day), hour: Number(p.hour), minute: Number(p.minute) };
}

/**
 * `at` re-expressed as a local Date whose calendar fields (getFullYear,
 * getDate, getHours…) read the office's wall clock. Use it as the `now`
 * handed to the date maths, so a visitor in Toronto sees Lahore's cutoff.
 */
export function businessNow(at: Date = new Date(), tz: string = BUSINESS_TZ): Date {
  const p = zonedParts(at, tz);
  return new Date(p.year, p.month - 1, p.day, p.hour, p.minute);
}

/** "YYYY-MM-DD" of `at` in the office's zone. */
export function businessDayKey(at: Date = new Date(), tz: string = BUSINESS_TZ): string {
  const p = zonedParts(at, tz);
  return `${p.year}-${String(p.month).padStart(2, "0")}-${String(p.day).padStart(2, "0")}`;
}
