/** Display formatting shared by the site, the WhatsApp message and the admin. */
import { businessNow } from "@/lib/time";
import type { DeliveryEstimate } from "./dates";

export const fmtNum = (n: number): string => new Intl.NumberFormat("en-US").format(Math.round(n));

export const fmtMoney = (n: number, currency: string): string => `${currency} ${fmtNum(n)}`;

export const fmtDay = (dt: Date): string =>
  dt.toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short" });

export const fmtRange = (est: DeliveryEstimate): string =>
  est.from.getTime() === est.to.getTime() ? fmtDay(est.from) : `${fmtDay(est.from)} – ${fmtDay(est.to)}`;

export function fmtDate(iso: string | Date): string {
  const dt = new Date(iso);
  return Number.isNaN(dt.getTime()) ? "" : dt.toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
}

export function fmtDateTime(iso: string | Date): string {
  const dt = new Date(iso);
  return Number.isNaN(dt.getTime())
    ? ""
    : dt.toLocaleString("en-GB", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

/** "923157667076" → "+92 315 766 7076"; anything else → "+<digits>". */
export function fmtPhone(n: string | null | undefined): string {
  const s = String(n ?? "");
  const m = s.match(/^92(3\d{2})(\d{3})(\d{4})$/);
  return m ? `+92 ${m[1]} ${m[2]} ${m[3]}` : `+${s}`;
}

/** 15 → "3 pm", 0 → "12 am". */
export function fmtHour(h: number | null | undefined): string {
  if (h == null) return "";
  const n = Number(h);
  if (!Number.isFinite(n)) return "";
  const ap = n >= 12 ? "pm" : "am";
  const x = n % 12;
  return `${x === 0 ? 12 : x} ${ap}`;
}

/** Local calendar date → "YYYY-MM-DD" for `<input type="date">`. */
export function inputDate(dt: Date): string {
  const p = (n: number) => String(n).padStart(2, "0");
  return `${dt.getFullYear()}-${p(dt.getMonth() + 1)}-${p(dt.getDate())}`;
}

/** "YYYY-MM-DD" → local Date, or null when malformed. */
export function localDateFromInput(v: string | null | undefined): Date | null {
  const m = String(v ?? "").match(/^(\d{4})-(\d{2})-(\d{2})$/);
  return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
}

/** Characters in a quote id's random tail. Four (36^4 ≈ 1.7 M a day) collided often enough to overwrite another customer's logged quote; eight is 36^8 ≈ 2.8 × 10^12. */
export const QUOTE_TAIL = 8;

/** Every quote id the log accepts: today's eight-character tail, and the four-character one tabs opened before the change still send. */
export const QUOTE_ID_RE = /^SP-\d{6}-(?:[A-Z0-9]{4}|[A-Z0-9]{8})$/;

const date6 = (now: Date): string => {
  const p = (n: number) => String(n).padStart(2, "0");
  return `${String(now.getFullYear()).slice(2)}${p(now.getMonth() + 1)}${p(now.getDate())}`;
};

/** A random tail of `QUOTE_TAIL` base-36 characters, from two draws so it never runs short of digits. */
function randomTail(rand: () => number): string {
  const a = Math.floor(rand() * 36 ** 4).toString(36).padStart(4, "0");
  const b = Math.floor(rand() * 36 ** 4).toString(36).padStart(4, "0");
  return (a + b).toUpperCase().slice(0, QUOTE_TAIL);
}

/** Quote reference such as SP-260920-K7QX2M9A: dated by the office's calendar, short enough to read out on the phone. */
export function quoteId(now: Date = businessNow(), rand: () => number = Math.random): string {
  return `SP-${date6(now)}-${randomTail(rand)}`;
}

/**
 * Deterministic quote id for a given seed (e.g. a per-tab salt plus the
 * destination, weight and service): the same combination shows the same id
 * across re-renders without any state, while different tabs get different ids.
 */
export function quoteIdFor(seed: string, now: Date = businessNow()): string {
  // Two independent 32-bit FNV-1a hashes (different offset bases), four base-36 characters from each.
  const fnv = (basis: number): number => {
    let h = basis;
    for (let i = 0; i < seed.length; i++) {
      h ^= seed.charCodeAt(i);
      h = Math.imul(h, 0x01000193) >>> 0;
    }
    return h;
  };
  const four = (h: number) => (h % 36 ** 4).toString(36).padStart(4, "0");
  return `SP-${date6(now)}-${(four(fnv(0x811c9dc5)) + four(fnv(0x050c5d1f))).toUpperCase()}`;
}

/** Lower-case, accent-free key used to match destination names across sheets ("Türkiye" ≡ "turkiye", "U.K." ≡ "u k"). */
export const nameKey = (name: string | null | undefined): string =>
  String(name ?? "")
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, " ")
    .trim();

/** URL/ID-safe slug, ASCII only, max 40 chars. */
export function slug(name: string): string {
  return (
    String(name)
      .toLowerCase()
      .normalize("NFD")
      .replace(/[̀-ͯ]/g, "")
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-|-$/g, "")
      .slice(0, 40) || "dest"
  );
}
