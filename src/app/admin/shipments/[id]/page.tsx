import Link from "next/link";
import { notFound } from "next/navigation";
import { AdminShell } from "@/components/admin/AdminShell";
import { ShipmentDesk } from "@/components/admin/ShipmentDesk";
import { requireAdminPage } from "@/lib/auth/session";
import { leadCounts } from "@/lib/leads";
import { getShipment } from "@/lib/shipments";
import { getLatestVersion } from "@/lib/site/repo";

export const dynamic = "force-dynamic";

export default async function ShipmentPage(props: PageProps<"/admin/shipments/[id]">) {
  const user = await requireAdminPage();
  const { id } = await props.params;
  if (!/^SH-[A-Z0-9]{8}$/.test(id)) notFound();
  const [live, found, counts] = await Promise.all([getLatestVersion(), getShipment(id), leadCounts()]);
  if (!found || !live) notFound();
  const dest = live.destinations.find((d) => d.id === found.shipment.destId);
  const svc = live.services.find((s) => s.id === found.shipment.serviceId);
  return (
    <AdminShell companyName={live.company.name} user={user} badges={{ inbox: counts.new }}>
      <section className="admin" style={{ paddingBottom: 60 }}>
        <div className="admin-head">
          <h1>
            <span className="qid">{found.shipment.id}</span>
          </h1>
          <span className="meta">
            <Link href="/admin/shipments">← All shipments</Link>
          </span>
        </div>
        <ShipmentDesk
          shipment={{
            id: found.shipment.id,
            quoteId: found.shipment.quoteId,
            customerName: found.shipment.customerName,
            customerPhone: found.shipment.customerPhone,
            receiverName: found.shipment.receiverName,
            carrier: found.shipment.carrier,
            trackingNo: found.shipment.trackingNo ?? "",
            notes: found.shipment.notes,
            status: found.shipment.status,
            createdAt: found.shipment.createdAt.toISOString(),
            destination: dest?.name ?? found.shipment.destId,
            service: svc?.name ?? found.shipment.serviceId,
          }}
          events={found.events.map((e) => ({ id: e.id, status: e.status, note: e.note, at: e.at.toISOString(), by: e.by }))}
          companyName={live.company.name}
          siteUrl={process.env.NEXT_PUBLIC_SITE_URL ?? ""}
        />
      </section>
    </AdminShell>
  );
}
