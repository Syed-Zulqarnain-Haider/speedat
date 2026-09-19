"use client";

import { useState } from "react";
import { estimateDelivery } from "@/lib/pricing/dates";
import { priceAll } from "@/lib/pricing/engine";
import { fmtMoney, fmtNum, fmtRange } from "@/lib/pricing/format";
import { weightSentence } from "@/lib/pricing/quote";
import type { ShipmentType } from "@/lib/pricing/types";
import { useMounted } from "@/lib/client/session";
import type { SiteData } from "@/lib/site/types";

const num = (s: string): number | null => {
  const n = Number(s);
  return s.trim() === "" || !Number.isFinite(n) ? null : n;
};

/** Prices against the draft — including unpublished changes — so a rate can be checked before it goes live. */
export function TestPrice({ draft }: { draft: SiteData }) {
  const mounted = useMounted();
  const [destId, setDestId] = useState("");
  const [type, setType] = useState<ShipmentType>("pkg");
  const [kg, setKg] = useState("");
  const [pcs, setPcs] = useState("1");
  const [L, setL] = useState("");
  const [W, setW] = useState("");
  const [H, setH] = useState("");
  const cur = draft.settings.currency;
  const dests = draft.destinations.filter((x) => x.active).slice().sort((a, b) => a.name.localeCompare(b.name));
  const res = priceAll(draft, { destId, type, rows: [{ kg: num(kg), qty: num(pcs) ?? 1, L: num(L), W: num(W), H: num(H) }] });

  return (
    <section className="block" id="sec-test">
      <h2>Test a price</h2>
      <p className="desc">Uses the rates in the editor, including unpublished changes, so you can check a price before publishing.</p>
      <div className="inline">
        <label className="field">
          <span>Destination</span>
          <select value={destId} onChange={(e) => setDestId(e.target.value)}>
            <option value="" disabled>
              Choose
            </option>
            {dests.map((x) => (
              <option key={x.id} value={x.id}>
                {x.name}
              </option>
            ))}
          </select>
        </label>
        <label className="field">
          <span>Type</span>
          <select value={type} onChange={(e) => setType(e.target.value as ShipmentType)}>
            <option value="pkg">Packages</option>
            <option value="doc">Documents</option>
          </select>
        </label>
        <label className="field">
          <span>Weight per piece (kg)</span>
          <input type="number" step="any" min="0.1" placeholder="2.5" value={kg} onChange={(e) => setKg(e.target.value)} />
        </label>
        <label className="field">
          <span>Pieces</span>
          <input type="number" min="1" step="1" value={pcs} onChange={(e) => setPcs(e.target.value)} />
        </label>
        <label className="field">
          <span>L×W×H (cm)</span>
          <div className="dims">
            <input type="number" step="any" placeholder="L" aria-label="Length" value={L} onChange={(e) => setL(e.target.value)} />
            <span className="x">×</span>
            <input type="number" step="any" placeholder="W" aria-label="Width" value={W} onChange={(e) => setW(e.target.value)} />
            <span className="x">×</span>
            <input type="number" step="any" placeholder="H" aria-label="Height" value={H} onChange={(e) => setH(e.target.value)} />
          </div>
        </label>
      </div>
      <div style={{ marginTop: 10 }}>
        {!res.ok ? (
          <div className="meta">
            {res.reason === "overmax"
              ? `Over the cargo threshold (${draft.settings.maxKg} kg): the site asks the customer to message you. ${weightSentence(res.weights, draft.settings)}`
              : "Enter a destination and weight."}
          </div>
        ) : (
          <>
            <div className="meta">{weightSentence(res.weights, draft.settings)}</div>
            <div className="test-out">
              {draft.services.map((sv) => {
                const p = res.prices[sv.id];
                const est = mounted && p && draft.settings.showEta && p.days ? estimateDelivery(p.days, draft.settings, null, new Date()) : null;
                return (
                  <div key={sv.id}>
                    <div className="meta">
                      {sv.name}
                      {p?.days ? ` · ${p.days} days` : ""}
                    </div>
                    <div className="display">{p ? fmtMoney(p.total, cur) : "—"}</div>
                    {p ? (
                      <div className="hint">
                        {p.docRate ? `document rate ${fmtNum(p.base)}` : `${fmtNum(p.first ?? 0)}${p.steps ? ` + ${p.steps} × ${fmtNum(p.addl ?? 0)}` : ""}`}
                        {p.tax ? " + tax" : ""}
                        {est ? (
                          <>
                            <br />
                            delivered {fmtRange(est)}
                          </>
                        ) : null}
                      </div>
                    ) : (
                      <div className="hint">not offered</div>
                    )}
                  </div>
                );
              })}
            </div>
          </>
        )}
      </div>
    </section>
  );
}
