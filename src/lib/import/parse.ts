/**
 * Turning a rate sheet's rows into destinations and prices. Pure and
 * isomorphic: the admin runs it in the browser for the live preview, the
 * email intake runs it on the server with a remembered layout.
 *
 * Vocabulary: a "layout" is the set of header cells of a sheet; its
 * "signature" identifies it, and a saved ImportProfile maps its columns to
 * our fields so the same layout is understood automatically next time.
 */
import { isNum, roundTo, toNumLoose } from "@/lib/pricing/engine";
import { parseDays } from "@/lib/pricing/dates";
import { nameKey, slug } from "@/lib/pricing/format";
import type { Destination, Service } from "@/lib/pricing/types";
import type { ImportProfile, SiteData } from "@/lib/site/types";
import type { ImportOptions, ImportResult, ImportRow, ImportRowRate } from "./types";

/** Parse CSV / TSV / semicolon text, quotes honoured, blank rows dropped. */
export function parseDelimited(text: string): string[][] {
  const delim = text.includes("\t") ? "\t" : text.split(";").length > text.split(",").length ? ";" : ",";
  const rows: string[][] = [];
  let row: string[] = [];
  let cell = "";
  let q = false;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i]!;
    if (q) {
      if (ch === '"') {
        if (text[i + 1] === '"') {
          cell += '"';
          i++;
        } else q = false;
      } else cell += ch;
    } else if (ch === '"') q = true;
    else if (ch === delim) {
      row.push(cell);
      cell = "";
    } else if (ch === "\n" || ch === "\r") {
      if (ch === "\r" && text[i + 1] === "\n") i++;
      row.push(cell);
      rows.push(row);
      row = [];
      cell = "";
    } else cell += ch;
  }
  if (cell !== "" || row.length) {
    row.push(cell);
    rows.push(row);
  }
  return rows.filter((r) => r.some((c) => c.trim() !== "")).map((r) => r.map((c) => c.trim()));
}

/** First row (of the first 15) with at least three filled cells, two of them non-numeric. */
export function detectHeaderRow(rows: string[][]): number {
  for (let i = 0; i < Math.min(rows.length, 15); i++) {
    const r = rows[i]!;
    const texts = r.filter((c) => c !== "" && Number.isNaN(Number(String(c).replace(/,/g, "")))).length;
    if (texts >= 2 && r.filter((c) => c !== "").length >= 3) return i;
  }
  return 0;
}

export const headerNames = (row: string[]): string[] => row.map((h, i) => h || `Column ${i + 1}`);

export function headerSignature(headers: string[]): string {
  return headers.map((h) => nameKey(h)).join("|");
}

/** Field keys the mapper offers: "name" plus `${service}.${first|addl|days|doc}`. */
export function mapFields(services: Service[]): { key: string; label: string; req: boolean }[] {
  const out = [{ key: "name", label: "Destination name", req: true }];
  for (const sv of services)
    out.push(
      { key: `${sv.id}.first`, label: `${sv.name}: first slab`, req: false },
      { key: `${sv.id}.addl`, label: `${sv.name}: each additional step`, req: false },
      { key: `${sv.id}.days`, label: `${sv.name}: days`, req: false },
      { key: `${sv.id}.doc`, label: `${sv.name}: documents (flat)`, req: false },
    );
  return out;
}

const SYN: Record<string, string[]> = {
  express: ["express", "priority", "fast", "urgent", "premium", "exp"],
  normal: ["normal", "economy", "eco", "standard", "saver", "regular", "budget", "std"],
};

/** Best-guess column mapping from header text. Returns -1 for fields not found. */
export function autoMap(headers: string[], services: Service[]): Record<string, number> {
  const n = headers.map((h) => nameKey(h));
  const map: Record<string, number> = {};
  const find = (re: RegExp, from?: number[]): number => {
    for (let i = 0; i < n.length; i++) if ((!from || from.includes(i)) && re.test(n[i]!)) return i;
    return -1;
  };
  map.name = find(/\b(dest|destination|country|countries|city|zone|region|location|to)\b/);
  if (map.name < 0) map.name = 0;
  for (const sv of services) {
    const keys = [nameKey(sv.name), sv.id, ...(SYN[sv.id] ?? [])].filter(Boolean);
    let cand: number[] = [];
    n.forEach((h, i) => {
      if (i !== map.name && keys.some((k) => ` ${h} `.includes(` ${k}`))) cand.push(i);
    });
    if (!cand.length && services.length === 1) cand = n.map((_, i) => i).filter((i) => i !== map.name);
    const dc = find(/(doc|document|envelope|letter|dox)/, cand);
    const rest = cand.filter((i) => i !== dc);
    let f = find(/(first|base|min|initial|start|upto|up to|0 5|1st|slab)/, rest);
    let a = find(/(add|each|per|extra|next|step|plus|every)/, rest);
    const dd = find(/(day|transit|tat|time|eta|delivery)/, rest);
    if (f < 0 && rest.length) f = rest.filter((i) => i !== a && i !== dd)[0] ?? -1;
    if (a < 0 && rest.length) a = rest.filter((i) => i !== f && i !== dd)[0] ?? -1;
    map[`${sv.id}.first`] = f;
    map[`${sv.id}.addl`] = a;
    map[`${sv.id}.days`] = dd;
    map[`${sv.id}.doc`] = dc;
  }
  return map;
}

