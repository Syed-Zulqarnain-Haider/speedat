/**
 * What changed between two site documents, and whether a draft may be
 * published. Pure functions: the admin shows them, the publish action
 * enforces them, tests pin them.
 */
import { gridWeights, isNum, lines, parts, toNumLoose } from "@/lib/pricing/engine";
import { badHolidayLines, workingSet } from "@/lib/pricing/dates";
import { fmtMoney, nameKey } from "@/lib/pricing/format";
import type { SiteData } from "./types";

export interface DiffLine {
  kind: "added" | "removed" | "renamed" | "visibility" | "rate" | "field" | "profile" | "service" | "order";
  label: string;
  old?: string;
  new?: string;
  /** e.g. "+40% — please double-check" for large rate moves. */
  flag?: string;
  /** A price the new document's pricing mode does not read (slab prices under grid pricing, or the reverse): stored, never shown. */
  inert?: true;
}

export interface Diff {
  count: number;
  /** Of `count`, the changes customers will not see because the pricing mode ignores them. */
  inert: number;
  /** Destination ids that changed in any way. */
  byDest: Record<string, true>;
  lines: DiffLine[];
}

/** Rate moves at or above this are flagged in the review. */
export const FLAG_PCT = 25;

/** Most a review line shows per side; the audit log keeps these lines, so they stay short. */
const SHOW = 90;
/** Characters kept before a change that does not fit whole, and after one that does. */
const CTX = 30;
const TAIL = 20;

/**
 * Cut two differing texts to a window that holds the difference. Cutting both to
 * their first 90 characters hid every edit made past them: old and new read the
 * same and the owner could not see what he was about to publish. The window
 * starts at the beginning when that reaches the change, else as late as the
 * change needs; a change longer than the window keeps its run-up instead.
 */
function around(x: string, y: string): [string, string] {
  if (x.length <= SHOW && y.length <= SHOW) return [x, y];
  const min = Math.min(x.length, y.length);
  let p = 0;
  while (p < min && x[p] === y[p]) p++;
  let q = 0;
  while (q < min - p && x[x.length - 1 - q] === y[y.length - 1 - q]) q++;
  const need = Math.max(Math.min(x.length, x.length - q + TAIL), Math.min(y.length, y.length - q + TAIL));
  let start = Math.max(0, need - SHOW);
  if (start > p - CTX) start = Math.max(0, p - CTX);
  const cut = (s: string) => {
    const end = Math.min(s.length, start + SHOW);
    return `${start > 0 ? "…" : ""}${s.slice(start, end)}${end < s.length ? "…" : ""}`;
  };
  return [cut(x), cut(y)];
}

/**
 * Old and new text for a changed field, with `where` naming the changed line(s)
 * of a multi-line field (FAQ, services, story, add-ons, holidays) so only those
 * lines are shown; single lines are windowed around the change.
 */
export function fieldChange(a: unknown, b: unknown): { where?: string; old: string; new: string } {
  const sa = String(a ?? "");
  const sb = String(b ?? "");
  const la = sa ? sa.split(/\r?\n/) : [];
  const lb = sb ? sb.split(/\r?\n/) : [];
  if (la.length <= 1 && lb.length <= 1) {
    const [o, n] = around(sa, sb);
    return { old: o, new: n };
  }
  let i = 0;
  while (i < la.length && i < lb.length && la[i] === lb[i]) i++;
  let j = 0;
  while (j < la.length - i && j < lb.length - i && la[la.length - 1 - j] === lb[lb.length - 1 - j]) j++;
  const oa = la.slice(i, la.length - j);
  const ob = lb.slice(i, lb.length - j);
  const n = Math.max(oa.length, ob.length);
  const span = n <= 1 ? `line ${i + 1}` : `lines ${i + 1}–${i + n}`;
  const where = !oa.length ? `${span} added` : !ob.length ? `${span} removed` : span;
  const [o, nw] = around(oa.join(" ⏎ "), ob.join(" ⏎ "));
  return { where, old: o, new: nw };
}

/** What the pricing mode ignores, as a review note. */
function inertNote(grid: boolean): string {
  return grid ? "not used — the site prices by kilogram grid" : "not used — the site prices by first slab and step";
}

