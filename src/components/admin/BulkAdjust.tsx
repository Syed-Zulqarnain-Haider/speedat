"use client";

import { useState } from "react";
import { isGrid, isNum, roundTo } from "@/lib/pricing/engine";
import type { SiteData } from "@/lib/site/types";
import { sectionNo } from "./sections";

interface Props {
  draft: SiteData;
  readOnly: boolean;
  update: (fn: (d: SiteData) => void) => void;
  toast: (m: string) => void;
}

/** A price a bulk change can move: the slab pair, the document price, or every kilogram box of the grid. */
export type PriceField = "first" | "addl" | "doc" | "grid";

export interface Adjustment {
  services: string[];
  fields: PriceField[];
  mode: "pct" | "amt";
  value: number;
  /** Round each result to the nearest multiple of this. */
  round: number;
}

/** The fields the site prices with in this mode; the others are stored but never shown, so a bulk change skips them. */
export function fieldsFor(grid: boolean): PriceField[] {
  return grid ? ["doc", "grid"] : ["doc", "first", "addl"];
}

/**
 * Apply a bulk change to `d` in place; returns how many prices moved. Only the
 * fields the current pricing mode reads are touched: "+10%" used to move the
 * first slab and step only, so under grid pricing it changed nothing a
 * customer sees while saying "120 prices changed".
 */
export function adjustPrices(d: SiteData, a: Adjustment): number {
  const live = fieldsFor(isGrid(d.settings));
  const fs = a.fields.filter((f) => live.includes(f));
  const next = (cur: number) => Math.max(0, roundTo(a.mode === "pct" ? cur * (1 + a.value / 100) : cur + a.value, a.round));
  let n = 0;
  for (const x of d.destinations)
    for (const sid of a.services) {
      const rate = x.rates[sid];
      if (!rate) continue;
      for (const f of fs) {
        if (f === "grid") {
          for (const [kg, cur] of Object.entries(rate.grid ?? {})) {
            if (!isNum(cur)) continue;
            const nv = next(cur);
            if (nv !== cur) {
              rate.grid![kg] = nv;
              n++;
            }
          }
          continue;
        }
        const cur = rate[f];
        if (!isNum(cur)) continue;
        const nv = next(cur);
        if (nv !== cur) {
          rate[f] = nv;
          n++;
        }
      }
    }
  return n;
}

export function BulkAdjust({ draft, readOnly, update, toast }: Props) {
  const [svcs, setSvcs] = useState<Record<string, boolean>>(() => Object.fromEntries(draft.services.map((s) => [s.id, true])));
  const [fields, setFields] = useState<Record<PriceField, boolean>>({ first: true, addl: true, doc: true, grid: true });
  const grid = isGrid(draft.settings);
  const [mode, setMode] = useState<"pct" | "amt">("pct");
  const [val, setVal] = useState("");
  const [rnd, setRnd] = useState("10");
  const [msg, setMsg] = useState<{ kind: "ok" | "warn"; text: string } | null>(null);

  const apply = () => {
    const chosen = draft.services.filter((s) => svcs[s.id]).map((s) => s.id);
    const fs = fieldsFor(grid).filter((f) => fields[f]);
    const v = Number(val);
    const r = Number(rnd) || 1;
    if (!chosen.length || !fs.length) return setMsg({ kind: "warn", text: "Choose at least one service and one price field." });
    if (!Number.isFinite(v) || v === 0) return setMsg({ kind: "warn", text: "Enter the change value, for example 5 for +5% or -200 for 200 less." });
    // Counted on a copy: `update` runs its callback inside a state updater, which may run later (or twice).
    const n = adjustPrices(structuredClone(draft), { services: chosen, fields: fs, mode, value: v, round: r });
    update((d) => {
      adjustPrices(d, { services: chosen, fields: fs, mode, value: v, round: r });
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
        {grid ? (
          <label className="chk">
            <input type="checkbox" checked={fields.grid} onChange={(e) => setFields((f) => ({ ...f, grid: e.target.checked }))} /> Kilogram prices
          </label>
        ) : (
          <>
            <label className="chk">
              <input type="checkbox" checked={fields.first} onChange={(e) => setFields((f) => ({ ...f, first: e.target.checked }))} /> First slab
            </label>
            <label className="chk">
              <input type="checkbox" checked={fields.addl} onChange={(e) => setFields((f) => ({ ...f, addl: e.target.checked }))} /> Per step
            </label>
          </>
        )}
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
