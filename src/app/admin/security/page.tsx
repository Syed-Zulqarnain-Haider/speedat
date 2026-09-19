import { desc } from "drizzle-orm";
import { AdminShell } from "@/components/admin/AdminShell";
import { TotpSetup } from "@/components/admin/TotpSetup";
import { requireAdminPage } from "@/lib/auth/session";
import { firebaseConfigured } from "@/lib/auth/firebase-admin";
import { totpStatus } from "@/lib/auth/totp";
import { db, schema } from "@/lib/db";
import { leadCounts } from "@/lib/leads";
import { notifyConfigured } from "@/lib/notify";
import { fmtDateTime } from "@/lib/pricing/format";
import { getLatestVersion } from "@/lib/site/repo";

export const dynamic = "force-dynamic";

export default async function SecurityPage() {
  const user = await requireAdminPage();
  const [live, status, counts, admins, recent] = await Promise.all([
    getLatestVersion(),
    totpStatus(user.email),
    leadCounts(),
    db.select({ email: schema.admins.email, name: schema.admins.name, role: schema.admins.role, totp: schema.admins.totpEnabled, addedAt: schema.admins.addedAt }).from(schema.admins),
    db.select().from(schema.auditLog).orderBy(desc(schema.auditLog.id)).limit(40),
  ]);
  const checks = [
    { label: "Admin sign-in (Firebase)", ok: firebaseConfigured(), note: firebaseConfigured() ? "configured" : "FIREBASE_SERVICE_ACCOUNT_B64 missing — only the development bypass works" },
    { label: "Email alerts (Postmark)", ok: notifyConfigured(), note: notifyConfigured() ? "configured" : "not configured — alerts are logged, not sent" },
    { label: "Inbound sheets webhook secret", ok: !!process.env.INBOUND_EMAIL_SECRET, note: process.env.INBOUND_EMAIL_SECRET ? "set" : "INBOUND_EMAIL_SECRET missing — emailed sheets are refused" },
    { label: "Cron secret", ok: !!process.env.CRON_SECRET, note: process.env.CRON_SECRET ? "set" : "CRON_SECRET missing — the daily check cannot run" },
    { label: "Form-token / TOTP key", ok: !!(process.env.APP_SECRET || process.env.TOTP_ENCRYPTION_KEY), note: process.env.APP_SECRET ? "APP_SECRET set" : "APP_SECRET missing — a derived key is in use; set one before enrolling 2FA" },
  ];
  return (
    <AdminShell companyName={live?.company.name ?? "Speedat"} user={user} badges={{ inbox: counts.new }}>
      <section className="admin" style={{ paddingBottom: 60 }}>
        <div className="admin-head">
          <h1>Security</h1>
        </div>
        <div className="grid2" style={{ alignItems: "start" }}>
          <div>
            <TotpSetup enabled={status.enabled} />
            <div className="panel" style={{ paddingBottom: 12 }}>
              <h2 className="step">Admins</h2>
              <ul className="hist">
                {admins.map((a) => (
                  <li key={a.email}>
                    <strong>{a.email}</strong>
                    <span className="meta">{a.role}</span>
                    <span className={a.totp ? "tag" : "meta"}>{a.totp ? "2FA on" : "2FA off"}</span>
                    <span className="hint">added {fmtDateTime(a.addedAt)}</span>
                  </li>
                ))}
              </ul>
              <p className="hint" style={{ marginTop: 8 }}>
                Add or remove admins with <code>ADMIN_EMAIL=… pnpm db:seed</code> or directly in the <code>admins</code> table; every sign-in is checked against it.
              </p>
            </div>
          </div>
          <div>
            <div className="panel" style={{ paddingBottom: 12 }}>
              <h2 className="step">Deployment checks</h2>
              <ul className="hist">
                {checks.map((c) => (
                  <li key={c.label}>
                    <strong>{c.label}</strong>
                    <span className={c.ok ? "tag" : "meta warn"}>{c.ok ? "OK" : "attention"}</span>
                    <span className="hint">{c.note}</span>
                  </li>
                ))}
              </ul>
            </div>
            <div className="panel" style={{ paddingBottom: 12 }}>
              <h2 className="step">Recent activity</h2>
              <ul className="hist" style={{ fontSize: ".9rem" }}>
                {recent.map((r) => (
                  <li key={r.id}>
                    <span className="meta">{fmtDateTime(r.at)}</span>
                    <strong>{r.action}</strong>
                    <span className="meta">{r.actor}</span>
                    {r.ip ? <span className="hint">{r.ip}</span> : null}
                  </li>
                ))}
              </ul>
            </div>
          </div>
        </div>
      </section>
    </AdminShell>
  );
}
