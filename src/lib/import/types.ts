/** Shapes shared by the sheet importer, the admin preview and the `imports` table. */
import type { ImportProfile } from "@/lib/site/types";

export interface ImportOptions {
  /** Create destinations that are in the sheet but not in the draft. */
  addNew: boolean;
  /** Hide active destinations that the sheet does not mention. */
  hideMissing: boolean;
  /** Sheet prices are carrier costs: apply `margin` % and round to `mround`. */
  cost: boolean;
  margin: number;
  mround: number;
}

export interface ImportRowRate {
  first: number | null;
  addl: number | null;
  days: string;
  doc: number | null;
  /** Raw sheet values before margin, for the preview's "cost → price" column. */
  costFirst: number | null;
  costAddl: number | null;
  costDoc: number | null;
}

export interface ImportRow {
  name: string;
  rates: Record<string, ImportRowRate>;
  /** Id of the draft destination this row updates, or null when it would be new. */
  existing: string | null;
}

export interface ImportResult {
  rows: ImportRow[];
  /** Rows that will be skipped, with the reason. */
  errors: string[];
  /** Rows imported but worth a look. */
  warnings: string[];
  opts: ImportOptions;
  /** Destination ids to hide (only when `opts.hideMissing`). */
  hide?: string[];
  /** Layout to remember once applied; null for pasted rows. */
  profile: ImportProfile | null;
  /** 0-based index of the header row in the sheet. */
  headerRow: number;
  /** Field key → column index used for this parse. */
  map: Record<string, number>;
}
