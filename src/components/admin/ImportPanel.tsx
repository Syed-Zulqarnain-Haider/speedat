"use client";

/**
 * "Import from Excel": upload or paste a rate sheet, confirm which columns
 * mean what (remembered per layout), preview what will change, apply it to
 * the editor. Sheets that arrived by email with an unknown layout appear in
 * the list below and open in the same mapper.
 */
import { useMemo, useState, type ReactNode } from "react";
import { applyImportAction, loadImportAction, rejectImportAction, saveIntakeAction, uploadSheetAction, type SheetPayload } from "@/app/admin/import-actions";
import type { ImportStatus, ImportSummary, IntakeSettings } from "@/lib/import/intake";
import { applyImport, buildImport, buildPasteImport, findProfile, headerNames, headerSignature, mapFields } from "@/lib/import/parse";
import type { ImportOptions, ImportResult } from "@/lib/import/types";
import { fmtDate, fmtDateTime, fmtNum } from "@/lib/pricing/format";
import type { SiteData } from "@/lib/site/types";

interface Props {
  draft: SiteData;
  imports: ImportSummary[];
  intake: IntakeSettings;
  isOwner: boolean;
  /** Replace the editor's draft with the server's post-import draft (already saved). */
  adopt: (data: SiteData) => void;
  /** Replace the editor's draft with a local edit that still needs saving. */
  adoptLocal: (data: SiteData) => void;
  toast: (m: string) => void;
  refresh: () => void;
}

const STATUS_LABEL: Record<ImportStatus | string, string> = {
  needs_mapping: "needs mapping",
  applied: "in the editor — publish to go live",
  published: "published automatically",
  rejected: "rejected",
  failed: "could not be read",
};

