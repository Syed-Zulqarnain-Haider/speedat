/**
 * What changed between two site documents, and whether a draft may be
 * published. Pure functions: the admin shows them, the publish action
 * enforces them, tests pin them.
 */
import { isNum, lines, parts, toNumLoose } from "@/lib/pricing/engine";
import { badHolidayLines, workingSet } from "@/lib/pricing/dates";
import { fmtMoney, nameKey } from "@/lib/pricing/format";
import type { SiteData } from "./types";

export interface DiffLine {
  kind: "added" | "removed" | "renamed" | "visibility" | "rate" | "field" | "profile";
  label: string;
  old?: string;
  new?: string;
  /** e.g. "+40% — please double-check" for large rate moves. */
  flag?: string;
}

export interface Diff {
  count: number;
  /** Destination ids that changed in any way. */
  byDest: Record<string, true>;
  lines: DiffLine[];
}

/** Rate moves at or above this are flagged in the review. */
export const FLAG_PCT = 25;

const short = (v: unknown): string => {
  const s = String(v ?? "");
  return s.length > 90 ? `${s.slice(0, 90)}…` : s;
};

export function diffSite(a: SiteData, b: SiteData): Diff {
  const out: Diff = { count: 0, byDest: {}, lines: [] };
  const cur = b.settings.currency;
  const am = new Map(a.destinations.map((x) => [x.id, x]));
  const bm = new Map(b.destinations.map((x) => [x.id, x]));
  const money = (v: unknown) => (v === "" || v == null ? "—" : fmtMoney(Number(v), cur));
  for (const [id, x] of bm) {
    const y = am.get(id);
    if (!y) {
      out.count++;
      out.byDest[id] = true;
      out.lines.push({ kind: "added", label: "Added", new: x.name + (x.active ? "" : " (hidden from site)") });
      continue;
    }
    if (x.name !== y.name) {
      out.count++;
      out.byDest[id] = true;
      out.lines.push({ kind: "renamed", label: "Renamed", old: y.name, new: x.name });
    }
    if (!!x.active !== !!y.active) {
      out.count++;
      out.byDest[id] = true;
      out.lines.push({ kind: "visibility", label: `${x.name}: ${x.active ? "now shown on the site" : "hidden from the site"}` });
    }
    for (const sv of b.services) {
      const r1 = y.rates[sv.id] ?? {};
      const r2 = x.rates[sv.id] ?? {};
      for (const f of ["first", "addl", "days", "doc"] as const) {
        const v1 = r1[f] ?? "";
        const v2 = r2[f] ?? "";
        if (String(v1) === String(v2)) continue;
        out.count++;
        out.byDest[id] = true;
        const lab = f === "days" ? "days" : f === "first" ? "first slab" : f === "doc" ? "documents" : "per step";
        const pct = f !== "days" && isNum(v1) && isNum(v2) && v1 > 0 ? Math.round(((v2 - v1) / v1) * 100) : null;
        out.lines.push({
          kind: "rate",
          label: `${x.name} · ${sv.name} ${lab}`,
          old: f === "days" ? String(v1) : money(v1),
          new: f === "days" ? String(v2) : money(v2),
          flag: pct !== null && Math.abs(pct) >= FLAG_PCT ? `${pct > 0 ? "+" : ""}${pct}% — please double-check` : undefined,
        });
      }
    }
  }
  for (const [id, y] of am) {
    if (bm.has(id)) continue;
    out.count++;
    out.byDest[id] = true;
    out.lines.push({ kind: "removed", label: "Removed", old: y.name });
  }
  for (const g of ["company", "settings", "content"] as const) {
    const ga = a[g] as unknown as Record<string, unknown>;
    const gb = b[g] as unknown as Record<string, unknown>;
    for (const k of Object.keys(gb)) {
      if (String(ga[k] ?? "") === String(gb[k] ?? "")) continue;
      out.count++;
      out.lines.push({ kind: "field", label: g === "content" ? `Website text · ${k}` : k, old: short(ga[k]), new: short(gb[k]) });
    }
  }
  if (!!a.live !== !!b.live) {
    out.count++;
    out.lines.push({ kind: "field", label: "Rates are live", old: String(!!a.live), new: String(!!b.live) });
  }
  const ap = a.importProfiles.map((p) => p.signature).join("\n");
  const bp = b.importProfiles.map((p) => p.signature).join("\n");
  if (ap !== bp) {
    out.count++;
    out.lines.push({ kind: "profile", label: "Import layout remembered", new: b.importProfiles.at(-1)?.name || "sheet" });
  }
  return out;
}

