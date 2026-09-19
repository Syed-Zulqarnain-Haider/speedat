/**
 * POST /api/inbound/email — Postmark "Inbound" webhook. Every attachment
 * that looks like a rate sheet goes through the intake pipeline: known
 * layout → applied to the draft (and published if within tolerance);
 * unknown layout → waits in the admin for a one-time mapping.
 *
 * Authentication: the webhook URL carries HTTP Basic credentials whose
 * password is INBOUND_EMAIL_SECRET (Postmark supports this), or the same
 * value in an `x-inbound-secret` header for other providers.
 */
import { NextResponse } from "next/server";
import { z } from "zod";
import { audit } from "@/lib/audit";
import { getIntakeSettings, receiveSheet, senderAllowed } from "@/lib/import/intake";
import { fileKind } from "@/lib/import/workbook";

export const maxDuration = 60;

const Payload = z.object({
  From: z.string().max(320).optional(),
  FromFull: z.object({ Email: z.string().max(320).optional() }).optional(),
  Subject: z.string().max(500).optional(),
  MessageID: z.string().max(200).optional(),
  Attachments: z
    .array(z.object({ Name: z.string().max(300), Content: z.string(), ContentType: z.string().max(200).optional(), ContentLength: z.number().optional() }))
    .max(20)
    .default([]),
});

function authorised(req: Request): boolean {
  const secret = process.env.INBOUND_EMAIL_SECRET;
  if (!secret) return false;
  const header = req.headers.get("x-inbound-secret");
  if (header && timingSafeEqual(header, secret)) return true;
  const basic = req.headers.get("authorization");
  if (basic?.startsWith("Basic ")) {
    try {
      const decoded = Buffer.from(basic.slice(6), "base64").toString("utf8");
      const pass = decoded.slice(decoded.indexOf(":") + 1);
      return timingSafeEqual(pass, secret);
    } catch {
      return false;
    }
  }
  return false;
}

function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

export async function POST(req: Request) {
  if (!authorised(req)) return NextResponse.json({ error: { code: "unauthorized", message: "Bad or missing webhook secret" } }, { status: 401 });
  let body: z.infer<typeof Payload>;
  try {
    body = Payload.parse(await req.json());
  } catch {
    return NextResponse.json({ error: { code: "bad_request", message: "Unexpected payload" } }, { status: 400 });
  }
  const from = (body.FromFull?.Email ?? body.From ?? "").toLowerCase().trim() || null;
  const settings = await getIntakeSettings();
  if (!senderAllowed(from, settings.allowedSenders)) {
    await audit(from ?? "unknown", "intake_sender_refused", { subject: body.Subject, messageId: body.MessageID });
    // 200 so the provider does not retry; the refusal is on record.
    return NextResponse.json({ ok: true, accepted: 0, refused: "sender" });
  }
  const sheets = body.Attachments.filter((a) => fileKind(a.Name));
  if (!sheets.length) {
    await audit(from ?? "unknown", "intake_no_sheet", { subject: body.Subject, attachments: body.Attachments.map((a) => a.Name) });
    return NextResponse.json({ ok: true, accepted: 0 });
  }
  const results = [];
  for (const a of sheets) {
    const bytes = new Uint8Array(Buffer.from(a.Content, "base64"));
    const r = await receiveSheet({ source: "email", fileName: a.Name, fromEmail: from, bytes, actor: `email:${from ?? "unknown"}` });
    results.push({ file: a.Name, importId: r.importId, status: r.status, publishedVersion: r.publishedVersion, error: r.error });
  }
  return NextResponse.json({ ok: true, accepted: results.length, results });
}
