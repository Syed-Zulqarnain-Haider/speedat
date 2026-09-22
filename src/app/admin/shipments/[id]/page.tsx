import Link from "next/link";
import { notFound } from "next/navigation";
import { AdminShell } from "@/components/admin/AdminShell";
import { ShipmentDesk } from "@/components/admin/ShipmentDesk";
import { requireAdminPage } from "@/lib/auth/session";
import { leadCounts } from "@/lib/leads";
import { getShipment } from "@/lib/shipments";
import { getLatestVersion, namesFor } from "@/lib/site/repo";

export const dynamic = "force-dynamic";

export default async function ShipmentPage(props: PageProps<"/admin/shipments/[id]">) {
  const user = await requireAdminPage();
  const { id } = await props.params;
  if (!/^SH-[A-Z0-9]{8}$/.test(id)) notFound();
  const [live, found, counts] = await Promise.all([getLatestVersion(), getShipment(id), leadCounts()]);
  if (!found || !live) notFound();
  // A destination or service removed from the rates since keeps the name it was booked under;
  // the customer's WhatsApp update reads it too.
  const [destNames, svcNames] = await Promise.all([namesFor("destinations", live, [found.shipment.destId]), namesFor("services", live, [found.shipment.serviceId])]);
  const destination = destNames.get(found.shipment.destId) ?? found.shipment.destId;
  const service = svcNames.get(found.shipment.serviceId) ?? found.shipment.serviceId;
  return (
    <AdminShell companyName={live.company.name} user={user} badges={{ inbox: counts.new }}>
      <section className="admin">
        <div className="topbar">
          <div>
            <p className="eyebrow">03 — Shipment</p>
            <h1 className="mono-h1">{found.shipment.id}</h1>
          </div>
          <div className="topbar-right">
            <span className="meta">
              {service} to {destination}
            </span>
            <Link className="btn outline small" href="/admin/shipments">
              ← All shipments
            </Link>
          </div>
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
            destination,
            service,
          }}
          events={found.events.map((e) => ({ id: e.id, status: e.status, note: e.note, at: e.at.toISOString(), by: e.by }))}
          companyName={live.company.name}
        />
      </section>
    </AdminShell>
  );
}
