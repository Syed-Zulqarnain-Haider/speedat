"use client";

import { useState } from "react";
import { isNum, roundTo } from "@/lib/pricing/engine";
import type { SiteData } from "@/lib/site/types";
import { sectionNo } from "./sections";

interface Props {
  draft: SiteData;
  readOnly: boolean;
  update: (fn: (d: SiteData) => void) => void;
  toast: (m: string) => void;
}

export function BulkAdjust({ draft, readOnly, update, toast }: Props) {
  const [svcs, setSvcs] = useState<Record<string, boolean>>(() => Object.fromEntries(draft.services.map((s) => [s.id, true])));
  const [fields, setFields] = useState({ first: true, addl: true, doc: true });
  const [mode, setMode] = useState<"pct" | "amt">("pct");
  const [val, setVal] = useState("");
  const [rnd, setRnd] = useState("10");
  const [msg, setMsg] = useState<{ kind: "ok" | "warn"; text: string } | null>(null);

  const apply = () => {
    const chosen = draft.services.filter((s) => svcs[s.id]).map((s) => s.id);
    const fs = (["first", "addl", "doc"] as const).filter((f) => fields[f]);
    const v = Number(val);
    const r = Number(rnd) || 1;
    if (!chosen.length || !fs.length) return setMsg({ kind: "warn", text: "Choose at least one service and one price field." });
    if (!Number.isFinite(v) || v === 0) return setMsg({ kind: "warn", text: "Enter the change value, for example 5 for +5% or -200 for 200 less." });
    let n = 0;
    update((d) => {
      for (const x of d.destinations)
        for (const sid of chosen) {
          const rate = x.rates[sid];
          if (!rate) continue;
          for (const f of fs) {
            const cur = rate[f];
            if (!isNum(cur)) continue;
            const nv = Math.max(0, roundTo(mode === "pct" ? cur * (1 + v / 100) : cur + v, r));
            if (nv !== cur) {
              rate[f] = nv;
              n++;
            }
          }
        }
    });
    setMsg({ kind: "ok", text: `${n} price${n === 1 ? "" : "s"} changed in the editor. Review the list before publishing; Discard undoes everything.` });
    toast(`${n} prices adjusted`);
  };

  return (
    <section className="block" id="sec-bulk">
      <p className="eyebrow">{sectionNo("bulk")} — Bulk adjust</p>
      <h2>Bulk adjust</h2>
      <p className="desc">Change many prices at once, for example when a carrier raises everything by 5%. Applies to the editor; nothing is published until you review.</p>
      <div className="inline">
        {draft.services.map((s) => (
          <label className="chk" key={s.id}>
            <input type="checkbox" checked={!!svcs[s.id]} onChange={(e) => setSvcs((m) => ({ ...m, [s.id]: e.target.checked }))} /> {s.name}
          </label>
        ))}
        <label className="chk">
          <input type="checkbox" checked={fields.first} onChange={(e) => setFields((f) => ({ ...f, first: e.target.checked }))} /> First slab
        </label>
        <label className="chk">
          <input type="checkbox" checked={fields.addl} onChange={(e) => setFields((f) => ({ ...f, addl: e.target.checked }))} /> Per step
        </label>
        <label className="chk">
          <input type="checkbox" checked={fields.doc} onChange={(e) => setFields((f) => ({ ...f, doc: e.target.checked }))} /> Documents
        </label>
      </div>
      <div className="inline" style={{ marginTop: 10 }}>
        <label className="field">
          <span>Change</span>
          <select value={mode} onChange={(e) => setMode(e.target.value as "pct" | "amt")}>
            <option value="pct">by percent</option>
            <option value="amt">by amount</option>
          </select>
        </label>
        <label className="field">
          <span>Value (negative to reduce)</span>
          <input type="number" step="any" placeholder="5" value={val} onChange={(e) => setVal(e.target.value)} />
        </label>
        <label className="field">
          <span>Round result to nearest</span>
          <input type="number" min="1" step="1" value={rnd} onChange={(e) => setRnd(e.target.value)} />
        </label>
        <button className="btn primary" type="button" disabled={readOnly} onClick={apply}>
          Apply to editor
        </button>
      </div>
      {msg ? <div className={`notice ${msg.kind}`}>{msg.text}</div> : null}
    </section>
  );
}
