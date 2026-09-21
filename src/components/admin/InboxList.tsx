"use client";

import Link from "next/link";
import { useState } from "react";
import { updateLeadAction } from "@/app/admin/inbox/actions";
import { Toast, useToast } from "@/components/calculator/Toast";
import { fmtDateTime } from "@/lib/pricing/format";

export interface LeadView {
  id: number;
  createdAt: string;
  updatedAt: string;
  kind: "quote" | "message";
  quoteId: string | null;
  name: string;
  phone: string;
  email: string;
  message: string;
  destination: string;
  weightKg: number | null;
  status: string;
  notes: string;
  updatedBy: string;
}

const STATUSES = ["new", "contacted", "booked", "lost"] as const;

function waHref(phone: string): string | null {
  const digits = phone.replace(/[^0-9]/g, "");
  if (digits.length < 8) return null;
  // Local Pakistani numbers typed as 03xx… become 923xx…
  const intl = digits.startsWith("0") ? `92${digits.slice(1)}` : digits;
  return `https://wa.me/${intl}`;
}

export function InboxList({ leads }: { leads: LeadView[] }) {
  const [rows, setRows] = useState(leads);
  const [toast, showToast] = useToast();
  const [busy, setBusy] = useState<number | null>(null);

  const save = async (id: number, patch: { status?: (typeof STATUSES)[number]; notes?: string }) => {
    setBusy(id);
    const res = await updateLeadAction({ id, ...patch });
    setBusy(null);
    if (!res.ok) return showToast(res.message);
    setRows((rs) => rs.map((r) => (r.id === id ? { ...r, status: res.status, notes: res.notes, updatedAt: res.updatedAt } : r)));
    showToast("Saved");
  };

  if (!rows.length) return <p className="hint empty">Nothing here.</p>;
  return (
    <>
      <ul className="hist leads">
        {rows.map((l) => {
          const wa = waHref(l.phone);
          return (
            <li key={l.id} id={`lead-${l.id}`} className={`lead${l.status === "new" ? " new" : ""}`}>
              <div className="lead-body">
                <div className="lead-head">
                  <span className={`tag kind-${l.kind}`}>{l.kind === "quote" ? "Quote" : "Message"}</span>
                  {l.kind === "quote" ? (
                    <>
                      <span className="qid">{l.quoteId}</span>
                      {l.name ? <span className="lead-name">{l.name}</span> : null}
                    </>
                  ) : (
                    <span className="lead-name">{l.name || "Message"}</span>
                  )}
                  {l.destination ? <span className="meta">→ {l.destination}</span> : null}
                  {l.weightKg ? <span className="meta">{l.weightKg} kg</span> : null}
                  <span className="meta">{fmtDateTime(l.createdAt)}</span>
                </div>
                {l.phone || l.email ? (
                  <div className="lead-contact">
                    {l.phone ? <span className="meta">{l.phone}</span> : null}
                    {l.email ? <span className="meta">{l.email}</span> : null}
                  </div>
                ) : null}
                {l.message ? <p className="lead-msg">{l.message}</p> : null}
              </div>
              <div className="lead-actions">
                <label className="field">
                  <span>Status</span>
                  <select value={l.status} disabled={busy === l.id} onChange={(e) => save(l.id, { status: e.target.value as (typeof STATUSES)[number] })}>
                    {STATUSES.map((s) => (
                      <option key={s} value={s}>
                        {s}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="field notes">
                  <span>Notes</span>
                  <input
                    type="text"
                    defaultValue={l.notes}
                    placeholder="What was agreed, pickup time, receiver…"
                    onBlur={(e) => {
                      if (e.target.value !== l.notes) save(l.id, { notes: e.target.value });
                    }}
                  />
                </label>
                <div className="lead-btns">
                  {wa ? (
                    <a className="btn wa small" href={wa} target="_blank" rel="noopener">
                      Open in WhatsApp
                    </a>
                  ) : null}
                  <Link className="btn outline small" href={`/admin/shipments/new?lead=${l.id}`}>
                    Create shipment
                  </Link>
                </div>
              </div>
              {l.updatedBy ? (
                <span className="lead-foot">
                  Last touched by {l.updatedBy}, {fmtDateTime(l.updatedAt)}
                </span>
              ) : null}
            </li>
          );
        })}
      </ul>
      <Toast message={toast} />
    </>
  );
}