/** Spellings carriers use for the same place. Keys and values are `nameKey` form. */
const ALIASES: Record<string, string> = {
  "uk": "united kingdom",
  "u k": "united kingdom",
  "great britain": "united kingdom",
  "britain": "united kingdom",
  "england": "united kingdom",
  "gb": "united kingdom",
  "usa": "united states",
  "us": "united states",
  "u s": "united states",
  "u s a": "united states",
  "united states of america": "united states",
  "america": "united states",
  "uae": "united arab emirates",
  "u a e": "united arab emirates",
  "emirates": "united arab emirates",
  "dubai": "united arab emirates",
  "ksa": "saudi arabia",
  "saudi": "saudi arabia",
  "saudia": "saudi arabia",
  "turkey": "turkiye",
  "holland": "netherlands",
  "uk london": "united kingdom",
};

/** Matching key for a destination name: `nameKey` plus alias folding. */
export function matchKey(name: string | null | undefined): string {
  const k = nameKey(name);
  return ALIASES[k] ?? k;
}

export function findProfile(profiles: ImportProfile[], signature: string): ImportProfile | undefined {
  return profiles.find((p) => p.signature === signature);
}

export interface BuildArgs {
  /** All rows of the chosen sheet. */
  rows: string[][];
  /** 0-based index of the header row; data starts after it. */
  headerRow: number;
  map: Record<string, number>;
  opts: ImportOptions;
  draft: Pick<SiteData, "services" | "destinations">;
  /** File name, kept in the profile for the history. */
  fileName: string | null;
}

/** Interpret the sheet. Never throws: problems come back as errors/warnings per row. */
export function buildImport({ rows, headerRow, map, opts, draft, fileName }: BuildArgs): ImportResult {
  const out: ImportResult = { rows: [], errors: [], warnings: [], opts, profile: null, headerRow, map };
  const headers = headerNames(rows[headerRow] ?? []);
  if (map.name == null || map.name < 0) {
    out.errors.push("Choose which column holds the destination name.");
    return out;
  }
  const seen = new Map<string, number>();
  const data = rows.slice(headerRow + 1);
  data.forEach((r, i) => {
    const name = String(r[map.name!] ?? "").trim();
    const line = `Row ${headerRow + i + 2}`;
    if (!name || /^(total|notes?|remarks?)$/i.test(name)) return;
    const rec: ImportRow = { name, rates: {}, existing: null };
    let any = false;
    for (const sv of draft.services) {
      const col = (k: string) => map[`${sv.id}.${k}`] ?? -1;
      const fi = col("first");
      const ai = col("addl");
      const di = col("days");
      const ci = col("doc");
      let f = fi >= 0 ? toNumLoose(r[fi]) : null;
      let a = ai >= 0 ? toNumLoose(r[ai]) : null;
      const dd = di >= 0 ? parseDays(r[di]) : "";
      let dc = ci >= 0 ? toNumLoose(r[ci]) : null;
      if ((f !== null && Number.isNaN(f)) || (a !== null && Number.isNaN(a)) || (dc !== null && Number.isNaN(dc))) {
        out.errors.push(`${line} (${name}): ${sv.name} price is not a number`);
        return;
      }
      if (f === null && a === null && dc === null) continue;
      if ((f === null) !== (a === null)) out.warnings.push(`${line} (${name}): ${sv.name} has only one of the two package prices`);
      const costF = f;
      const costA = a;
      const costD = dc;
      if (opts.cost) {
        const m = 1 + (Number(opts.margin) || 0) / 100;
        const rr = Number(opts.mround) || 1;
        if (f !== null) f = roundTo(f * m, rr);
        if (a !== null) a = roundTo(a * m, rr);
        if (dc !== null) dc = roundTo(dc * m, rr);
      }
      const rate: ImportRowRate = { first: f, addl: a, days: dd, doc: dc, costFirst: costF, costAddl: costA, costDoc: costD };
      rec.rates[sv.id] = rate;
      any = true;
    }
    if (!any) {
      out.warnings.push(`${line} (${name}): no prices found, skipped`);
      return;
    }
    const k = matchKey(name);
    const existing = draft.destinations.find((x) => matchKey(x.name) === k);
    rec.existing = existing ? existing.id : null;
    const dup = seen.get(k);
    if (dup != null) {
      out.warnings.push(`${line} (${name}): appears more than once, the last row wins`);
      out.rows[dup] = rec;
    } else {
      seen.set(k, out.rows.length);
      out.rows.push(rec);
    }
  });
  if (opts.hideMissing) {
    const inFile = new Set(out.rows.map((r) => r.existing).filter((x): x is string => !!x));
    out.hide = draft.destinations.filter((x) => x.active && !inFile.has(x.id)).map((x) => x.id);
  }
  out.profile = {
    signature: headerSignature(headers),
    name: fileName ?? "sheet",
    map,
    savedAt: new Date().toISOString(),
    cost: opts.cost,
    margin: opts.margin,
    mround: opts.mround,
  };
  return out;
}

