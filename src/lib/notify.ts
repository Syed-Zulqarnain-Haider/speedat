/**
 * Outbound notifications to the office: new lead, held or missing rate
 * sheet, stale rates. Email via Postmark's API when POSTMARK_SERVER_TOKEN
 * and OFFICE_EMAIL are set; otherwise the message is logged and an audit
 * row records that nothing was sent, so the gap is visible, never silent.
 */
import "server-only";
import { audit } from "@/lib/audit";

export interface Mail {
  subject: string;
  text: string;
  /** Optional HTML alternative; a <pre> of the text is used when absent. */
  html?: string;
  /** Tag for Postmark analytics, e.g. "lead", "intake". */
  tag?: string;
}

export function notifyConfigured(): boolean {
  return !!(process.env.POSTMARK_SERVER_TOKEN && process.env.OFFICE_EMAIL && process.env.NOTIFY_FROM);
}

const escapeHtml = (s: string) => s.replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" })[c]!);

/** Send to the office. Returns true when the provider accepted it. Never throws. */
export async function notifyOffice(mail: Mail): Promise<boolean> {
  const to = process.env.OFFICE_EMAIL;
  const from = process.env.NOTIFY_FROM;
  const token = process.env.POSTMARK_SERVER_TOKEN;
  if (!to || !from || !token) {
    console.warn(`[notify] not configured; would have sent "${mail.subject}"`);
    await audit("system", "notify_skipped", { subject: mail.subject, reason: "not configured" });
    return false;
  }
  try {
    const res = await fetch("https://api.postmarkapp.com/email", {
      method: "POST",
      headers: { "content-type": "application/json", accept: "application/json", "X-Postmark-Server-Token": token },
      body: JSON.stringify({
        From: from,
        To: to,
        Subject: mail.subject,
        TextBody: mail.text,
        HtmlBody: mail.html ?? `<pre style="font-family:sans-serif;white-space:pre-wrap">${escapeHtml(mail.text)}</pre>`,
        Tag: mail.tag,
        MessageStream: "outbound",
      }),
      signal: AbortSignal.timeout(10_000),
    });
    if (!res.ok) {
      const body = await res.text().catch(() => "");
      console.error("[notify] provider refused", res.status, body.slice(0, 300));
      await audit("system", "notify_failed", { subject: mail.subject, status: res.status });
      return false;
    }
    return true;
  } catch (err) {
    console.error("[notify] send failed", err);
    await audit("system", "notify_failed", { subject: mail.subject, error: String(err).slice(0, 200) });
    return false;
  }
}
