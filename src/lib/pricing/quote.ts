/**
 * Quote objects and the WhatsApp message built from them. Pure so the same
 * text is produced on the site, in the admin's test panel and in tests.
 */
import { addonsList, gToKg } from "./engine";
import { fmtMoney } from "./format";
import type { Addon, Settings, ShipmentType, Weights } from "./types";

export const LB = 0.45359237;
export const IN = 2.54;
export type Units = "metric" | "imperial";

export interface Quote {
  id: string;
  from: string;
  dest: string;
  destId: string;
  type: ShipmentType;
  service: string;
  serviceId: string;
  days: string;
  /** Formatted delivery window, "" when estimates are off. */
  eta: string;
  pickup: string;
  pieces: number;
  piecesText: string;
  billableG: number;
  /** Shipping price before add-ons. */
  total: number;
  docRate: boolean;
  version: number;
}

const f2 = (v: number): string => String(Math.round(v * 100) / 100);

/** "2 × 1 kg (30×20×10 cm); 1 × 0.5 kg" in the customer's units. */
export function piecesText(w: Weights, units: Units): string {
  const imp = units === "imperial";
  return w.lines
    .map((l) => {
      const kg = imp ? `${f2(l.kg / LB)} lb` : `${f2(l.kg)} kg`;
      const dims = l.volG
        ? ` (${imp ? [l.L, l.W, l.H].map((x) => f2(x / IN)).join("×") + " in" : [l.L, l.W, l.H].map(f2).join("×") + " cm"})`
        : "";
      return `${l.qty} × ${kg}${dims}`;
    })
    .join("; ");
}

/** One sentence explaining what weight the price is charged on. */
export function weightSentence(w: Weights, settings: Pick<Settings, "stepKg">): string {
  let txt = `Charged on ${gToKg(w.billableG)} kg${w.pieces > 1 ? ` for ${w.pieces} pieces` : ""}`;
  if (w.volumetricWins) txt += ` — volumetric weight (${gToKg(w.chargeG)} kg) is higher than actual (${gToKg(w.actualG)} kg).`;
  else if (w.billableG !== w.chargeG) txt += ` (${gToKg(w.actualG)} kg, rounded up to the next ${settings.stepKg} kg).`;
  else txt += ".";
  return txt;
}

export interface QuoteTextInput {
  companyName: string;
  currency: string;
  addons: Addon[];
  contents?: string;
}

/** "Pickup and service charges" → "pickup and service charges", so an add-on's label reads on inside "Includes PKR 500 …"; an acronym keeps its case. */
export const midSentence = (s: string): string => s.replace(/^[A-Z](?=[a-z])/, (c) => c.toLowerCase());

/** The one number the customer booked: shipping plus every add-on that was on. */
export const bookedTotal = (shipping: number, addons: readonly Pick<Addon, "amount">[]): number => addons.reduce((t, a) => t + a.amount, shipping);

/**
 * The add-ons a booking named, resolved against the settings the price was
 * computed from, so their amounts are the document's and never the browser's.
 * Labels the document no longer has are dropped; order follows the settings.
 */
export function resolveAddons(settings: Pick<Settings, "addons">, labels: readonly string[] | undefined): Addon[] {
  if (!labels?.length) return [];
  const want = new Set(labels);
  return addonsList(settings).filter((a) => want.has(a.label));
}

export function quoteText(q: Quote, ctx: QuoteTextInput): string {
  const out = [`Hi ${ctx.companyName}, I want to book a shipment.`, `Quote: ${q.id}`];
  if (q.from) out.push(`From: ${q.from}`);
  out.push(`To: ${q.dest}`, `Service: ${q.service}${q.days ? ` (${q.days} working days)` : ""}`);
  if (q.pickup) out.push(`Pickup: ${q.pickup}`);
  if (q.eta) out.push(`Estimated delivery: ${q.eta}`);
  out.push(`${q.type === "doc" ? "Documents" : "Packages"}: ${q.piecesText}`, `Charged on: ${gToKg(q.billableG)} kg`);
  if (ctx.addons.length) {
    out.push(`Shipping: ${fmtMoney(q.total, ctx.currency)}`);
    for (const a of ctx.addons) out.push(`${a.label}: ${fmtMoney(a.amount, ctx.currency)}`);
    out.push(`Total: ${fmtMoney(bookedTotal(q.total, ctx.addons), ctx.currency)}`);
  } else out.push(`Price: ${fmtMoney(q.total, ctx.currency)}`);
  const c = ctx.contents?.trim();
  if (c) out.push(`Contents: ${c}`);
  return out.join("\n");
}

export interface LeadSummaryInput {
  service: string;
  dest: string;
  billableG: number;
  /** Shipping price before add-ons, as the engine computed it. */
  shipping: number;
  addons: readonly Addon[];
  currency: string;
  piecesText?: string;
}

/**
 * The inbox line for a booked quote. Its price is the Total the customer's
 * WhatsApp message carries, with what is inside it named the way the site
 * names it, so the owner and the customer never read two different numbers:
 * "Express to Canada · 5 kg · PKR 19,620 (includes PKR 500 pickup and service charges) · 1 × 5 kg".
 */
export function leadSummary(i: LeadSummaryInput): string {
  const inside = i.addons.length ? ` (includes ${i.addons.map((a) => `${fmtMoney(a.amount, i.currency)} ${midSentence(a.label)}`).join(", ")})` : "";
  let s = `${i.service} to ${i.dest} · ${gToKg(i.billableG)} kg · ${fmtMoney(bookedTotal(i.shipping, i.addons), i.currency)}${inside}`;
  if (i.piecesText) s += ` · ${i.piecesText}`;
  return s;
}

export function waLink(whatsapp: string, text: string): string {
  return `https://wa.me/${whatsapp}?text=${encodeURIComponent(text)}`;
}

/** Message for shipments above the cargo threshold. */
export function cargoText(companyName: string, from: string, dest: string, w: Weights, units: Units): string {
  return (
    `Hi ${companyName}, I need a cargo rate.` +
    (from ? `\nFrom: ${from}` : "") +
    `\nTo: ${dest}\nPieces: ${piecesText(w, units)}\nChargeable: about ${gToKg(w.billableG)} kg`
  );
}
