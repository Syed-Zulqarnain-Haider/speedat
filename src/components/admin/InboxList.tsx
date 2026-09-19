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

  if (!rows.length) return <p className="hint" style={{ marginTop: 16 }}>Nothing here.</p>;
  return (
    <>
      <ul className="hist" style={{ marginTop: 10 }}>
        {rows.map((l) => {
          const wa = waHref(l.phone);
          return (
            <li key={l.id} id={`lead-${l.id}`} style={{ display: "block" }}>
              <div style={{ display: "flex", flexWrap: "wrap", gap: "6px 14px", alignItems: "baseline" }}>
                <strong>{l.kind === "quote" ? `Quote ${l.quoteId}` : l.name || "Message"}</strong>
                <span className="meta">{fmtDateTime(l.createdAt)}</span>
                {l.destination ? <span className="meta">→ {l.destination}</span> : null}
                {l.weightKg ? <span className="meta">{l.weightKg} kg</span> : null}
                {l.phone ? <span className="meta">{l.phone}</span> : null}
                {l.email ? <span className="meta">{l.email}</span> : null}
              </div>
              {l.message ? <p style={{ margin: "6px 0 8px", whiteSpace: "pre-wrap", maxWidth: "80ch" }}>{l.message}</p> : null}
              <div className="inline" style={{ alignItems: "center" }}>
                <label className="field" style={{ maxWidth: 180 }}>
                  <span>Status</span>
                  <select value={l.status} disabled={busy === l.id} onChange={(e) => save(l.id, { status: e.target.value as (typeof STATUSES)[number] })}>
                    {STATUSES.map((s) => (
                      <option key={s} value={s}>
                        {s}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="field" style={{ flex: 3, minWidth: 220 }}>
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
                {wa ? (
                  <a className="btn wa small" href={wa} target="_blank" rel="noopener">
                    Open in WhatsApp
                  </a>
                ) : null}
                <Link className="btn small" href={`/admin/shipments/new?lead=${l.id}`}>
                  Create shipment
                </Link>
              </div>
              {l.updatedBy ? (
                <span className="hint">
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