/** Ids in both lists, in the order each list has them; different = the shared items were reordered. */
function sameOrder(a: readonly { id: string }[], b: readonly { id: string }[]): boolean {
  const inB = new Set(b.map((x) => x.id));
  const inA = new Set(a.map((x) => x.id));
  const pa = a.filter((x) => inB.has(x.id)).map((x) => x.id);
  const pb = b.filter((x) => inA.has(x.id)).map((x) => x.id);
  return pa.join("\n") === pb.join("\n");
}

/**
 * Every difference customers could see between live `a` and draft `b`, plus
 * the stored-only ones marked `inert`. Rates are read the way `b`'s pricing
 * mode prices them: under grid pricing a first-slab edit is real data but
 * changes no price on the site, so it is listed with a note (and counted in
 * `inert`) instead of a "+40% double-check" flag that implies it matters.
 * Services, the order of destinations and services (the site lists them in
 * that order), and settings keys only one side has are all compared — a
 * restore of an older version that differs only there used to read "nothing
 * to publish".
 */
export function diffSite(a: SiteData, b: SiteData): Diff {
  const out: Diff = { count: 0, inert: 0, byDest: {}, lines: [] };
  const cur = b.settings.currency;
  const grid = b.settings.pricingMode === "grid";
  /** Slab fields under grid pricing, grid boxes under slab pricing. Documents and days are read by both. */
  const ignored = (f: "first" | "addl" | "grid") => (f === "grid" ? !grid : grid);
  const push = (line: DiffLine) => {
    out.count++;
    if (line.inert) out.inert++;
    out.lines.push(line);
  };
  const svA = new Map(a.services.map((x) => [x.id, x]));
  const svB = new Map(b.services.map((x) => [x.id, x]));
  for (const sv of b.services) {
    const old = svA.get(sv.id);
    if (!old) push({ kind: "service", label: "Service added", new: sv.name });
    else {
      if (old.name !== sv.name) push({ kind: "service", label: "Service renamed", old: old.name, new: sv.name });
      if ((old.note ?? "") !== (sv.note ?? "")) {
        const ch = fieldChange(old.note, sv.note);
        push({ kind: "service", label: `${sv.name} · description`, old: ch.old, new: ch.new });
      }
    }
  }
  for (const sv of a.services) if (!svB.has(sv.id)) push({ kind: "service", label: "Service removed", old: sv.name });
  if (!sameOrder(a.services, b.services)) push({ kind: "order", label: "Service order", old: a.services.map((x) => x.name).join(", "), new: b.services.map((x) => x.name).join(", ") });
  const am = new Map(a.destinations.map((x) => [x.id, x]));
  const bm = new Map(b.destinations.map((x) => [x.id, x]));
  const money = (v: unknown) => (v === "" || v == null ? "—" : fmtMoney(Number(v), cur));
  for (const [id, x] of bm) {
    const y = am.get(id);
    if (!y) {
      out.byDest[id] = true;
      push({ kind: "added", label: "Added", new: x.name + (x.active ? "" : " (hidden from site)") });
      continue;
    }
    if (x.name !== y.name) {
      out.byDest[id] = true;
      push({ kind: "renamed", label: "Renamed", old: y.name, new: x.name });
    }
    if (!!x.active !== !!y.active) {
      out.byDest[id] = true;
      push({ kind: "visibility", label: `${x.name}: ${x.active ? "now shown on the site" : "hidden from the site"}` });
    }
    for (const sv of b.services) {
      const r1 = y.rates[sv.id] ?? {};
      const r2 = x.rates[sv.id] ?? {};
      for (const f of ["first", "addl", "days", "doc"] as const) {
        const v1 = r1[f] ?? "";
        const v2 = r2[f] ?? "";
        if (String(v1) === String(v2)) continue;
        out.byDest[id] = true;
        const lab = f === "days" ? "days" : f === "first" ? "first slab" : f === "doc" ? "documents" : "per step";
        const off = (f === "first" || f === "addl") && ignored(f);
        const pct = f !== "days" && isNum(v1) && isNum(v2) && v1 > 0 ? Math.round(((v2 - v1) / v1) * 100) : null;
        push({
          kind: "rate",
          label: `${x.name} · ${sv.name} ${lab}`,
          old: f === "days" ? String(v1) : money(v1),
          new: f === "days" ? String(v2) : money(v2),
          flag: off ? inertNote(grid) : pct !== null && Math.abs(pct) >= FLAG_PCT ? `${pct > 0 ? "+" : ""}${pct}% — please double-check` : undefined,
          ...(off ? { inert: true as const } : {}),
        });
      }
      // Grid prices, one line per kilogram that changed.
      const g1 = r1.grid ?? {};
      const g2 = r2.grid ?? {};
      const kgs = [...new Set([...Object.keys(g1), ...Object.keys(g2)])].sort((p, q) => Number(p) - Number(q));
      for (const k of kgs) {
        const v1 = g1[k];
        const v2 = g2[k];
        if ((v1 ?? "") === (v2 ?? "")) continue;
        out.byDest[id] = true;
        const off = ignored("grid");
        const pct = isNum(v1) && isNum(v2) && v1 > 0 ? Math.round(((v2 - v1) / v1) * 100) : null;
        push({
          kind: "rate",
          label: `${x.name} · ${sv.name} ${k} kg`,
          old: money(v1),
          new: money(v2),
          flag: off ? inertNote(grid) : pct !== null && Math.abs(pct) >= FLAG_PCT ? `${pct > 0 ? "+" : ""}${pct}% — please double-check` : undefined,
          ...(off ? { inert: true as const } : {}),
        });
      }
    }
  }
  for (const [id, y] of am) {
    if (bm.has(id)) continue;
    out.byDest[id] = true;
    push({ kind: "removed", label: "Removed", old: y.name });
  }
  if (!sameOrder(a.destinations, b.destinations)) push({ kind: "order", label: "Destination order changed (the site lists them in this order)" });
  for (const g of ["company", "settings", "content"] as const) {
    const ga = (a[g] ?? {}) as unknown as Record<string, unknown>;
    const gb = (b[g] ?? {}) as unknown as Record<string, unknown>;
    // Both sides' keys: a key only the live document has (an older shape, a restored version) is a change too.
    for (const k of new Set([...Object.keys(gb), ...Object.keys(ga)])) {
      if (String(ga[k] ?? "") === String(gb[k] ?? "")) continue;
      const ch = fieldChange(ga[k], gb[k]);
      const base = g === "content" ? `Website text · ${k}` : k;
      push({ kind: "field", label: ch.where ? `${base} (${ch.where})` : base, old: ch.old, new: ch.new });
    }
  }
  if (!!a.live !== !!b.live) push({ kind: "field", label: "Rates are live", old: String(!!a.live), new: String(!!b.live) });
  const ap = a.importProfiles.map((p) => p.signature).join("\n");
  const bp = b.importProfiles.map((p) => p.signature).join("\n");
  if (ap !== bp) push({ kind: "profile", label: "Import layout remembered", new: b.importProfiles.at(-1)?.name || "sheet" });
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
  const grid = st.pricingMode === "grid";
  if (grid && !(st.maxKg >= 1)) errs.push("Grid pricing needs a cargo threshold of at least 1 kg (Settings), which sets the last kilogram box.");
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
      if (grid) {
        const entries = Object.entries(r.grid ?? {}).filter(([, v]) => v != null);
        if (!entries.length) {
          if (r.doc != null) errs.push(`${nm} · ${sv.name}: has a document price but no kilogram prices — customers sending packages would see it as unavailable.`);
          continue;
        }
        for (const [k, v] of entries) if (!(isNum(v) && v > 0)) errs.push(`${nm} · ${sv.name} ${k} kg: price must be greater than 0 or left blank.`);
        priced++;
        continue;
      }
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

/**
 * Non-blocking oddities worth a second look before publishing. With `live`,
 * also says when edited prices are ones the pricing mode ignores — a grid-mode
 * publish of first-slab edits "succeeded" and changed nothing customers see.
 */
export function warnSite(s: SiteData, live?: SiteData): string[] {
  const w: string[] = [];
  if (live) {
    const d = diffSite(live, s);
    if (d.inert) {
      const grid = s.settings.pricingMode === "grid";
      const what = grid ? "first-slab or per-step prices" : "kilogram grid prices";
      const mode = grid ? "by kilogram grid" : "by first slab and step";
      w.push(
        d.inert === d.count
          ? `Every change here is to ${what}, which the site does not use (it prices ${mode}) — customers will see no difference.`
          : `${d.inert} change${d.inert === 1 ? " is" : "s are"} to ${what}, which the site does not use (it prices ${mode}); customers will not see ${d.inert === 1 ? "it" : "them"}.`,
      );
    }
  }
  const cur = s.settings.currency;
  const [ex, no] = s.services;
  const grid = s.settings.pricingMode === "grid";
  const firstOf = (q: { first?: number | null; grid?: Record<string, number> } | undefined): number | null => {
    if (!q) return null;
    if (grid) {
      const ks = Object.keys(q.grid ?? {}).sort((a, b) => Number(a) - Number(b));
      return ks.length ? (q.grid?.[ks[0]!] ?? null) : null;
    }
    return isNum(q.first) ? q.first : null;
  };
  for (const x of s.destinations) {
    if (!x.active) continue;
    const r = x.rates;
    for (const sv of s.services) {
      const q = r[sv.id];
      if (!grid && q && isNum(q.first) && isNum(q.addl) && q.addl > q.first)
        w.push(`${x.name} · ${sv.name}: the per-step price (${fmtMoney(q.addl, cur)}) is higher than the first slab (${fmtMoney(q.first, cur)}).`);
      if (grid && q?.grid) {
        // A heavier parcel should not be cheaper than a lighter one.
        const ks = Object.keys(q.grid).sort((a, b) => Number(a) - Number(b));
        for (let i = 1; i < ks.length; i++) {
          const prev = q.grid[ks[i - 1]!];
          const cur2 = q.grid[ks[i]!];
          if (isNum(prev) && isNum(cur2) && cur2 < prev) {
            w.push(`${x.name} · ${sv.name}: ${ks[i]} kg (${fmtMoney(cur2, cur)}) is cheaper than ${ks[i - 1]} kg (${fmtMoney(prev, cur)}).`);
            break;
          }
        }
        // Priced as the engine reads it (`gridPrice`): a blank box below a priced one is billed at the next
        // heavier priced kg, but above the last priced kg there is no such kg — those parcels get no price at
        // all, and the site sends the customer to WhatsApp. Say which is which.
        const priced = gridWeights(s.settings).filter((k) => {
          const v = q.grid?.[String(k)];
          return isNum(v) && v > 0;
        });
        const missing = gridWeights(s.settings).filter((k) => !priced.includes(k));
        if (missing.length && priced.length) {
          const top = priced[priced.length - 1]!;
          const gaps = missing.filter((k) => k < top);
          const above = missing.filter((k) => k > top);
          if (gaps.length)
            w.push(`${x.name} · ${sv.name}: no price for ${gaps.length} weight${gaps.length === 1 ? "" : "s"} (${gaps.slice(0, 6).join(", ")}${gaps.length > 6 ? "…" : ""} kg) — those parcels are charged at the next heavier priced kg.`);
          if (above.length)
            w.push(
              `${x.name} · ${sv.name}: no price above ${top} kg — parcels of ${above.length === 1 ? `${above[0]} kg` : `${above[0]}–${above[above.length - 1]} kg`} get no price on the site and are sent to WhatsApp.`,
            );
        }
      }
    }
    if (ex && no) {
      const a = firstOf(r[ex.id]);
      const b = firstOf(r[no.id]);
      if (a != null && b != null && a < b) w.push(`${x.name}: ${ex.name} is cheaper than ${no.name} (${fmtMoney(a, cur)} vs ${fmtMoney(b, cur)}).`);
    }
    for (const sv of s.services) {
      const q = r[sv.id];
      if (q && firstOf(q) != null && !q.days) w.push(`${x.name} · ${sv.name}: no transit days, so no delivery date can be shown.`);
    }
    for (const sv of s.services) {
      const q = r[sv.id];
      const f = firstOf(q);
      if (q && isNum(q.doc) && f != null && q.doc > f)
        w.push(`${x.name} · ${sv.name}: the document price (${fmtMoney(q.doc, cur)}) is higher than the lightest package price (${fmtMoney(f, cur)}).`);
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
  if (d.lines.some((l) => l.kind === "service")) bits.push("services");
  if (d.lines.some((l) => l.kind === "order")) bits.push("order");
  if (fields) bits.push("settings or text");
  if (d.lines.some((l) => l.kind === "profile")) bits.push("import layout");
  return `${d.count} change${d.count === 1 ? "" : "s"}${bits.length ? `: ${bits.join(", ")}` : ""}`;
}

