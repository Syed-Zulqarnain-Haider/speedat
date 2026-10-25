/**
 * POST /api/quotes — log a quote the customer acted on. Best-effort: the
 * browser fires it with keepalive when Book is tapped and never waits for
 * it. Input is validated and capped; the price is recomputed server-side
 * against the live document (shipping from the engine, from the billed and
 * the chargeable weight the browser priced on; add-ons by label from the
 * settings) so a tampered total is stored as what the engine says, not
 * what the client sent. The stored `total` is the one number the
 * customer booked — shipping plus the add-ons that were on, the "Total" of
 * their WhatsApp message — with the parts itemised in `detail`.
 *
 * The billed weight is checked, not trusted: it must be one the engine can
 * bill (`isBillableG`) and within the cargo threshold (`cargoLimitKg`), or the
 * quote is refused — a forged 0.5 kg "billable" for a 40 kg box would
 * otherwise be logged and booked at the 0.5 kg price. An id is logged once:
 * a later POST with the same id may update the booked flag, add-ons and
 * total of that same quote, but never its destination, service, type or
 * weight, so a reused or colliding id cannot rewrite another customer's.
 */
import { and, eq } from "drizzle-orm";
import { NextResponse } from "next/server";
import { z } from "zod";
import { db, schema } from "@/lib/db";
import { upsertQuoteLead } from "@/lib/leads";
import { clientIp, rateLimit } from "@/lib/limits";
import { billableFor, cargoLimitKg, isBillableG, kgToG, priceService } from "@/lib/pricing/engine";
import { QUOTE_ID_RE } from "@/lib/pricing/format";
import { bookedTotal, leadSummary, resolveAddons } from "@/lib/pricing/quote";
import { getLiveHold, getLiveSite } from "@/lib/site/live";

const Body = z.object({
  /** SP-YYMMDD- and an 8-character tail; the 4-character tail of tabs opened before the change is still accepted. */
  id: z.string().regex(QUOTE_ID_RE),
  destId: z.string().max(60),
  serviceId: z.string().max(40),
  type: z.enum(["pkg", "doc"]),
  billableG: z.number().int().min(1).max(1_000_000),
  /**
   * Chargeable grams before step rounding, the weight the engine judges the document limit on. Without it a
   * 0.3 kg letter billed as 1 kg is re-priced as a 1 kg parcel. Optional so a tab opened before this field
   * existed still logs its quote; it then falls back to the billed weight, as before.
   */
  chargeG: z.number().int().min(1).max(1_000_000).optional(),
  /** The number the customer's screen showed: shipping plus the add-ons that were on. Kept only as a note when it disagrees with the engine. */
  total: z.number().min(0).max(100_000_000),
  version: z.number().int().min(0),
  piecesText: z.string().max(600).optional(),
  eta: z.string().max(80).optional(),
  pickup: z.string().max(40).optional(),
  from: z.string().max(80).optional(),
  booked: z.boolean().optional(),
  mode: z.enum(["quick", "detail"]).optional(),
  /** Labels of the add-ons that were on; amounts are never taken from the client. */
  addons: z.array(z.string().max(80)).max(20).optional(),
  contents: z.string().max(120).optional(),
});

export async function POST(req: Request) {
  const limit = await rateLimit("quotes", await clientIp(), 60, 60);
  if (!limit.ok) {
    return NextResponse.json({ error: { code: "rate_limited", message: "Too many requests" } }, { status: 429, headers: { "retry-after": String(limit.retryAfter) } });
  }
  let parsed: z.infer<typeof Body>;
  try {
    parsed = Body.parse(await req.json());
  } catch {
    return NextResponse.json({ error: { code: "bad_request", message: "Invalid quote" } }, { status: 400 });
  }
  const [site, hold] = await Promise.all([getLiveSite(), getLiveHold()]);
  // No quotes are issued while prices are on hold: nothing on the site offers one, so any arriving here is stale or forged.
  if (hold.on) return NextResponse.json({ error: { code: "held", message: "Prices are being updated" } }, { status: 409 });
  const dest = site.destinations.find((d) => d.id === parsed.destId && d.active);
  const service = site.services.find((s) => s.id === parsed.serviceId);
  if (!dest || !service) return NextResponse.json({ error: { code: "not_found", message: "Unknown destination or service" } }, { status: 404 });
  const { id, destId, serviceId, type, billableG, chargeG: clientChargeG, total: clientTotal, version, booked, addons: addonLabels, ...rest } = parsed;
  if (!isBillableG(site.settings, billableG)) {
    return NextResponse.json({ error: { code: "bad_weight", message: "Not a weight the rate card bills" } }, { status: 422 });
  }
  const limitG = kgToG(cargoLimitKg(site));
  // The heaviest billed weight a parcel within the threshold can have (25 kg bills as 25.5 kg on a 0.5 + 1 kg card).
  if (limitG && billableG > billableFor(site.settings, limitG)) {
    return NextResponse.json({ error: { code: "overmax", message: "Over the cargo threshold; priced on WhatsApp" } }, { status: 422 });
  }
  // The chargeable weight never exceeds the billed one (the billed weight is the chargeable one rounded up).
  const chargeG = Math.min(clientChargeG ?? billableG, billableG);
  const price = priceService(site.settings, dest, serviceId, billableG, type, chargeG);
  if (!price) return NextResponse.json({ error: { code: "not_found", message: "Service not offered" } }, { status: 404 });
  const addons = resolveAddons(site.settings, addonLabels);
  const total = bookedTotal(price.total, addons);
  const detail: Record<string, unknown> = { ...rest, chargeG, shipping: price.total, docRate: price.docRate, addons: addons.map((a) => ({ label: a.label, amount: a.amount })) };
  if (Math.round(clientTotal) !== total) detail.clientTotal = clientTotal;
  const q = schema.quotes;
  try {
    const saved = await db
      .insert(q)
      .values({ id, destId, serviceId, type, billableG, total, version, booked: !!booked, detail })
      .onConflictDoUpdate({
        target: q.id,
        set: { booked: !!booked, total, detail },
        // Only the same quote is updated (add-ons ticked, Book tapped); an id already logged for another
        // destination, service, type or weight is left alone and the request refused.
        setWhere: and(eq(q.destId, destId), eq(q.serviceId, serviceId), eq(q.type, type), eq(q.billableG, billableG)),
      })
      .returning({ id: q.id });
    if (!saved.length) return NextResponse.json({ error: { code: "conflict", message: "Quote id already used" } }, { status: 409 });
  } catch (err) {
    console.error("quote log failed", err);
    return NextResponse.json({ error: { code: "unavailable", message: "Could not save the quote" } }, { status: 503 });
  }
  if (booked) {
    // A tapped Book button is a lead for the inbox; the customer's WhatsApp message is the conversation.
    try {
      await upsertQuoteLead({
        quoteId: id,
        destId,
        weightG: billableG,
        summary: leadSummary({ service: service.name, dest: dest.name, billableG, shipping: price.total, addons, currency: site.settings.currency, piecesText: rest.piecesText }),
        contents: rest.contents,
      });
    } catch (err) {
      console.error("lead upsert failed", err);
    }
  }
  return NextResponse.json({ ok: true });
}
