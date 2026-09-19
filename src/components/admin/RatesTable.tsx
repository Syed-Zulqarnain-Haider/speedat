"use client";

import { Fragment, useState } from "react";
import { isNum } from "@/lib/pricing/engine";
import { nameKey, slug } from "@/lib/pricing/format";
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
  return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

export function toCsv(s: SiteData): string {
  const head = ["Destination", "On site"];
  for (const sv of s.services) head.push(`${sv.name} first ${s.settings.firstKg} kg`, `${sv.name} each +${s.settings.stepKg} kg`, `${sv.name} days`, `${sv.name} documents`);
  const out = [head.map(csvCell).join(",")];
  for (const x of s.destinations) {
    const row: unknown[] = [x.name, x.active ? "yes" : "no"];
    for (const sv of s.services) {
      const r = x.rates[sv.id] ?? {};
      row.push(r.first ?? "", r.addl ?? "", r.days ?? "", r.doc ?? "");
    }
    out.push(row.map(csvCell).join(","));
  }
  return `${out.join("\r\n")}\r\n`;
}

export function RatesTable({ draft, live, changed, readOnly, update, epoch, toast }: Props) {
  const [filter, setFilter] = useState("");
  const [confirmDel, setConfirmDel] = useState<string | null>(null);
  const s = draft;
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
      if (r.first == null && r.addl == null && r.doc == null && !r.days) delete x.rates[svc];
      else x.rates[svc] = r;
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

  return (
    <section className="block" id="sec-rates" style={{ borderTop: 0, marginTop: 10, paddingTop: 0 }}>
      <div className="toolbar">
        <input type="text" placeholder="Find a destination" aria-label="Find a destination" value={filter} onChange={(e) => setFilter(e.target.value)} />
        <button className="btn small" type="button" disabled={readOnly} onClick={() => update((d) => d.destinations.sort((a, b) => a.name.localeCompare(b.name)))}>
          Sort A–Z
        </button>
        <button
          className="btn small"
          type="button"
          disabled={readOnly}
          onClick={() =>
            update((d) => {
              d.destinations.push({ id: uniqueId(slug("New destination"), d.destinations), name: "New destination", active: false, rates: {} });
            })
          }
        >
          Add destination
        </button>
        <button className="btn small" type="button" onClick={download}>
          Download as CSV
        </button>
        <button className="btn small" type="button" onClick={copy}>
          Copy as CSV
        </button>
      </div>
      <div className="tablewrap">
        <table className="rates" key={epoch}>
          <thead>
            <tr>
              <th />
              <th />
              {s.services.map((sv) => (
                <th key={sv.id} className={`grp ${sv.id}`} colSpan={4}>
                  {sv.name}
                </th>
              ))}
              <th />
            </tr>
            <tr>
              <th>Destination</th>
              <th>On site</th>
              {s.services.map((sv) => (
                <SubHead key={sv.id} s={s} />
              ))}
              <th />
            </tr>
          </thead>
          <tbody>
            {s.destinations.map((x) => {
              const hidden = !!k && !nameKey(x.name).includes(k);
              const isChanged = !!changed[x.id];
              return (
                <tr key={x.id} className={`${x.active ? "" : "inactive"}${isChanged ? " changed" : ""}`} hidden={hidden}>
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
                        {cell("first", "first slab")}
                        {cell("addl", "per step")}
                        <td>
                          <input
                            className="days"
                            type="text"
                            defaultValue={r.days ?? ""}
                            placeholder="3–5"
                            aria-label={`${x.name} ${sv.name} days`}
                            disabled={readOnly}
                            onChange={(e) =>
                              update((d) => {
                                const y = d.destinations.find((z) => z.id === x.id);
                                if (!y) return;
                                const rr = { ...(y.rates[sv.id] ?? {}) };
                                if (e.target.value.trim()) rr.days = e.target.value.trim();
                                else delete rr.days;
                                if (rr.first == null && rr.addl == null && rr.doc == null && !rr.days) delete y.rates[sv.id];
                                else y.rates[sv.id] = rr;
                              })
                            }
                          />
                        </td>
                      </Fragment>
                    );
                  })}
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
              );
            })}
          </tbody>
        </table>
      </div>
      <p className="hint" style={{ marginTop: 8 }}>
        Prices are what the customer pays, before any tax you set in Settings. Docs is a flat price for documents up to the weight in Settings; leave it blank to
        charge documents like packages. Leave First and Each blank to not offer a service for that destination. Changed rows are marked on the left; Undo
        restores the live version of that row.
      </p>
    </section>
  );
}

function SubHead({ s }: { s: SiteData }) {
  return (
    <>
      <th>Docs ≤{s.settings.docMaxKg} kg</th>
      <th>First {s.settings.firstKg} kg</th>
      <th>Each +{s.settings.stepKg} kg</th>
      <th>Days</th>
    </>
  );
}
