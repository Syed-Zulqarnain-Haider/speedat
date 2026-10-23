/**
 * Turning a rate sheet's rows into destinations and prices. Pure and
 * isomorphic: the admin runs it in the browser for the live preview, the
 * email intake runs it on the server with a remembered layout.
 *
 * Vocabulary: a "layout" is the set of header cells of a sheet; its
 * "signature" identifies it, and a saved ImportProfile maps its columns to
 * our fields so the same layout is understood automatically next time.
 */
import { gridPrice, gridWeights, isGrid, isNum, roundTo, slabToGrid, toNumLoose } from "@/lib/pricing/engine";
import { parseDays } from "@/lib/pricing/dates";
import { nameKey, slug } from "@/lib/pricing/format";
import type { Destination, Rate, Service } from "@/lib/pricing/types";
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

/**
 * Field keys the mapper offers: "name" plus `${service}.${first|addl|days|doc}`,
 * and `${service}.kg.${n}` for each weight in `kgs`. The weight fields are
 * listed so a wrongly detected grid (one service's columns read as another's)
 * can be corrected by hand instead of only by renaming the sheet's headers.
 */
export function mapFields(services: Service[], kgs: number[] = []): { key: string; label: string; req: boolean; kg?: number }[] {
  const out: { key: string; label: string; req: boolean; kg?: number }[] = [{ key: "name", label: "Destination name", req: true }];
  for (const sv of services)
    out.push(
      { key: `${sv.id}.first`, label: `${sv.name}: first slab`, req: false },
      { key: `${sv.id}.addl`, label: `${sv.name}: each additional step`, req: false },
      { key: `${sv.id}.days`, label: `${sv.name}: days`, req: false },
      { key: `${sv.id}.doc`, label: `${sv.name}: documents (flat)`, req: false },
    );
  const sorted = [...new Set(kgs)].sort((a, b) => a - b);
  for (const sv of services) for (const kg of sorted) out.push({ key: `${sv.id}.kg.${kg}`, label: `${sv.name}: ${kg} kg`, req: false, kg });
  return out;
}

/** Weights that appear as `<service>.kg.<n>` keys in a mapping (mapped or not). */
export function mappedKgs(map: Record<string, number>): number[] {
  const out = new Set<number>();
  for (const k of Object.keys(map)) {
    const m = k.match(/\.kg\.(\d+)$/);
    if (m) out.add(Number(m[1]));
  }
  return [...out].sort((a, b) => a - b);
}

const SYN: Record<string, string[]> = {
  express: ["express", "priority", "fast", "urgent", "premium", "exp"],
  normal: ["normal", "economy", "eco", "standard", "saver", "regular", "budget", "std"],
};

/**
 * "1 kg", "1KG", "Express 3 Kgs", "3" → whole kilograms; anything else → null.
 * Fractions ("0.5 KG", "2.5 kg") are not grid columns — they belong to slab layouts.
 */
export function weightHeader(h: string): number | null {
  const k = nameKey(h);
  const m = k.match(/^(\d{1,3})$/) ?? k.match(/(?:^|[^\d\s]\s+)(\d{1,3})\s*(?:kg|kgs|kilo|kilos)$/);
  if (!m) return null;
  const kg = Number(m[1]);
  return Number.isInteger(kg) && kg > 0 && kg <= 500 ? kg : null;
}

/**
 * Weight columns per service, e.g. { express: { "1": 3, "2": 4 }, normal: { "1": 8 } } (kg → column).
 * A service is read from the header itself or from the group row above it (forward-filled, the way
 * merged cells export); without any service words, successive runs of weights are assigned in service order.
 *
 * The row above the header is only a group row when a cell above a weight column names exactly one
 * service. Carriers often put a title there ("Express and Economy rates 2026"): forward-filling that
 * across the sheet once gave every weight column to Express, so Express got Economy's prices.
 */
export function weightColumns(headers: string[], services: Service[], groupRow?: string[]): Record<string, Record<string, number>> {
  const out: Record<string, Record<string, number>> = {};
  /** The one service a text names; null when it names none or several (a title, not a group label). */
  const serviceOf = (text: string): string | null => {
    const t = ` ${nameKey(text)} `;
    const hits = services.filter((sv) => [nameKey(sv.name), sv.id, ...(SYN[sv.id] ?? [])].filter(Boolean).some((k) => t.includes(` ${k}`)));
    return hits.length === 1 ? hits[0]!.id : null;
  };
  const isGroupRow = !!groupRow && headers.some((h, i) => weightHeader(h) != null && !!groupRow[i]?.trim() && serviceOf(groupRow[i]!) != null);
  let group = "";
  const groups = headers.map((_, i) => {
    const g = isGroupRow ? groupRow![i]?.trim() : "";
    // A filled cell that is not a service label ("Days", "Notes") ends the previous group.
    if (g) group = serviceOf(g) ? g : "";
    return group;
  });
  let run = -1;
  let lastKg = 0;
  headers.forEach((h, i) => {
    const kg = weightHeader(h);
    if (kg == null) return;
    let svc = serviceOf(h) ?? serviceOf(groups[i] ?? "");
    if (!svc) {
      // A new run of weights (first one, or the weight went down) belongs to the next service in order.
      if (kg <= lastKg || run < 0) run++;
      svc = services[Math.min(run, services.length - 1)]?.id ?? null;
    }
    lastKg = kg;
    if (!svc) return;
    (out[svc] ??= {})[String(kg)] = i;
  });
  // One or two weight-looking columns are a slab layout ("Express 1 kg" = first slab), not a grid.
  for (const svc of Object.keys(out)) if (Object.keys(out[svc]!).length < 3) delete out[svc];
  return out;
}

