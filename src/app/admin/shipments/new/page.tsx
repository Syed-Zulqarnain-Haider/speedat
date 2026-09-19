import { AdminShell } from "@/components/admin/AdminShell";
import { NewShipmentForm } from "@/components/admin/NewShipmentForm";
import { requireAdminPage } from "@/lib/auth/session";
import { getLead, leadCounts } from "@/lib/leads";
import { getLatestVersion } from "@/lib/site/repo";
import { db, schema } from "@/lib/db";
import { eq } from "drizzle-orm";

export const dynamic = "force-dynamic";

export default async function NewShipmentPage(props: PageProps<"/admin/shipments/new">) {
  const user = await requireAdminPage();
  const sp = await props.searchParams;
  const leadId = typeof sp.lead === "string" && /^\d+$/.test(sp.lead) ? Number(sp.lead) : null;
  const [live, lead, counts] = await Promise.all([getLatestVersion(), leadId ? getLead(leadId) : null, leadCounts()]);
  // A quote lead carries the service the customer chose.
  const quote = lead?.quoteId ? (await db.select().from(schema.quotes).where(eq(schema.quotes.id, lead.quoteId)).limit(1))[0] : null;
  const initial = {
    leadId: lead?.id ?? null,
    quoteId: lead?.quoteId ?? null,
    customerName: lead?.name ?? "",
    customerPhone: lead?.phone ?? "",
    destId: lead?.destId ?? quote?.destId ?? "",
    serviceId: quote?.serviceId ?? live?.services[0]?.id ?? "",
    notes: lead?.message ?? "",
  };
  return (
    <AdminShell companyName={live?.company.name ?? "Speedat"} user={user} badges={{ inbox: counts.new }}>
      <section className="admin" style={{ paddingBottom: 60 }}>
        <div className="admin-head">
          <h1>New shipment</h1>
          {lead ? <span className="meta">From lead #{lead.id}</span> : null}
        </div>
        <NewShipmentForm
          initial={initial}
          destinations={(live?.destinations ?? []).map((d) => ({ id: d.id, name: d.name })).sort((a, b) => a.name.localeCompare(b.name))}
          services={(live?.services ?? []).map((s) => ({ id: s.id, name: s.name }))}
        />
      </section>
    </AdminShell>
  );
}
