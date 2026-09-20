"use client";

import { Fragment, useState } from "react";
import { gridWeights, isGrid, isNum } from "@/lib/pricing/engine";
import { fmtNum, nameKey, slug } from "@/lib/pricing/format";
import type { Destination } from "@/lib/pricing/types";
import type { SiteData } from "@/lib/site/types";
import { numOrNull } from "./fields";

interface Props {
  draft: SiteData;
  live: SiteData;
  changed: Record<string, true>;
  readOnly: boolean;
  update: (fn: (d: SiteData) => void) => void;
  /** Bumps whenever the whole draft is replaced, so uncontrolled inputs remount. */
  epoch: number;
  toast: (m: string) => void;
}

function uniqueId(base: string, taken: Destination[]): string {
  let id = base;
  let n = 2;
  while (taken.some((x) => x.id === id)) id = `${base}-${n++}`;
  return id;
}

function csvCell(v: unknown): string {
  const s = String(v ?? "");
  // A leading =, +, -, @ would be executed as a formula by Excel; neutralise it.
  const safe = /^[=+\-@\t\r]/.test(s) ? `'${s}` : s;
  return /[",\n]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe;
}

export function toCsv(s: SiteData): string {
  const grid = isGrid(s.settings);
  const kgs = gridWeights(s.settings);
  const head = ["Destination", "On site"];
  for (const sv of s.services) {
    head.push(`${sv.name} days`, `${sv.name} documents`);
    if (grid) for (const kg of kgs) head.push(`${sv.name} ${kg} kg`);
    else head.push(`${sv.name} first ${s.settings.firstKg} kg`, `${sv.name} each +${s.settings.stepKg} kg`);
  }
  const out = [head.map(csvCell).join(",")];
  for (const x of s.destinations) {
    const row: unknown[] = [x.name, x.active ? "yes" : "no"];
    for (const sv of s.services) {
      const r = x.rates[sv.id] ?? {};
      row.push(r.days ?? "", r.doc ?? "");
      if (grid) for (const kg of kgs) row.push(r.grid?.[String(kg)] ?? "");
      else row.push(r.first ?? "", r.addl ?? "");
    }
    out.push(row.map(csvCell).join(","));
  }
  return `${out.join("\r\n")}\r\n`;
}

/** Drop empty rate objects so a destination with nothing entered shows as "not offered". */
function tidy(x: Destination, svc: string): void {
  const r = x.rates[svc];
  if (!r) return;
  if (r.grid && !Object.keys(r.grid).length) delete r.grid;
  if (r.first == null && r.addl == null && r.doc == null && !r.days && !r.grid) delete x.rates[svc];
}

export function RatesTable({ draft, live, changed, readOnly, update, epoch, toast }: Props) {
  const [filter, setFilter] = useState("");
  const [confirmDel, setConfirmDel] = useState<string | null>(null);
  const [open, setOpen] = useState<string | null>(null);
  const s = draft;
  const grid = isGrid(s.settings);
  const kgs = gridWeights(s.settings);
  const k = nameKey(filter);

  const setRate = (id: string, svc: string, field: "first" | "addl" | "doc", raw: string) => {
    const v = numOrNull(raw);
    if (v === undefined) return;
    update((d) => {
      const x = d.destinations.find((y) => y.id === id);
      if (!x) return;
      const r = { ...(x.rates[svc] ?? {}) };
      if (v === null) delete r[field];
      else r[field] = v;
      x.rates[svc] = r;
      tidy(x, svc);
    });
  };
  const setDays = (id: string, svc: string, raw: string) =>
    update((d) => {
      const x = d.destinations.find((y) => y.id === id);
      if (!x) return;
      const r = { ...(x.rates[svc] ?? {}) };
      if (raw.trim()) r.days = raw.trim();
      else delete r.days;
      x.rates[svc] = r;
      tidy(x, svc);
    });
  const setGridPrice = (id: string, svc: string, kg: number, raw: string) => {
    const v = numOrNull(raw);
    if (v === undefined) return;
    update((d) => {
      const x = d.destinations.find((y) => y.id === id);
      if (!x) return;
      const r = { ...(x.rates[svc] ?? {}) };
      const g = { ...(r.grid ?? {}) };
      if (v === null) delete g[String(kg)];
      else g[String(kg)] = v;
      r.grid = g;
      x.rates[svc] = r;
      tidy(x, svc);
    });
  };

  const download = () => {
    const blob = new Blob([toCsv(s)], { type: "text/csv;charset=utf-8" });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = `${slug(s.company.name)}-rates.csv`;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  };
  const copy = () => {
    if (navigator.clipboard?.writeText) navigator.clipboard.writeText(toCsv(s)).then(() => toast("CSV copied — paste it into Excel"), () => toast("Copy failed"));
    else toast("Copy is not supported here");
  };

  const gridSummary = (x: Destination, svc: string): string => {
    const g = x.rates[svc]?.grid ?? {};
    const filled = kgs.filter((kg) => isNum(g[String(kg)]));
    if (!filled.length) return "—";
    const first = g[String(filled[0])]!;
    const last = g[String(filled[filled.length - 1])]!;
    return `${fmtNum(first)} – ${fmtNum(last)}${filled.length < kgs.length ? ` (${filled.length}/${kgs.length})` : ""}`;
  };

  return (
    <section className="block" id="sec-rates" style={{ borderTop: 0, marginTop: 10, paddingTop: 0 }}>
      <div className="toolbar">
        <input type="text" placeholder="Find a destination" aria-label="Find a destination" value={filter} onChange={(e) => setFilter(e.target.value)} />
        <button className="btn small" type="button" disabled={readOnly} onClick={() => update((d) => d.destinations.sort((a, b) => a.name.localeCompare(b.name)))}>
          Sort A–Z
        </button>
        <button
          className="btn small primary"
          type="button"
          disabled={readOnly}
          onClick={() =>
            update((d) => {
              const id = uniqueId(slug("New destination"), d.destinations);
              d.destinations.push({ id, name: "New destination", active: false, rates: {} });
              setOpen(id);
            })
          }
        >
          Add country
        </button>
        <button className="btn small" type="button" onClick={download}>
          Download as CSV
        </button>
        <button className="btn small" type="button" onClick={copy}>
          Copy as CSV
        </button>
      </div>
      <div className="tablewrap">
        <table className={grid ? "rates rates-grid" : "rates"} key={epoch}>
          <thead>
            <tr>
              <th />
              <th />
              {s.services.map((sv) => (
                <th key={sv.id} className={`grp ${sv.id}`} colSpan={grid ? 2 : 4}>
                  {sv.name}
                </th>
              ))}
              {grid ? <th /> : null}
              <th />
            </tr>
            <tr>
              <th>Destination</th>
              <th>On site</th>
              {s.services.map((sv) =>
                grid ? (
                  <Fragment key={sv.id}>
                    <th>Docs ≤{s.settings.docMaxKg} kg</th>
                    <th>Days</th>
                  </Fragment>
                ) : (
                  <Fragment key={sv.id}>
                    <th>Docs ≤{s.settings.docMaxKg} kg</th>
                    <th>First {s.settings.firstKg} kg</th>
                    <th>Each +{s.settings.stepKg} kg</th>
                    <th>Days</th>
                  </Fragment>
                ),
              )}
              {grid ? <th>Prices 1–{kgs[kgs.length - 1]} kg</th> : null}
              <th />
            </tr>
          </thead>
          <tbody>
            {s.destinations.map((x) => {
              const hidden = !!k && !nameKey(x.name).includes(k);
              const isChanged = !!changed[x.id];
              const isOpen = open === x.id;
              return (
                <Fragment key={x.id}>
                  <tr className={`${x.active ? "" : "inactive"}${isChanged ? " changed" : ""}`} hidden={hidden}>
                    <td>
                      <input
                        className="name"
                        type="text"
                        defaultValue={x.name}
                        aria-label="Destination name"
                        disabled={readOnly}
                        onChange={(e) =>
                          update((d) => {
                            const y = d.destinations.find((z) => z.id === x.id);
                            if (y) y.name = e.target.value;
                          })
                        }
                      />
                    </td>
                    <td className="ctr">
                      <input
                        type="checkbox"
                        defaultChecked={x.active}
                        aria-label={`Show ${x.name} on website`}
                        disabled={readOnly}
                        onChange={(e) =>
                          update((d) => {
                            const y = d.destinations.find((z) => z.id === x.id);
                            if (y) y.active = e.target.checked;
                          })
                        }
                      />
                    </td>
                    {s.services.map((sv) => {
                      const r = x.rates[sv.id] ?? {};
                      const cell = (field: "doc" | "first" | "addl", label: string) => (
                        <td key={field}>
                          <input
                            className="num"
                            type="number"
                            inputMode="decimal"
                            min="0"
                            step="any"
                            defaultValue={isNum(r[field]) ? r[field] : ""}
                            placeholder="—"
                            aria-label={`${x.name} ${sv.name} ${label}`}
                            disabled={readOnly}
                            onChange={(e) => setRate(x.id, sv.id, field, e.target.value)}
                          />
                        </td>
                      );
                      return (
                        <Fragment key={sv.id}>
                          {cell("doc", "document rate")}
                          {!grid ? cell("first", "first slab") : null}
                          {!grid ? cell("addl", "per step") : null}
                          <td>
                            <input
                              className="days"
                              type="text"
                              defaultValue={r.days ?? ""}
                              placeholder="3–5"
                              aria-label={`${x.name} ${sv.name} days`}
                              disabled={readOnly}
                              onChange={(e) => setDays(x.id, sv.id, e.target.value)}
                            />
                          </td>
                        </Fragment>
                      );
                    })}
                    {grid ? (
                      <td style={{ whiteSpace: "nowrap" }}>
                        <button className="btn small" type="button" aria-expanded={isOpen} onClick={() => setOpen(isOpen ? null : x.id)}>
                          {isOpen ? "Hide prices ▴" : "Prices ▾"}
                        </button>
                        <div className="hint" style={{ marginTop: 4 }}>
                          {s.services.map((sv) => `${sv.name}: ${gridSummary(x, sv.id)}`).join(" · ")}
                        </div>
                      </td>
                    ) : null}
                    <td className="ctr" style={{ whiteSpace: "nowrap" }}>
                      {isChanged && !readOnly ? (
                        <>
                          <button
                            className="btn small"
                            type="button"
                            aria-label={`Undo changes to ${x.name}`}
                            onClick={() =>
                              update((d) => {
                                const idx = d.destinations.findIndex((z) => z.id === x.id);
                                const orig = live.destinations.find((z) => z.id === x.id);
                                if (idx < 0) return;
                                if (orig) d.destinations[idx] = structuredClone(orig);
                                else d.destinations.splice(idx, 1);
                              })
                            }
                          >
                            Undo
                          </button>{" "}
                        </>
                      ) : null}
                      <button
                        className="btn small danger"
                        type="button"
                        disabled={readOnly}
                        aria-label={`Remove ${x.name}`}
                        onClick={() => {
                          if (confirmDel !== x.id) {
                            setConfirmDel(x.id);
                            return;
                          }
                          setConfirmDel(null);
                          update((d) => {
                            d.destinations = d.destinations.filter((z) => z.id !== x.id);
                          });
                        }}
                      >
                        {confirmDel === x.id ? "Confirm remove" : "Remove"}
                      </button>
                    </td>
                  </tr>
                  {grid && isOpen && !hidden ? (
                    <tr className="grid-row">
                      <td colSpan={2 + s.services.length * 2 + 2}>
                        <GridEditor dest={x} services={s.services} kgs={kgs} readOnly={readOnly} onChange={(svc, kg, raw) => setGridPrice(x.id, svc, kg, raw)} update={update} />
                      </td>
                    </tr>
                  ) : null}
                </Fragment>
              );
            })}
          </tbody>
        </table>
      </div>
      <p className="hint" style={{ marginTop: 8 }}>
        {grid
          ? `Prices are what the customer pays, before any tax you set in Settings. Open “Prices” on a country to enter its price for every kilogram from 1 kg to ${kgs[kgs.length - 1]} kg (the cargo threshold in Settings); a parcel is charged at the next whole kilogram up, and a blank kilogram uses the next heavier priced one. Docs is a flat price for documents up to the weight in Settings. Changed rows are marked on the left; Undo restores the live version of that row.`
          : "Prices are what the customer pays, before any tax you set in Settings. Docs is a flat price for documents up to the weight in Settings; leave it blank to charge documents like packages. Leave First and Each blank to not offer a service for that destination. Changed rows are marked on the left; Undo restores the live version of that row."}
      </p>
    </section>
  );
}

interface GridEditorProps {
  dest: Destination;
  services: SiteData["services"];
  kgs: number[];
  readOnly: boolean;
  onChange: (svc: string, kg: number, raw: string) => void;
  update: (fn: (d: SiteData) => void) => void;
}

/** One row of kilogram boxes per service, with a quick fill so 25 boxes need two numbers, not twenty-five. */
function GridEditor({ dest, services, kgs, readOnly, onChange, update }: GridEditorProps) {
  const [fill, setFill] = useState<Record<string, { start: string; step: string }>>({});
  // Remount the boxes after a fill so the uncontrolled inputs show the new values.
  const [gen, setGen] = useState(0);
  const apply = (svc: string, overwrite: boolean) => {
    const f = fill[svc] ?? { start: "", step: "" };
    const start = Number(f.start);
    const step = Number(f.step);
    if (!Number.isFinite(start) || start <= 0 || !Number.isFinite(step) || step < 0) return;
    update((d) => {
      const x = d.destinations.find((y) => y.id === dest.id);
      if (!x) return;
      const r = { ...(x.rates[svc] ?? {}) };
      const g = { ...(r.grid ?? {}) };
      kgs.forEach((kg, i) => {
        if (overwrite || !isNum(g[String(kg)])) g[String(kg)] = Math.round(start + i * step);
      });
      r.grid = g;
      x.rates[svc] = r;
    });
    setGen((n) => n + 1);
  };
  return (
    <div className="grid-editor" key={gen}>
      {services.map((sv) => {
        const g = dest.rates[sv.id]?.grid ?? {};
        const f = fill[sv.id] ?? { start: "", step: "" };
        return (
          <div key={sv.id} className="grid-service">
            <div className="grid-head">
              <strong className={`grp ${sv.id}`}>{sv.name}</strong>
              <span className="hint">Quick fill:</span>
              <input type="number" className="num" placeholder="1 kg price" value={f.start} onChange={(e) => setFill((m) => ({ ...m, [sv.id]: { ...f, start: e.target.value } }))} disabled={readOnly} aria-label={`${sv.name} quick fill start`} />
              <span className="hint">+ per kg</span>
              <input type="number" className="num" placeholder="per kg" value={f.step} onChange={(e) => setFill((m) => ({ ...m, [sv.id]: { ...f, step: e.target.value } }))} disabled={readOnly} aria-label={`${sv.name} quick fill step`} />
              <button className="btn small" type="button" disabled={readOnly} onClick={() => apply(sv.id, false)}>
                Fill blanks
              </button>
              <button className="btn small" type="button" disabled={readOnly} onClick={() => apply(sv.id, true)}>
                Fill all
              </button>
            </div>
            <div className="grid-boxes">
              {kgs.map((kg) => (
                <label key={kg} className="grid-box">
                  <span>{kg} kg</span>
                  <input
                    type="number"
                    inputMode="decimal"
                    min="0"
                    step="any"
                    defaultValue={isNum(g[String(kg)]) ? g[String(kg)] : ""}
                    placeholder="—"
                    aria-label={`${dest.name} ${sv.name} ${kg} kg price`}
                    disabled={readOnly}
                    onChange={(e) => onChange(sv.id, kg, e.target.value)}
                  />
                </label>
              ))}
            </div>
          </div>
        );
      })}
    </div>
  );
}
