/**
 * POST /api/quotes — log a quote the customer acted on. Best-effort: the
 * browser fires it with keepalive when Book is tapped and never waits for
 * it. Input is validated and capped; the price is recomputed server-side
 * against the live document so a tampered total is stored as what the
 * engine says, not what the client sent.
 */
import { NextResponse } from "next/server";
import { z } from "zod";
import { db, schema } from "@/lib/db";
import { upsertQuoteLead } from "@/lib/leads";
import { clientIp, rateLimit } from "@/lib/limits";
import { fmtMoney } from "@/lib/pricing/format";
import { gToKg, priceService } from "@/lib/pricing/engine";
import { getLiveSite } from "@/lib/site/live";

const Body = z.object({
  id: z.string().regex(/^SP-\d{6}-[A-Z0-9]{4}$/),
  destId: z.string().max(60),
  serviceId: z.string().max(40),
  type: z.enum(["pkg", "doc"]),
  billableG: z.number().int().min(1).max(1_000_000),
  total: z.number().min(0).max(100_000_000),
  version: z.number().int().min(0),
  piecesText: z.string().max(600).optional(),
  eta: z.string().max(80).optional(),
  pickup: z.string().max(40).optional(),
  from: z.string().max(80).optional(),
  booked: z.boolean().optional(),
  mode: z.enum(["quick", "detail"]).optional(),
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
  const site = await getLiveSite();
  const dest = site.destinations.find((d) => d.id === parsed.destId && d.active);
  const service = site.services.find((s) => s.id === parsed.serviceId);
  if (!dest || !service) return NextResponse.json({ error: { code: "not_found", message: "Unknown destination or service" } }, { status: 404 });
  const { id, destId, serviceId, type, billableG, total: clientTotal, version, booked, ...rest } = parsed;
  const price = priceService(site.settings, dest, serviceId, billableG, type);
  if (!price) return NextResponse.json({ error: { code: "not_found", message: "Service not offered" } }, { status: 404 });
  const detail: Record<string, unknown> = { ...rest };
  if (Math.round(clientTotal) !== price.total) detail.clientTotal = clientTotal;
  try {
    await db
      .insert(schema.quotes)
      .values({ id, destId, serviceId, type, billableG, total: price.total, version, booked: !!booked, detail })
      .onConflictDoUpdate({ target: schema.quotes.id, set: { booked: !!booked, detail } });
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
        summary: `${service.name} to ${dest.name} · ${gToKg(billableG)} kg · ${fmtMoney(price.total, site.settings.currency)}${rest.piecesText ? ` · ${rest.piecesText}` : ""}`,
        contents: rest.contents,
      });
    } catch (err) {
      console.error("lead upsert failed", err);
    }
  }
  return NextResponse.json({ ok: true });
}