/** Column layout used for pasted rows: name, then first/addl/days/doc per service. */
export function pasteMap(services: Service[]): Record<string, number> {
  const map: Record<string, number> = { name: 0 };
  services.forEach((sv, k) => {
    map[`${sv.id}.first`] = 1 + k * 4;
    map[`${sv.id}.addl`] = 2 + k * 4;
    map[`${sv.id}.days`] = 3 + k * 4;
    map[`${sv.id}.doc`] = 4 + k * 4;
  });
  return map;
}

/** Pasted text → import, skipping a header row if the second cell is not numeric. */
export function buildPasteImport(text: string, draft: Pick<SiteData, "services" | "destinations">): ImportResult {
  let rows = parseDelimited(text);
  const first = rows[0];
  if (first && first.length > 1 && first[1] !== "" && Number.isNaN(Number(String(first[1]).replace(/,/g, "")))) rows = rows.slice(1);
  const res = buildImport({
    rows: [[], ...rows],
    headerRow: 0,
    map: pasteMap(draft.services),
    opts: { addNew: true, hideMissing: false, cost: false, margin: 0, mround: 1 },
    draft,
    fileName: null,
  });
  res.profile = null;
  return res;
}

function uniqueId(base: string, taken: Destination[]): string {
  let id = base;
  let n = 2;
  while (taken.some((x) => x.id === id)) id = `${base}-${n++}`;
  return id;
}

/** Apply an import to a document, returning a new document. Existing values are kept where the sheet is blank. */
export function applyImport(draft: SiteData, im: ImportResult): SiteData {
  const d = structuredClone(draft);
  for (const r of im.rows) {
    let dest = r.existing ? d.destinations.find((x) => x.id === r.existing) : undefined;
    if (!dest) {
      if (!im.opts.addNew) continue;
      dest = { id: uniqueId(slug(r.name), d.destinations), name: r.name, active: true, rates: {} };
      d.destinations.push(dest);
    }
    for (const [svc, nv] of Object.entries(r.rates)) {
      const old = dest.rates[svc] ?? {};
      const merged = {
        first: nv.first ?? old.first ?? null,
        addl: nv.addl ?? old.addl ?? null,
        days: nv.days || old.days || "",
        doc: nv.doc ?? old.doc ?? null,
      };
      const cleaned: Destination["rates"][string] = {};
      if (isNum(merged.first)) cleaned.first = merged.first;
      if (isNum(merged.addl)) cleaned.addl = merged.addl;
      if (merged.days) cleaned.days = merged.days;
      if (isNum(merged.doc)) cleaned.doc = merged.doc;
      if (Object.keys(cleaned).length) dest.rates[svc] = cleaned;
      else delete dest.rates[svc];
    }
  }
  for (const id of im.hide ?? []) {
    const x = d.destinations.find((y) => y.id === id);
    if (x) x.active = false;
  }
  if (im.profile) {
    d.importProfiles = d.importProfiles.filter((p) => p.signature !== im.profile!.signature);
    d.importProfiles.push(im.profile);
    while (d.importProfiles.length > 12) d.importProfiles.shift();
  }
  return d;
}

/** Percent moves in an import versus the current draft, for the auto-publish tolerance check. */
export function largestMove(draft: SiteData, im: ImportResult): { pct: number; label: string } | null {
  let worst: { pct: number; label: string } | null = null;
  for (const r of im.rows) {
    if (!r.existing) continue;
    const dest = draft.destinations.find((x) => x.id === r.existing);
    if (!dest) continue;
    for (const [svc, nv] of Object.entries(r.rates)) {
      const old = dest.rates[svc];
      if (!old) continue;
      for (const f of ["first", "addl", "doc"] as const) {
        const a = old[f];
        const b = nv[f];
        if (!isNum(a) || !isNum(b) || a <= 0) continue;
        const pct = Math.abs(((b - a) / a) * 100);
        if (!worst || pct > worst.pct) worst = { pct, label: `${dest.name} ${svc} ${f}` };
      }
    }
  }
  return worst;
}