export function ImportPanel({ draft, imports, intake, isOwner, adopt, adoptLocal, toast, refresh }: Props) {
  const [sheet, setSheet] = useState<SheetPayload | null>(null);
  const [current, setCurrent] = useState("");
  const [headerRow, setHeaderRow] = useState(0);
  const [map, setMap] = useState<Record<string, number>>({});
  const [opts, setOpts] = useState<ImportOptions>({ addNew: true, hideMissing: false, cost: false, margin: 15, mround: 10 });
  const [busy, setBusy] = useState(false);
  const [pasteText, setPasteText] = useState("");
  const [pasted, setPasted] = useState<ImportResult | null>(null);
  const [msg, setMsg] = useState<ReactNode>(null);

  const rows = useMemo(() => (sheet ? (sheet.sheets[current] ?? []) : []), [sheet, current]);
  const headers = useMemo(() => headerNames(rows[headerRow] ?? []), [rows, headerRow]);
  const profile = useMemo(() => (sheet ? findProfile(draft.importProfiles, headerSignature(headers)) : undefined), [sheet, draft.importProfiles, headers]);
  const fields = useMemo(() => mapFields(draft.services), [draft.services]);
  const preview = useMemo<ImportResult | null>(
    () => (sheet && rows.length ? buildImport({ rows, headerRow, map, opts, draft, fileName: sheet.fileName }) : null),
    [sheet, rows, headerRow, map, opts, draft],
  );

  const open = (p: SheetPayload) => {
    setSheet(p);
    setCurrent(p.sheet);
    setHeaderRow(p.headerRow);
    setMap(p.map);
    const prof = findProfile(draft.importProfiles, headerSignature(headerNames((p.sheets[p.sheet] ?? [])[p.headerRow] ?? [])));
    if (prof) setOpts((o) => ({ ...o, cost: prof.cost, margin: prof.margin, mround: prof.mround }));
    setPasted(null);
    setMsg(null);
  };

  const onFile = async (file: File | null) => {
    if (!file) return;
    setBusy(true);
    setMsg(<p className="meta">Reading {file.name}…</p>);
    const fd = new FormData();
    fd.append("file", file);
    const res = await uploadSheetAction(fd);
    setBusy(false);
    if (!res.ok) return setMsg(<div className="notice err">{res.message}</div>);
    open(res);
    refresh();
  };

  const openExisting = async (id: number) => {
    setBusy(true);
    const res = await loadImportAction(id);
    setBusy(false);
    if (!res.ok) return toast(res.message);
    open(res);
    document.getElementById("sec-import")?.scrollIntoView({ behavior: "smooth", block: "start" });
  };

  const changeSheet = (name: string) => {
    setCurrent(name);
    setHeaderRow(0);
  };

  const apply = async () => {
    if (!sheet || !preview) return;
    setBusy(true);
    const res = await applyImportAction({ importId: sheet.importId, sheet: current, headerRow, map, opts });
    setBusy(false);
    if (!res.ok) return setMsg(<div className="notice err">{res.message}</div>);
    adopt(res.draft);
    setSheet(null);
    setMsg(null);
    toast("Import applied — review and publish");
    refresh();
    document.getElementById("sec-rates")?.scrollIntoView({ behavior: "smooth", block: "start" });
  };

  const applyPasted = () => {
    if (!pasted) return;
    // Pasted rows never carry a layout to remember; applying is a local edit, like typing.
    adoptLocal(applyImport(draft, pasted));
    setPasted(null);
    setPasteText("");
    toast("Rows applied — review and publish");
  };

  const reject = async (id: number) => {
    const res = await rejectImportAction(id);
    if (!res.ok) return toast(res.message);
    if (sheet?.importId === id) setSheet(null);
    refresh();
  };

  const pending = imports.filter((i) => i.status === "needs_mapping" && i.source === "email");

  return (
    <section className="block" id="sec-import">
      <h2>Import from Excel</h2>
      <p className="desc">
        Upload the rate sheet your carrier sent (.xlsx, .xls or .csv), tell it once which columns hold what, and it remembers that layout next time. Sheets emailed to
        your intake address with a known layout are applied here automatically.
      </p>
      {pending.length ? (
        <div className="notice warn">
          <strong>
            {pending.length} emailed sheet{pending.length === 1 ? "" : "s"} waiting for a column mapping
          </strong>
          <ul>
            {pending.map((i) => (
              <li key={i.id}>
                {i.fileName} from {i.fromEmail ?? "unknown"} ({fmtDateTime(i.receivedAt)}){" "}
                <button className="btn small" type="button" disabled={busy} onClick={() => openExisting(i.id)}>
                  Map columns
                </button>
              </li>
            ))}
          </ul>
        </div>
      ) : null}
      <div className="inline">
        <label className="field" style={{ flex: 2 }}>
          <span>Rate sheet file</span>
          <input type="file" accept=".xlsx,.xlsm,.xls,.csv,.tsv,.txt" disabled={busy} onChange={(e) => onFile(e.target.files?.[0] ?? null)} />
        </label>
      </div>
      {msg}

      {sheet ? (
        <>
          <div className="inline" style={{ marginTop: 6 }}>
            {sheet.names.length > 1 ? (
              <label className="field">
                <span>Sheet</span>
                <select value={current} onChange={(e) => changeSheet(e.target.value)}>
                  {sheet.names.map((n) => (
                    <option key={n} value={n}>
                      {n} ({sheet.sheets[n]?.length ?? 0} rows)
                    </option>
                  ))}
                </select>
              </label>
            ) : null}
            <label className="field" style={{ maxWidth: 200 }}>
              <span>Header row number</span>
              <input type="number" min={1} step={1} value={headerRow + 1} onChange={(e) => setHeaderRow(Math.max(0, Math.min(rows.length - 1, (Number(e.target.value) || 1) - 1)))} />
            </label>
            <span className="meta">{sheet.fileName}</span>
          </div>
          <div className="tablewrap" style={{ marginTop: 8 }}>
            <table className="preview">
              <tbody>
                {rows.slice(0, headerRow + 6).map((r, i) => (
                  <tr key={i} className={i === headerRow ? "hdr" : ""}>
                    <td className="meta">{i + 1}</td>
                    {r.map((c, j) => (
                      <td key={j}>{c}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {Object.keys(map).some((k) => k.includes(".kg.")) ? (
            <div className="notice ok">
              Weight columns detected:{" "}
              {draft.services
                .map((sv) => `${sv.name} ${Object.keys(map).filter((k) => k.startsWith(`${sv.id}.kg.`) && map[k]! >= 0).length}`)
                .join(", ")}{" "}
              — they import as per-kilogram prices.
            </div>
          ) : null}
          {profile ? (
            <div className="notice ok">This layout is recognised — the column mapping saved on {fmtDate(profile.savedAt)} is applied. Adjust it below if needed.</div>
          ) : (
            <div className="notice info">Match each field to a column. This mapping is saved when you apply the import, so next time the same sheet layout is recognised automatically.</div>
          )}
          <div className="map-grid">
            {fields.map((f) => (
              <label className="field" key={f.key}>
                <span>
                  {f.label}
                  {f.req ? "" : <em className="hint"> (optional)</em>}
                </span>
                <select value={String(map[f.key] ?? -1)} onChange={(e) => setMap((m) => ({ ...m, [f.key]: Number(e.target.value) }))}>
                  <option value="-1">— not in file —</option>
                  {headers.map((h, i) => (
                    <option key={i} value={String(i)}>
                      {h}
                    </option>
                  ))}
                </select>
              </label>
            ))}
          </div>
          <label className="chk">
            <input type="checkbox" checked={opts.addNew} onChange={(e) => setOpts((o) => ({ ...o, addNew: e.target.checked }))} /> Add destinations that are not in the list yet
          </label>
          <label className="chk">
            <input type="checkbox" checked={opts.hideMissing} onChange={(e) => setOpts((o) => ({ ...o, hideMissing: e.target.checked }))} /> Hide destinations that are missing from this
            file
          </label>
          <label className="chk">
            <input type="checkbox" checked={opts.cost} onChange={(e) => setOpts((o) => ({ ...o, cost: e.target.checked }))} /> Prices in this file are carrier costs — add my margin
          </label>
          {opts.cost ? (
            <div className="inline" style={{ margin: "4px 0 8px 26px" }}>
              <label className="field" style={{ maxWidth: 160 }}>
                <span>Margin %</span>
                <input type="number" step="any" value={opts.margin} onChange={(e) => setOpts((o) => ({ ...o, margin: Number(e.target.value) || 0 }))} />
              </label>
              <label className="field" style={{ maxWidth: 160 }}>
                <span>Round to nearest</span>
                <input type="number" min={1} step={1} value={opts.mround} onChange={(e) => setOpts((o) => ({ ...o, mround: Number(e.target.value) || 1 }))} />
              </label>
            </div>
          ) : null}
          {preview ? <Preview im={preview} draft={draft} onApply={apply} busy={busy} /> : null}
          <p style={{ marginTop: 10 }}>
            <button className="btn small" type="button" onClick={() => setSheet(null)}>
              Close without applying
            </button>{" "}
            <button className="btn small danger" type="button" onClick={() => reject(sheet.importId)}>
              Reject this sheet
            </button>
          </p>
        </>
      ) : null}

      <details style={{ marginTop: 14 }}>
        <summary>Paste rows instead</summary>
        <p className="hint" style={{ margin: "8px 0" }}>
          Column order: Destination, {draft.services.map((sv) => `${sv.name} first, ${sv.name} additional, ${sv.name} days, ${sv.name} documents`).join(", ")}. Documents columns may
          be left empty. A header row is ignored.
        </p>
        <textarea className="paste" value={pasteText} placeholder={"United Kingdom\t4500\t1100\t3-5\t3900\t3200\t850\t6-9\t2800"} onChange={(e) => setPasteText(e.target.value)} />
        <div style={{ marginTop: 10 }}>
          <button className="btn" type="button" onClick={() => setPasted(buildPasteImport(pasteText, draft))}>
            Preview pasted rows
          </button>
        </div>
        {pasted ? <Preview im={pasted} draft={draft} onApply={applyPasted} busy={busy} /> : null}
      </details>

      <h3 style={{ margin: "22px 0 6px" }}>Recent sheets</h3>
      {imports.length ? (
        <ul className="hist">
          {imports.map((i) => (
            <li key={i.id}>
              <strong>{i.fileName}</strong>
              <span className="meta">
                {fmtDateTime(i.receivedAt)} · {i.source === "email" ? `from ${i.fromEmail ?? "unknown"}` : "uploaded"}
              </span>
              <span className={`meta${i.status === "failed" ? " warn" : ""}`}>
                {STATUS_LABEL[i.status] ?? i.status}
                {i.appliedVersion ? ` (version ${i.appliedVersion})` : ""}
                {i.rows ? ` · ${i.rows} rows` : ""}
                {i.errors ? ` · ${i.errors} skipped` : ""}
                {i.error ? ` · ${i.error}` : ""}
              </span>
              {i.status === "needs_mapping" ? (
                <button className="btn small" type="button" disabled={busy} onClick={() => openExisting(i.id)}>
                  Open
                </button>
              ) : null}
            </li>
          ))}
        </ul>
      ) : (
        <p className="hint">No sheets yet.</p>
      )}

      <IntakeSettingsForm intake={intake} isOwner={isOwner} toast={toast} />
    </section>
  );
}

function Preview({ im, draft, onApply, busy }: { im: ImportResult; draft: SiteData; onApply: () => void; busy: boolean }) {
  const newN = im.rows.filter((r) => !r.existing).length;
  const addN = im.opts.addNew ? newN : 0;
  const updN = im.rows.length - newN;
  const hideN = im.hide?.length ?? 0;
  const fv = (c: number | null, v: number | null) => (v == null ? "—" : im.opts.cost && c != null ? `${fmtNum(c)} → ${fmtNum(v)}` : fmtNum(v));
  return (
    <div>
      {im.errors.length ? (
        <div className="notice err">
          <strong>These rows will be skipped</strong>
          <ul>
            {im.errors.map((e, i) => (
              <li key={i}>{e}</li>
            ))}
          </ul>
        </div>
      ) : null}
      {im.warnings.length ? (
        <div className="notice warn">
          <strong>Worth a look</strong>
          <ul>
            {im.warnings.slice(0, 20).map((e, i) => (
              <li key={i}>{e}</li>
            ))}
            {im.warnings.length > 20 ? <li>…and {im.warnings.length - 20} more</li> : null}
          </ul>
        </div>
      ) : null}
      {im.rows.length ? (
        <div className="notice info">
          <strong>
            {im.rows.length} destination{im.rows.length === 1 ? "" : "s"} found:
          </strong>{" "}
          {updN} will be updated, {addN} will be added{newN - addN ? ` (${newN - addN} new ones ignored)` : ""}
          {hideN ? `, ${hideN} hidden from the site` : ""}.{im.opts.cost ? ` Carrier cost → your price after +${im.opts.margin}% margin.` : ""}
          <ul className="diff">
            {im.rows.map((r) => (
              <li key={r.name}>
                {r.name} —{" "}
                {draft.services
                  .map((sv) => {
                    const x = r.rates[sv.id];
                    if (!x) return "";
                    if (x.grid) {
                      const kgs = Object.keys(x.grid).sort((a, b) => Number(a) - Number(b));
                      const lo = kgs[0]!;
                      const hi = kgs[kgs.length - 1]!;
                      return `${sv.name} ${kgs.length} kg prices (${lo}–${hi} kg: ${fv(x.costGrid?.[lo] ?? null, x.grid[lo]!)} … ${fv(x.costGrid?.[hi] ?? null, x.grid[hi]!)})${x.days ? ` (${x.days})` : ""}${x.doc != null ? `, docs ${fv(x.costDoc, x.doc)}` : ""}`;
                    }
                    return `${sv.name} ${fv(x.costFirst, x.first)} / +${fv(x.costAddl, x.addl)}${x.days ? ` (${x.days})` : ""}${x.doc != null ? `, docs ${fv(x.costDoc, x.doc)}` : ""}`;
                  })
                  .filter(Boolean)
                  .join(", ")}
                {r.existing ? "" : <em> (new)</em>}
              </li>
            ))}
          </ul>
          <div style={{ marginTop: 10 }}>
            <button className="btn primary" type="button" disabled={busy} onClick={onApply}>
              Apply to editor
            </button>
          </div>
        </div>
      ) : (
        <div className="notice warn">No destinations with prices were found. Check the header row and the column mapping.</div>
      )}
    </div>
  );
}

function IntakeSettingsForm({ intake, isOwner, toast }: { intake: IntakeSettings; isOwner: boolean; toast: (m: string) => void }) {
  const [pct, setPct] = useState(String(intake.autoPublishPct));
  const [senders, setSenders] = useState(intake.allowedSenders.join(", "));
  const [hour, setHour] = useState(intake.expectedByHour == null ? "" : String(intake.expectedByHour));
  const [busy, setBusy] = useState(false);
  const save = async () => {
    setBusy(true);
    const res = await saveIntakeAction({
      autoPublishPct: Number(pct) || 0,
      allowedSenders: senders.split(/[,\n;]+/).map((s) => s.trim()).filter(Boolean),
      expectedByHour: hour.trim() === "" ? null : Number(hour),
    });
    setBusy(false);
    toast(res.ok ? "Intake settings saved" : res.message);
  };
  return (
    <details style={{ marginTop: 18 }}>
      <summary>Email intake settings</summary>
      <p className="hint" style={{ margin: "8px 0" }}>
        Sheets emailed to the intake address are read automatically. A known layout is applied to the editor; it is published without you only when auto-publish is on
        and no price moves by more than the tolerance. Otherwise it waits here for your review.
      </p>
      <div className="inline">
        <label className="field" style={{ maxWidth: 220 }}>
          <span>Auto-publish if no price moves more than (%)</span>
          <input type="number" min={0} max={100} step="any" value={pct} placeholder="0 = never" disabled={!isOwner} onChange={(e) => setPct(e.target.value)} />
        </label>
        <label className="field" style={{ flex: 2 }}>
          <span>Accept sheets only from (emails or @domains, comma-separated; blank = anyone with the webhook secret)</span>
          <input type="text" value={senders} placeholder="rates@airline.com, @partner.pk" disabled={!isOwner} onChange={(e) => setSenders(e.target.value)} />
        </label>
        <label className="field" style={{ maxWidth: 200 }}>
          <span>Expect a sheet by hour (0–23)</span>
          <input type="number" min={0} max={23} step={1} value={hour} placeholder="blank = no check" disabled={!isOwner} onChange={(e) => setHour(e.target.value)} />
        </label>
        <button className="btn" type="button" disabled={!isOwner || busy} onClick={save}>
          Save intake settings
        </button>
      </div>
    </details>
  );
}
