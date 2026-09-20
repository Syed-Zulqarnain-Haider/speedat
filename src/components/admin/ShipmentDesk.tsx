"use client";

import { useState } from "react";
import { addEventAction, updateShipmentAction } from "@/app/admin/shipments/actions";
import { Toast, useToast } from "@/components/calculator/Toast";
import { fmtDateTime } from "@/lib/pricing/format";

// Mirrors src/lib/shipments.ts (server-only module) for the client.
const STATUSES = ["booked", "picked_up", "in_transit", "customs", "out_for_delivery", "delivered", "exception"] as const;
type Status = (typeof STATUSES)[number];
const LABEL: Record<Status, string> = {
  booked: "Booked",
  picked_up: "Picked up",
  in_transit: "In transit",
  customs: "At customs",
  out_for_delivery: "Out for delivery",
  delivered: "Delivered",
  exception: "Needs attention",
};
const CUSTOMER_TEXT: Record<Status, string> = {
  booked: "Your shipment is booked. We will collect it and hand it to the airline.",
  picked_up: "We have collected your shipment and it is being prepared for the flight.",
  in_transit: "Your shipment is on its way to the destination country.",
  customs: "Your shipment is with customs at the destination. This can take a day or two.",
  out_for_delivery: "Your shipment is out for delivery today.",
  delivered: "Your shipment has been delivered.",
  exception: "There is a hold-up with your shipment. We are on it and will update you shortly.",
};

export interface ShipmentView {
  id: string;
  quoteId: string | null;
  customerName: string;
  customerPhone: string;
  receiverName: string;
  carrier: string;
  trackingNo: string;
  notes: string;
  status: string;
  createdAt: string;
  destination: string;
  service: string;
}

export interface EventView {
  id: number;
  status: string;
  note: string;
  at: string;
  by: string;
}

function waDigits(phone: string): string | null {
  const digits = phone.replace(/[^0-9]/g, "");
  if (digits.length < 8) return null;
  return digits.startsWith("0") ? `92${digits.slice(1)}` : digits;
}

export function ShipmentDesk({ shipment, events: initialEvents, companyName }: { shipment: ShipmentView; events: EventView[]; companyName: string }) {
  const [s, setS] = useState(shipment);
  const [events, setEvents] = useState(initialEvents);
  const [status, setStatus] = useState<Status>((STATUSES as readonly string[]).includes(s.status) ? (s.status as Status) : "booked");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [toast, showToast] = useToast();

  const saveField = async (patch: Partial<Pick<ShipmentView, "customerName" | "customerPhone" | "receiverName" | "carrier" | "trackingNo" | "notes">>) => {
    const res = await updateShipmentAction({ id: s.id, ...patch });
    if (!res.ok) return showToast(res.message);
    setS((x) => ({ ...x, ...patch }));
    showToast("Saved");
  };

  const addEvent = async () => {
    setBusy(true);
    const res = await addEventAction({ id: s.id, status, note });
    setBusy(false);
    if (!res.ok) return showToast(res.message);
    setEvents((ev) => [...ev, { id: Date.now(), status, note, at: res.at, by: "you" }]);
    setS((x) => ({ ...x, status }));
    setNote("");
    showToast(`Status set to ${LABEL[status]}`);
  };

  const customerMsg = [
    `Hi${s.customerName ? ` ${s.customerName}` : ""}, an update from ${companyName} on your shipment ${s.id} to ${s.destination}:`,
    CUSTOMER_TEXT[(s.status as Status) in LABEL ? (s.status as Status) : "booked"],
    s.trackingNo ? `Tracking number: ${s.trackingNo}${s.carrier ? ` (${s.carrier})` : ""}` : "",
  ]
    .filter(Boolean)
    .join("\n");
  const wa = waDigits(s.customerPhone);

  const field = (label: string, key: keyof typeof s, type = "text") => (
    <label className="field">
      <span>{label}</span>
      <input
        type={type}
        defaultValue={String(s[key] ?? "")}
        onBlur={(e) => {
          if (e.target.value !== s[key]) saveField({ [key]: e.target.value } as Partial<ShipmentView>);
        }}
      />
    </label>
  );

  return (
    <div className="grid2" style={{ alignItems: "start" }}>
      <div className="panel" style={{ paddingBottom: 16 }}>
        <h2 className="step">Details</h2>
        <p className="meta" style={{ marginBottom: 12 }}>
          {s.service} to {s.destination} · created {fmtDateTime(s.createdAt)}
          {s.quoteId ? ` · quote ${s.quoteId}` : ""}
        </p>
        {field("Customer name", "customerName")}
        {field("Customer phone / WhatsApp", "customerPhone", "tel")}
        {field("Receiver name", "receiverName")}
        {field("Carrier / airline", "carrier")}
        {field("Tracking number", "trackingNo")}
        <label className="field">
          <span>Internal notes</span>
          <textarea
            className="long"
            defaultValue={s.notes}
            onBlur={(e) => {
              if (e.target.value !== s.notes) saveField({ notes: e.target.value });
            }}
          />
        </label>
        <p className="hint">Fields save when you leave them.</p>
      </div>
      <div>
        <div className="panel" style={{ paddingBottom: 16 }}>
          <h2 className="step">Update status</h2>
          <div className="inline">
            <label className="field">
              <span>Status</span>
              <select value={status} onChange={(e) => setStatus(e.target.value as Status)}>
                {STATUSES.map((st) => (
                  <option key={st} value={st}>
                    {LABEL[st]}
                  </option>
                ))}
              </select>
            </label>
            <label className="field" style={{ flex: 2 }}>
              <span>Note (included in the WhatsApp update)</span>
              <input type="text" value={note} maxLength={1000} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Handed to Emirates flight EK623" />
            </label>
            <button className="btn primary" type="button" disabled={busy} onClick={addEvent}>
              Add update
            </button>
          </div>
          <div style={{ display: "flex", gap: 10, flexWrap: "wrap", alignItems: "center", marginTop: 8 }}>
            {wa ? (
              <a className="btn wa small" href={`https://wa.me/${wa}?text=${encodeURIComponent(customerMsg)}`} target="_blank" rel="noopener">
                Send WhatsApp update
              </a>
            ) : (
              <span className="hint">Add the customer&apos;s phone to send WhatsApp updates.</span>
            )}
            <button
              className="btn small"
              type="button"
              onClick={() => navigator.clipboard?.writeText(customerMsg).then(() => showToast("Message copied"), () => showToast("Copy failed"))}
            >
              Copy message
            </button>
          </div>
        </div>
        <div className="panel" style={{ paddingBottom: 12 }}>
          <h2 className="step">Timeline</h2>
          <ul className="hist">
            {events
              .slice()
              .reverse()
              .map((e) => (
                <li key={e.id}>
                  <strong>{LABEL[e.status as Status] ?? e.status}</strong>
                  <span className="meta">{fmtDateTime(e.at)}</span>
                  {e.note ? <span>{e.note}</span> : null}
                  <span className="hint">{e.by}</span>
                </li>
              ))}
          </ul>
        </div>
      </div>
      <Toast message={toast} />
    </div>
  );
}
