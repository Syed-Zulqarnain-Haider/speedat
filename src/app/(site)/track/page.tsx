import type { Metadata } from "next";
import { CtaBand } from "@/components/site/CtaBand";
import { clientIp, rateLimit } from "@/lib/limits";
import { fmtDateTime } from "@/lib/pricing/format";
import { STATUS_CUSTOMER_TEXT, STATUS_LABEL, findForTracking, type ShipmentStatus } from "@/lib/shipments";
import { getLiveSite } from "@/lib/site/live";

export const metadata: Metadata = { title: "Track a shipment", robots: { index: true } };

// Reads the query and the client address on every request.
export const dynamic = "force-dynamic";

export default async function TrackPage(props: PageProps<"/track">) {
  const sp = await props.searchParams;
  const q = typeof sp.q === "string" ? sp.q.trim().slice(0, 40) : "";
  const site = await getLiveSite();
  const wa = `https://wa.me/${site.company.whatsapp}`;
  let result: Awaited<ReturnType<typeof findForTracking>> = null;
  let throttled = false;
  if (q) {
    const limit = await rateLimit("track", await clientIp(), 30, 10 * 60);
    if (!limit.ok) throttled = true;
    else result = await findForTracking(q);
  }
  const destName = new Map(site.destinations.map((d) => [d.id, d.name]));
  return (
    <section className="page">
      <h1>Track a shipment</h1>
      <p className="lede">Enter the shipment id or quote id from your WhatsApp confirmation, or the airline tracking number.</p>
      <form method="get" className="panel" style={{ paddingBottom: 16, maxWidth: 520 }}>
        <label className="field">
          <span>Shipment id, quote id or tracking number</span>
          <input type="text" name="q" defaultValue={q} maxLength={40} placeholder="SH-XXXXXXXX or SP-260920-XXXX" autoComplete="off" />
        </label>
        <button className="btn primary" type="submit">
          Track
        </button>
      </form>
      {throttled ? <div className="notice warn">Too many lookups from this connection. Please wait a few minutes, or message us on WhatsApp.</div> : null}
      {q && !throttled && !result ? (
        <div className="card" style={{ marginTop: 12 }}>
          <h3>We could not find that shipment</h3>
          <p>Check the id in your confirmation message, or send it to us on WhatsApp and we will look it up for you.</p>
          <a className="btn wa" style={{ marginTop: 12 }} href={`${wa}?text=${encodeURIComponent(`Hi, I am trying to track ${q}`)}`} target="_blank" rel="noopener">
            Ask on WhatsApp
          </a>
        </div>
      ) : null}
      {result ? (
        <div className="quote" style={{ marginTop: 12 }}>
          <h2>
            <span className="qid">{result.shipment.id}</span> · {STATUS_LABEL[result.shipment.status as ShipmentStatus] ?? result.shipment.status}
          </h2>
          <p style={{ marginTop: 8 }}>{STATUS_CUSTOMER_TEXT[result.shipment.status as ShipmentStatus] ?? ""}</p>
          <dl>
            <dt>To</dt>
            <dd>{destName.get(result.shipment.destId) ?? result.shipment.destId}</dd>
            {result.shipment.receiverName ? (
              <>
                <dt>Receiver</dt>
                <dd>{result.shipment.receiverName}</dd>
              </>
            ) : null}
            {result.shipment.trackingNo ? (
              <>
                <dt>Airline tracking</dt>
                <dd>
                  {result.shipment.trackingNo}
                  {result.shipment.carrier ? ` (${result.shipment.carrier})` : ""}
                </dd>
              </>
            ) : null}
          </dl>
          <h3 style={{ margin: "8px 0" }}>Progress</h3>
          <ul className="hist">
            {result.events
              .slice()
              .reverse()
              .map((e) => (
                <li key={e.id}>
                  <strong>{STATUS_LABEL[e.status as ShipmentStatus] ?? e.status}</strong>
                  <span className="meta">{fmtDateTime(e.at)}</span>
                  {e.note ? <span>{e.note}</span> : null}
                </li>
              ))}
          </ul>
          <p className="hint" style={{ marginTop: 10 }}>
            Questions about this shipment? Message us on WhatsApp with the id above.
          </p>
        </div>
      ) : null}
      <CtaBand whatsapp={site.company.whatsapp} />
    </section>
  );
}