/** Best-guess column mapping from header text. Returns -1 for fields not found. Weight columns map as `<service>.kg.<n>`. */
export function autoMap(headers: string[], services: Service[], groupRow?: string[]): Record<string, number> {
  const n = headers.map((h) => nameKey(h));
  const map: Record<string, number> = {};
  const find = (re: RegExp, from?: number[]): number => {
    for (let i = 0; i < n.length; i++) if ((!from || from.includes(i)) && re.test(n[i]!)) return i;
    return -1;
  };
  // A place name beats a zone code: "Zone | Country" sheets list the carrier's zone number first.
  map.name = find(/\b(dest|destination|destinations|country|countries|city|location)\b/);
  if (map.name < 0) map.name = find(/\b(zone|region|to)\b/);
  if (map.name < 0) map.name = 0;
  const wcols = weightColumns(headers, services, groupRow);
  const weightIdx = new Set(Object.values(wcols).flatMap((m) => Object.values(m)));
  for (const [svc, m] of Object.entries(wcols)) for (const [kg, i] of Object.entries(m)) map[`${svc}.kg.${kg}`] = i;
  for (const sv of services) {
    const keys = [nameKey(sv.name), sv.id, ...(SYN[sv.id] ?? [])].filter(Boolean);
    let cand: number[] = [];
    n.forEach((h, i) => {
      if (i !== map.name && !weightIdx.has(i) && keys.some((k) => ` ${h} `.includes(` ${k}`))) cand.push(i);
    });
    if (!cand.length && services.length === 1) cand = n.map((_, i) => i).filter((i) => i !== map.name && !weightIdx.has(i));
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

export interface Layout {
  headerRow: number;
  map: Record<string, number>;
  /** The remembered layout that matched, if any. */
  profile: ImportProfile | undefined;
}

/**
 * Header row and column mapping to start from for a sheet. With `headerRow`
 * given (the admin typed one, or switched sheets) that row is used; otherwise
 * the detected header row is tried first, then the header row each saved
 * layout remembers — a sheet whose title rows fool `detectHeaderRow` is still
 * recognised once an admin corrected it, which is what lets the next emailed
 * copy apply unattended. Without a match the columns are guessed by `autoMap`.
 */
export function suggestLayout(rows: string[][], profiles: ImportProfile[], services: Service[], headerRow?: number): Layout {
  const at = (h: number): Layout => {
    const headers = headerNames(rows[h] ?? []);
    const profile = findProfile(profiles, headerSignature(headers));
    return { headerRow: h, map: profile ? { ...profile.map } : autoMap(headers, services, h > 0 ? rows[h - 1] : undefined), profile };
  };
  if (headerRow != null) return at(headerRow);
  const detected = at(detectHeaderRow(rows));
  if (detected.profile) return detected;
  for (const p of profiles) {
    if (p.headerRow == null || !rows[p.headerRow]) continue;
    if (headerSignature(headerNames(rows[p.headerRow]!)) === p.signature) return { headerRow: p.headerRow, map: { ...p.map }, profile: p };
  }
  return detected;
}

export interface BuildArgs {
  /** All rows of the chosen sheet. */
  rows: string[][];
  /** 0-based index of the header row; data starts after it. */
  headerRow: number;
  map: Record<string, number>;
  opts: ImportOptions;
  /** The document the sheet is read against; its pricing mode decides which prices the sheet must supply. */
  draft: Pick<SiteData, "services" | "destinations" | "settings">;
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
  // The engine prices a grid document from its kilogram boxes only, and a slab document from first +
  // step only. Prices of the other kind would be saved, diffed and even auto-published, yet change no
  // quote — so a slab sheet is converted to boxes, and a grid sheet on a slab document is refused.
  const gridDoc = isGrid(draft.settings);
  if (!gridDoc && Object.entries(map).some(([k, v]) => k.includes(".kg.") && v >= 0)) {
    out.errors.push(
      "This sheet has per-kilogram price columns, but the site prices by first slab + additional step, so they would change no price. Switch the pricing mode to per-kilogram boxes in Settings first, or set the kilogram columns to “not in file”.",
    );
    return out;
  }
  let converted = 0;
  let zeros = 0;
  /** A 0 in a carrier sheet means "not offered", never "free": it is read as a blank cell. */
  const price = (v: number | null): number | null => {
    if (v !== 0) return v;
    zeros++;
    return null;
  };
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
      let f = fi >= 0 ? price(toNumLoose(r[fi])) : null;
      let a = ai >= 0 ? price(toNumLoose(r[ai])) : null;
      const dd = di >= 0 ? parseDays(r[di]) : "";
      let dc = ci >= 0 ? price(toNumLoose(r[ci])) : null;
      if ((f !== null && Number.isNaN(f)) || (a !== null && Number.isNaN(a)) || (dc !== null && Number.isNaN(dc))) {
        out.errors.push(`${line} (${name}): ${sv.name} price is not a number`);
        return;
      }
      // Weight-grid columns for this service.
      const prefix = `${sv.id}.kg.`;
      const grid: Record<string, number> = {};
      const costGrid: Record<string, number> = {};
      let badKg: string | null = null;
      for (const [key, ci2] of Object.entries(map)) {
        if (!key.startsWith(prefix) || ci2 < 0) continue;
        const kg = key.slice(prefix.length);
        const v = price(toNumLoose(r[ci2]));
        if (v === null) continue;
        if (Number.isNaN(v)) {
          badKg = kg;
          break;
        }
        costGrid[kg] = v;
        grid[kg] = v;
      }
      if (badKg) {
        out.errors.push(`${line} (${name}): ${sv.name} ${badKg} kg price is not a number`);
        return;
      }
      const hasGrid = Object.keys(grid).length > 0;
      if (f === null && a === null && dc === null && !hasGrid) continue;
      if (!hasGrid && (f === null) !== (a === null)) out.warnings.push(`${line} (${name}): ${sv.name} has only one of the two package prices`);
      const costF = f;
      const costA = a;
      const costD = dc;
      if (opts.cost) {
        const m = 1 + (Number(opts.margin) || 0) / 100;
        const rr = Number(opts.mround) || 1;
        if (f !== null) f = roundTo(f * m, rr);
        if (a !== null) a = roundTo(a * m, rr);
        if (dc !== null) dc = roundTo(dc * m, rr);
        for (const k of Object.keys(grid)) grid[k] = roundTo(grid[k]! * m, rr);
      }
      const rate: ImportRowRate = { first: f, addl: a, days: dd, doc: dc, costFirst: costF, costAddl: costA, costDoc: costD };
      if (hasGrid) {
        rate.grid = grid;
        rate.costGrid = costGrid;
      } else if (gridDoc && f !== null && a !== null) {
        // Same derivation the document used when it was switched to grid mode (`slabToGrid`).
        rate.grid = slabToGrid(draft.settings, { first: f, addl: a });
        rate.costGrid = slabToGrid(draft.settings, { first: costF, addl: costA });
        converted++;
      }
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
  if (converted) {
    const kgs = gridWeights(draft.settings);
    out.warnings.unshift(`The site prices per kilogram: first-slab and step prices were turned into 1–${kgs[kgs.length - 1]} kg prices (${converted} service price${converted === 1 ? "" : "s"}).`);
  }
  if (zeros) out.warnings.push(`${zeros} price cell${zeros === 1 ? "" : "s"} read 0 and ${zeros === 1 ? "was" : "were"} taken as “not offered” (left blank), not as a free price.`);
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
    headerRow,
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
export function buildPasteImport(text: string, draft: Pick<SiteData, "services" | "destinations" | "settings">): ImportResult {
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

/**
 * Apply an import to a document, returning a new document. Existing values are kept where the sheet is
 * blank — except the kilogram boxes: a service the sheet prices per kilogram gets the sheet's grid as a
 * whole, because keeping stale heavier boxes made a 3 kg parcel dearer than a 4 kg one.
 */
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
      const grid = nv.grid && Object.keys(nv.grid).length ? { ...nv.grid } : old.grid;
      if (grid && Object.keys(grid).length) cleaned.grid = grid;
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

/**
 * The import with each row re-matched to `data`'s destinations by name. A
 * stored result's `existing` ids were resolved against the draft of the day;
 * comparing it with another document (the live version, a published copy)
 * must not trust them.
 */
export function rebind(im: ImportResult, data: Pick<SiteData, "destinations">): ImportResult {
  return { ...im, rows: im.rows.map((r) => ({ ...r, existing: data.destinations.find((x) => matchKey(x.name) === matchKey(r.name))?.id ?? null })) };
}

export interface Move {
  /** Largest percent change; `Infinity` for a change no percentage describes (see `largestMove`). */
  pct: number;
  label: string;
}

/** "United Kingdom express 3 kg moved 12.5%", or the label alone for an unbounded change. */
export const describeMove = (m: Move): string => (Number.isFinite(m.pct) ? `${m.label} moved ${m.pct.toFixed(1)}%` : m.label);

/** The prices the engine reads for one service in this mode, as label → price (positive, or null). */
function pricedPoints(settings: SiteData["settings"], r: Rate | undefined): Map<string, number | null> {
  const pos = (v: unknown): number | null => (isNum(v) && v > 0 ? v : null);
  const out = new Map<string, number | null>();
  if (isGrid(settings)) for (const kg of gridWeights(settings)) out.set(`${kg} kg`, gridPrice(r?.grid, kg, settings.maxKg)?.price ?? null);
  else {
    out.set("first", pos(r?.first));
    out.set("addl", pos(r?.addl));
  }
  out.set("doc", pos(r?.doc));
  return out;
}

/**
 * The largest move an import makes to what `base` actually quotes, for the
 * auto-publish tolerance. Measured on what the engine prices in `base`'s mode
 * (kilogram boxes, read the way a quote reads them, or first + step), after
 * applying the import — never on fields the engine ignores.
 *
 * Changes no percentage can describe count as unbounded (`pct: Infinity`), so
 * they always exceed the tolerance and wait for a person: a new destination, a
 * service or weight priced for the first time, a price the sheet removes, and
 * a destination the import hides. There is no "allow additions" setting on
 * purpose — a new country going live unseen is exactly what review is for.
 * Returns null when nothing the engine prices changes.
 */
export function largestMove(base: SiteData, im: ImportResult): Move | null {
  const bound = rebind(im, base);
  const next = applyImport(base, bound);
  let worst: Move | null = null;
  const bump = (pct: number, label: string) => {
    if (pct > 0 && (!worst || pct > worst.pct)) worst = { pct, label };
  };
  for (const id of bound.hide ?? []) {
    const d = base.destinations.find((x) => x.id === id);
    if (d?.active) bump(Infinity, `${d.name} would be hidden`);
  }
  for (const r of bound.rows) {
    if (!r.existing) {
      if (bound.opts.addNew) bump(Infinity, `${r.name} is a new destination`);
      continue;
    }
    const a = base.destinations.find((x) => x.id === r.existing);
    const b = next.destinations.find((x) => x.id === r.existing);
    if (!a || !b) continue;
    for (const svc of Object.keys(r.rates)) {
      const before = pricedPoints(base.settings, a.rates[svc]);
      const after = pricedPoints(base.settings, b.rates[svc]);
      for (const [pt, p0] of before) {
        const p1 = after.get(pt) ?? null;
        const label = `${a.name} ${svc} ${pt}`;
        if (p0 == null && p1 == null) continue;
        if (p0 == null) bump(Infinity, `${label} is priced for the first time`);
        else if (p1 == null) bump(Infinity, `${label} would no longer be priced`);
        else bump(Math.abs(((p1 - p0) / p0) * 100), label);
      }
    }
  }
  return worst;
}

function sameRate(a: Rate | undefined, b: Rate | undefined): boolean {
  const n = (v: unknown) => (isNum(v) ? v : null);
  if (n(a?.first) !== n(b?.first) || n(a?.addl) !== n(b?.addl) || n(a?.doc) !== n(b?.doc) || (a?.days ?? "") !== (b?.days ?? "")) return false;
  const ga = a?.grid ?? {};
  const gb = b?.grid ?? {};
  const kgs = new Set([...Object.keys(ga), ...Object.keys(gb)]);
  for (const k of kgs) if (n(ga[k]) !== n(gb[k])) return false;
  return true;
}

/**
 * Does `data` carry this import's prices? True when applying it again would
 * change no rate of the destinations it covers, every destination it adds
 * exists, and every one it hides is hidden. Used when an admin publishes: a
 * sheet whose prices they edited away or deleted before publishing did not
 * go live, and must not be reported as published.
 */
export function importReflected(im: ImportResult, data: SiteData): boolean {
  const bound = rebind(im, data);
  for (const id of bound.hide ?? []) if (data.destinations.find((x) => x.id === id)?.active) return false;
  if (bound.opts.addNew && bound.rows.some((r) => !r.existing)) return false;
  const again = applyImport(data, { ...bound, hide: undefined, profile: null });
  for (const r of bound.rows) {
    if (!r.existing) continue;
    const a = data.destinations.find((x) => x.id === r.existing);
    const b = again.destinations.find((x) => x.id === r.existing);
    for (const svc of Object.keys(r.rates)) if (!sameRate(a?.rates[svc], b?.rates[svc])) return false;
  }
  return true;
}