/** Blocking problems. Empty array = may publish. */
export function validateSite(s: SiteData): string[] {
  const errs: string[] = [];
  const st = s.settings;
  if (!String(s.company.name ?? "").trim()) errs.push("Company name is empty.");
  if (!/^[0-9]{8,15}$/.test(String(s.company.whatsapp ?? ""))) errs.push("WhatsApp number must be 8–15 digits with the country code, e.g. 923001234567.");
  if (!(st.volumetricDivisor >= 1000 && st.volumetricDivisor <= 10000)) errs.push("Volumetric divisor must be between 1000 and 10000.");
  if (!(st.firstKg > 0)) errs.push("First weight slab must be greater than 0.");
  if (!(st.stepKg > 0)) errs.push("Additional weight step must be greater than 0.");
  if (!(st.taxPct >= 0 && st.taxPct <= 100)) errs.push("Tax must be between 0 and 100%.");
  if (!(st.roundTo >= 1)) errs.push("Rounding must be 1 or more.");
  if (!(st.maxKg >= 0)) errs.push("Cargo threshold must be 0 or more.");
  if (!(st.docMaxKg > 0)) errs.push("Document weight limit must be greater than 0.");
  for (const ln of lines(st.addons)) {
    const p = parts(ln, 3);
    const amt = toNumLoose(p[1]);
    if (!p[0] || !isNum(amt) || amt < 0) errs.push(`Optional charge “${ln}” must be written as Label | Amount | on/off with a positive amount.`);
  }
  if (st.cutoffHour != null && !(st.cutoffHour >= 0 && st.cutoffHour <= 23)) errs.push("Cutoff hour must be between 0 and 23, or blank.");
  if (String(st.workingDays ?? "").trim() && !/mon|tue|wed|thu|fri|sat|sun/i.test(String(st.workingDays))) errs.push("Working days must be day names such as Mon, Tue, Wed.");
  if (String(st.workingDays ?? "").trim() && !workingSet(st).size) errs.push("Working days must be day names such as Mon, Tue, Wed.");
  if (!String(st.currency ?? "").trim() || String(st.currency).length > 8) errs.push("Currency label must be 1–8 characters, such as PKR or Rs.");
  for (const ln of badHolidayLines(st).slice(0, 5)) errs.push(`Holiday “${ln}” must be written as YYYY-MM-DD, optionally followed by | a name.`);
  if (!s.services.length) errs.push("At least one service is needed.");
  const seen = new Set<string>();
  let activeCount = 0;
  for (const x of s.destinations) {
    const nm = String(x.name ?? "").trim();
    if (!nm) {
      errs.push("A destination has no name.");
      continue;
    }
    const key = nameKey(nm);
    if (seen.has(key)) errs.push(`“${nm}” appears twice.`);
    seen.add(key);
    if (!x.active) continue;
    activeCount++;
    let priced = 0;
    for (const sv of s.services) {
      const r = x.rates[sv.id];
      if (!r) continue;
      if (r.doc != null && !(isNum(r.doc) && r.doc > 0)) errs.push(`${nm} · ${sv.name}: document price must be greater than 0 or left blank.`);
      if (r.first == null && r.addl == null) {
        if (r.doc != null) errs.push(`${nm} · ${sv.name}: has a document price but no package prices — customers sending packages would see it as unavailable.`);
        continue;
      }
      if (!(isNum(r.first) && r.first > 0)) errs.push(`${nm} · ${sv.name}: first-slab price must be greater than 0.`);
      if (!(isNum(r.addl) && r.addl >= 0)) errs.push(`${nm} · ${sv.name}: per-step price is missing.`);
      priced++;
    }
    if (!priced) errs.push(`${nm} is shown on the site but has no prices.`);
  }
  if (!activeCount) errs.push("At least one destination must be shown on the site.");
  return errs;
}

/** Non-blocking oddities worth a second look before publishing. */
export function warnSite(s: SiteData): string[] {
  const w: string[] = [];
  const cur = s.settings.currency;
  const [ex, no] = s.services;
  for (const x of s.destinations) {
    if (!x.active) continue;
    const r = x.rates;
    for (const sv of s.services) {
      const q = r[sv.id];
      if (q && isNum(q.first) && isNum(q.addl) && q.addl > q.first)
        w.push(`${x.name} · ${sv.name}: the per-step price (${fmtMoney(q.addl, cur)}) is higher than the first slab (${fmtMoney(q.first, cur)}).`);
    }
    if (ex && no) {
      const a = r[ex.id];
      const b = r[no.id];
      if (a && b && isNum(a.first) && isNum(b.first) && a.first < b.first)
        w.push(`${x.name}: ${ex.name} is cheaper than ${no.name} (${fmtMoney(a.first, cur)} vs ${fmtMoney(b.first, cur)}).`);
    }
    for (const sv of s.services) {
      const q = r[sv.id];
      if (q && isNum(q.first) && !q.days) w.push(`${x.name} · ${sv.name}: no transit days, so no delivery date can be shown.`);
    }
    for (const sv of s.services) {
      const q = r[sv.id];
      if (q && isNum(q.doc) && isNum(q.first) && q.doc > q.first)
        w.push(`${x.name} · ${sv.name}: the document price (${fmtMoney(q.doc, cur)}) is higher than the package first slab (${fmtMoney(q.first, cur)}).`);
    }
  }
  return w;
}

/** One line for the version history, e.g. "12 changes: 3 destinations, settings". */
export function summarise(d: Diff): string {
  const dests = Object.keys(d.byDest).length;
  const fields = d.lines.filter((l) => l.kind === "field").length;
  const bits: string[] = [];
  if (dests) bits.push(`${dests} destination${dests === 1 ? "" : "s"}`);
  if (fields) bits.push("settings or text");
  if (d.lines.some((l) => l.kind === "profile")) bits.push("import layout");
  return `${d.count} change${d.count === 1 ? "" : "s"}${bits.length ? `: ${bits.join(", ")}` : ""}`;
}

