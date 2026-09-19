"use server";

/** The contact form's server action: validate, throttle, store the lead, tell the office. */
import { z } from "zod";
import { audit } from "@/lib/audit";
import { checkFormToken } from "@/lib/form-token";
import { createMessageLead } from "@/lib/leads";
import { clientIp, ipHash, rateLimit } from "@/lib/limits";
import { notifyOffice } from "@/lib/notify";
import { getLiveSite } from "@/lib/site/live";
import type { ContactState } from "./state";

const Fields = z.object({
  name: z.string().trim().min(2, "Please tell us your name").max(120, "That name is too long"),
  phone: z
    .string()
    .trim()
    .regex(/^\+?[0-9 ()-]{7,20}$/, "Enter a phone number we can call or WhatsApp")
    .max(20),
  email: z.string().trim().max(120).refine((v) => v === "" || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v), "That email address does not look right"),
  message: z.string().trim().min(5, "Tell us what you would like to send").max(2000, "Please keep the message under 2000 characters"),
  destId: z.string().max(60).optional().default(""),
  weight: z.string().trim().max(10).optional().default(""),
});

export async function contactAction(_prev: ContactState, form: FormData): Promise<ContactState> {
  const values = Object.fromEntries(["name", "phone", "email", "message", "destId", "weight"].map((k) => [k, String(form.get(k) ?? "")]));
  const fail = (errors: Record<string, string>): ContactState => ({ ok: false, errors, values });

  // Bots fill the hidden field; humans never see it.
  if (String(form.get("website") ?? "").trim()) {
    await audit("anonymous", "contact_honeypot", {});
    return { ok: true, errors: {}, values: {} }; // pretend success; nothing stored
  }
  const tok = checkFormToken(String(form.get("t") ?? ""));
  if (tok === "too_fast") return fail({ _: "That was quick — please check your message and send it again." });
  if (tok !== "ok") return fail({ _: "This page has been open for a while. Reload it and send again." });

  const parsed = Fields.safeParse(values);
  if (!parsed.success) {
    const errors: Record<string, string> = {};
    for (const issue of parsed.error.issues) {
      const k = String(issue.path[0] ?? "_");
      if (!errors[k]) errors[k] = issue.message;
    }
    return fail(errors);
  }
  const ip = await clientIp();
  const limit = await rateLimit("contact", ip, 5, 15 * 60);
  if (!limit.ok) return fail({ _: `Too many messages from this connection. Try again in ${Math.ceil(limit.retryAfter / 60)} minutes or WhatsApp us.` });

  const site = await getLiveSite();
  const dest = site.destinations.find((d) => d.id === parsed.data.destId && d.active) ?? null;
  const weightKg = Number(parsed.data.weight);
  const weightG = parsed.data.weight && Number.isFinite(weightKg) && weightKg > 0 && weightKg < 100_000 ? Math.round(weightKg * 1000) : null;

  const lead = await createMessageLead({
    name: parsed.data.name,
    phone: parsed.data.phone,
    email: parsed.data.email,
    message: parsed.data.message,
    destId: dest?.id ?? null,
    weightG,
    ipHash: ipHash(ip),
  });
  await audit("anonymous", "contact_message", { leadId: lead.id, dest: dest?.name ?? null });
  void notifyOffice({
    subject: `New message from ${parsed.data.name}${dest ? ` — ${dest.name}` : ""}`,
    tag: "lead",
    text: [
      `Name: ${parsed.data.name}`,
      `Phone: ${parsed.data.phone}`,
      parsed.data.email ? `Email: ${parsed.data.email}` : "",
      dest ? `Destination: ${dest.name}` : "",
      weightG ? `Weight: ${weightG / 1000} kg` : "",
      "",
      parsed.data.message,
      "",
      `Open in the admin: ${process.env.NEXT_PUBLIC_SITE_URL ?? ""}/admin/inbox#lead-${lead.id}`,
    ]
      .filter((l) => l !== "")
      .join("\n"),
  });
  return { ok: true, errors: {}, values: {}, leadId: lead.id };
}
