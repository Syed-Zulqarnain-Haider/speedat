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
 */
import { NextResponse } from "next/server";
import { z } from "zod";
import { db, schema } from "@/lib/db";
import { upsertQuoteLead } from "@/lib/leads";
import { clientIp, rateLimit } from "@/lib/limits";
import { priceService } from "@/lib/pricing/engine";
import { bookedTotal, leadSummary, resolveAddons } from "@/lib/pricing/quote";
import { getLiveHold, getLiveSite } from "@/lib/site/live";

const Body = z.object({
  id: z.string().regex(/^SP-\d{6}-[A-Z0-9]{4}$/),
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
  // The chargeable weight never exceeds the billed one (the billed weight is the chargeable one rounded up).
  const chargeG = Math.min(clientChargeG ?? billableG, billableG);
  const price = priceService(site.settings, dest, serviceId, billableG, type, chargeG);
  if (!price) return NextResponse.json({ error: { code: "not_found", message: "Service not offered" } }, { status: 404 });
  const addons = resolveAddons(site.settings, addonLabels);
  const total = bookedTotal(price.total, addons);
  const detail: Record<string, unknown> = { ...rest, chargeG, shipping: price.total, docRate: price.docRate, addons: addons.map((a) => ({ label: a.label, amount: a.amount })) };
  if (Math.round(clientTotal) !== total) detail.clientTotal = clientTotal;
  try {
    await db
      .insert(schema.quotes)
      .values({ id, destId, serviceId, type, billableG, total, version, booked: !!booked, detail })
      .onConflictDoUpdate({ target: schema.quotes.id, set: { booked: !!booked, total, detail } });
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
