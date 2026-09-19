/** Display formatting shared by the site, the WhatsApp message and the admin. */
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

/** Quote reference such as SP-260920-K7QX: date-stamped, short enough to read out on the phone. */
export function quoteId(now: Date = new Date(), rand: () => number = Math.random): string {
  const p = (n: number) => String(n).padStart(2, "0");
  const tail = rand().toString(36).slice(2, 6).toUpperCase().padEnd(4, "0");
  return `SP-${String(now.getFullYear()).slice(2)}${p(now.getMonth() + 1)}${p(now.getDate())}-${tail}`;
}

/**
 * Deterministic quote id for a given seed (e.g. a per-tab salt plus the
 * destination, weight and service): the same combination shows the same id
 * across re-renders without any state, while different tabs get different ids.
 */
export function quoteIdFor(seed: string, now: Date = new Date()): string {
  let h = 0x811c9dc5;
  for (let i = 0; i < seed.length; i++) {
    h ^= seed.charCodeAt(i);
    h = Math.imul(h, 0x01000193) >>> 0;
  }
  const tail = (h % 1679616).toString(36).toUpperCase().padStart(4, "0"); // 36^4 combinations
  return quoteId(now, () => 0).slice(0, -4) + tail;
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
