"use client";

import { useActionState } from "react";
import { createShipmentAction, type CreateState } from "@/app/admin/shipments/actions";

interface Props {
  initial: { leadId: number | null; quoteId: string | null; customerName: string; customerPhone: string; destId: string; serviceId: string; notes: string };
  destinations: { id: string; name: string }[];
  services: { id: string; name: string }[];
}

export function NewShipmentForm({ initial, destinations, services }: Props) {
  const [state, action, pending] = useActionState<CreateState, FormData>(createShipmentAction, { error: null });
  return (
    <form action={action} className="panel" style={{ paddingBottom: 16, maxWidth: 760 }}>
      {state.error ? (
        <div className="notice err" role="alert">
          {state.error}
        </div>
      ) : null}
      <input type="hidden" name="leadId" value={initial.leadId ?? ""} />
      <input type="hidden" name="quoteId" value={initial.quoteId ?? ""} />
      {initial.quoteId ? <p className="meta" style={{ marginBottom: 10 }}>Quote {initial.quoteId}</p> : null}
      <div className="row">
        <label className="field">
          <span>Customer name</span>
          <input type="text" name="customerName" defaultValue={initial.customerName} maxLength={120} />
        </label>
        <label className="field">
          <span>Customer phone / WhatsApp</span>
          <input type="tel" name="customerPhone" defaultValue={initial.customerPhone} maxLength={30} />
        </label>
      </div>
      <div className="row">
        <label className="field">
          <span>Destination</span>
          <select name="destId" defaultValue={initial.destId} required>
            <option value="" disabled>
              Choose
            </option>
            {destinations.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </select>
        </label>
        <label className="field">
          <span>Service</span>
          <select name="serviceId" defaultValue={initial.serviceId} required>
            {services.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </select>
        </label>
      </div>
      <label className="field">
        <span>Receiver name</span>
        <input type="text" name="receiverName" maxLength={120} />
      </label>
      <div className="row">
        <label className="field">
          <span>Carrier / airline</span>
          <input type="text" name="carrier" maxLength={80} placeholder="e.g. DHL, Emirates SkyCargo" />
        </label>
        <label className="field">
          <span>Tracking number (if known)</span>
          <input type="text" name="trackingNo" maxLength={80} />
        </label>
      </div>
      <label className="field">
        <span>Notes (internal)</span>
        <textarea className="long" name="notes" defaultValue={initial.notes} maxLength={4000} />
      </label>
      <button className="btn primary" type="submit" disabled={pending}>
        {pending ? "Creating…" : "Create shipment"}
      </button>
    </form>
  );
}
