import Link from "next/link";
import { AdminShell } from "@/components/admin/AdminShell";
import { InboxList, type LeadView } from "@/components/admin/InboxList";
import { requireAdminPage } from "@/lib/auth/session";
import { LEAD_STATUSES, leadCounts, listLeads, type LeadStatus } from "@/lib/leads";
import { notifyConfigured } from "@/lib/notify";
import { getLatestVersion, namesFor } from "@/lib/site/repo";

export const dynamic = "force-dynamic";

export default async function InboxPage(props: PageProps<"/admin/inbox">) {
  const user = await requireAdminPage();
  const sp = await props.searchParams;
  const raw = typeof sp.status === "string" ? sp.status : "new";
  const status: LeadStatus | "all" = raw === "all" || (LEAD_STATUSES as string[]).includes(raw) ? (raw as LeadStatus | "all") : "new";
  const [live, leads, counts] = await Promise.all([getLatestVersion(), listLeads(status), leadCounts()]);
  // A destination removed from the rates since keeps the name the lead asked about.
  const destName = await namesFor("destinations", live, leads.flatMap((l) => (l.destId ? [l.destId] : [])));
  const views: LeadView[] = leads.map((l) => ({
    id: l.id,
    createdAt: l.createdAt.toISOString(),
    updatedAt: l.updatedAt.toISOString(),
    kind: l.kind === "quote" ? "quote" : "message",
    quoteId: l.quoteId,
    name: l.name,
    phone: l.phone,
    email: l.email,
    message: l.message,
    destination: l.destId ? (destName.get(l.destId) ?? l.destId) : "",
    weightKg: l.weightG ? l.weightG / 1000 : null,
    status: l.status,
    notes: l.notes,
    updatedBy: l.updatedBy,
  }));
  const tabs: { key: LeadStatus | "all"; label: string; n: number }[] = [
    { key: "new", label: "New", n: counts.new },
    { key: "contacted", label: "Contacted", n: counts.contacted },
    { key: "booked", label: "Booked", n: counts.booked },
    { key: "lost", label: "Lost", n: counts.lost },
    { key: "all", label: "All", n: counts.new + counts.contacted + counts.booked + counts.lost },
  ];
  return (
    <AdminShell companyName={live?.company.name ?? "Speedat"} user={user} badges={{ inbox: counts.new }}>
      <section className="admin">
        <div className="topbar">
          <div>
            <p className="eyebrow">02 — Inbox</p>
            <h1>Inbox</h1>
          </div>
          <div className="topbar-right">
            {counts.new ? <span className="pill draft">{counts.new} new</span> : <span className="pill live">nothing new</span>}
            <span className="meta">Booked quotes and contact messages. Work them left to right: new → contacted → booked.</span>
          </div>
        </div>
        {!notifyConfigured() ? (
          <div className="notice warn">
            Email alerts for new messages are not configured (POSTMARK_SERVER_TOKEN, NOTIFY_FROM, OFFICE_EMAIL). Messages still land here; check the inbox during the
            day.
          </div>
        ) : null}
        <nav className="subnav" aria-label="Lead status">
          {tabs.map((t) => (
            <Link key={t.key} href={`/admin/inbox?status=${t.key}`} aria-current={t.key === status ? "page" : undefined}>
              {t.label} <span className="n">{t.n}</span>
            </Link>
          ))}
        </nav>
        {/* Keyed by tab: switching tabs is a client-side navigation that keeps component state, so the list
            must remount to show the new tab's rows. A same-tab refresh (after a save) keeps the row in place. */}
        <InboxList key={status} leads={views} />
      </section>
    </AdminShell>
  );
}
