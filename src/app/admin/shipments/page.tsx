import Link from "next/link";
import { AdminShell } from "@/components/admin/AdminShell";
import { requireAdminPage } from "@/lib/auth/session";
import { leadCounts } from "@/lib/leads";
import { fmtDateTime } from "@/lib/pricing/format";
import { SHIPMENT_STATUSES, STATUS_LABEL, listShipments, shipmentCounts, type ShipmentStatus } from "@/lib/shipments";
import { getLatestVersion, namesFor } from "@/lib/site/repo";

export const dynamic = "force-dynamic";

export default async function ShipmentsPage(props: PageProps<"/admin/shipments">) {
  const user = await requireAdminPage();
  const sp = await props.searchParams;
  const raw = typeof sp.status === "string" ? sp.status : "open";
  const status: ShipmentStatus | "open" | "all" = raw === "all" || raw === "open" || (SHIPMENT_STATUSES as readonly string[]).includes(raw) ? (raw as ShipmentStatus | "open" | "all") : "open";
  const [live, rows, counts, leads] = await Promise.all([getLatestVersion(), listShipments(status), shipmentCounts(), leadCounts()]);
  // A destination or service removed from the rates since keeps the name it was booked under.
  const [destName, svcName] = await Promise.all([
    namesFor("destinations", live, rows.map((s) => s.destId)),
    namesFor("services", live, rows.map((s) => s.serviceId)),
  ]);
  return (
    <AdminShell companyName={live?.company.name ?? "Speedat"} user={user} badges={{ inbox: leads.new, shipments: counts.exception }}>
      <section className="admin">
        <div className="topbar">
          <div>
            <p className="eyebrow">03 — Shipments</p>
            <h1>Shipments</h1>
          </div>
          <div className="topbar-right">
            {counts.exception ? <span className="pill hold">{counts.exception} need attention</span> : null}
            <span className="meta">{counts.open} open</span>
            <Link className="btn primary small" href="/admin/shipments/new">
              New shipment
            </Link>
          </div>
        </div>
        <nav className="subnav" aria-label="Shipment status">
          <Link href="/admin/shipments?status=open" aria-current={status === "open" ? "page" : undefined}>
            Open <span className="n">{counts.open}</span>
          </Link>
          {SHIPMENT_STATUSES.map((s) => (
            <Link key={s} href={`/admin/shipments?status=${s}`} aria-current={status === s ? "page" : undefined}>
              {STATUS_LABEL[s]}
            </Link>
          ))}
          <Link href="/admin/shipments?status=all" aria-current={status === "all" ? "page" : undefined}>
            All
          </Link>
        </nav>
        {rows.length ? (
          <div className="tablewrap">
            <table className="ships">
              <thead>
                <tr>
                  <th>Shipment</th>
                  <th>Customer</th>
                  <th>To</th>
                  <th>Service</th>
                  <th>Carrier / tracking</th>
                  <th>Status</th>
                  <th>Updated</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((s) => (
                  <tr key={s.id} className={s.status === "exception" ? "changed" : ""}>
                    <td>
                      <Link href={`/admin/shipments/${s.id}`}>
                        <span className="qid">{s.id}</span>
                      </Link>
                      {s.quoteId ? <div className="hint">{s.quoteId}</div> : null}
                    </td>
                    <td>
                      {s.customerName || "—"}
                      {s.customerPhone ? <div className="hint">{s.customerPhone}</div> : null}
                    </td>
                    <td>{destName.get(s.destId) ?? s.destId}</td>
                    <td>{svcName.get(s.serviceId) ?? s.serviceId}</td>
                    <td>
                      {s.carrier || "—"}
                      {s.trackingNo ? <div className="hint">{s.trackingNo}</div> : null}
                    </td>
                    <td>
                      <span className={`pill s-${s.status}`}>{STATUS_LABEL[s.status as ShipmentStatus] ?? s.status}</span>
                    </td>
                    <td className="meta">{fmtDateTime(s.updatedAt)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="hint empty">No shipments here. Create one from a lead in the Inbox, or with “New shipment”.</p>
        )}
      </section>
    </AdminShell>
  );
}
