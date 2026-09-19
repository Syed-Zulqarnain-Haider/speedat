/**
 * Read an uploaded or emailed workbook into plain string rows per sheet.
 * Server-only (SheetJS is not shipped to the browser). Sizes are capped so a
 * stray 200 MB export cannot take the process down.
 */
import "server-only";
import * as XLSX from "xlsx";
import { parseDelimited } from "./parse";

export const MAX_FILE_BYTES = 8 * 1024 * 1024;
export const MAX_ROWS = 5000;
export const MAX_COLS = 80;

export interface Workbook {
  names: string[];
  sheets: Record<string, string[][]>;
}

export class WorkbookError extends Error {}

export function fileKind(name: string): "xlsx" | "xls" | "csv" | null {
  const ext = (name.split(".").pop() ?? "").toLowerCase();
  if (ext === "xlsx" || ext === "xlsm") return "xlsx";
  if (ext === "xls") return "xls";
  if (ext === "csv" || ext === "tsv" || ext === "txt") return "csv";
  return null;
}

export function readWorkbook(name: string, buf: Uint8Array): Workbook {
  if (buf.byteLength > MAX_FILE_BYTES) throw new WorkbookError(`File is larger than ${MAX_FILE_BYTES / 1024 / 1024} MB`);
  const kind = fileKind(name);
  if (!kind) throw new WorkbookError("Only .xlsx, .xls and .csv files are supported");
  if (kind === "csv") {
    const text = new TextDecoder("utf-8").decode(buf);
    return { names: ["Sheet1"], sheets: { Sheet1: cap(parseDelimited(text)) } };
  }
  // SheetJS turns arbitrary bytes into a one-cell sheet rather than failing, so check the container first.
  const isZip = buf.length > 4 && buf[0] === 0x50 && buf[1] === 0x4b && buf[2] === 0x03 && buf[3] === 0x04;
  const isOle = buf.length > 8 && buf[0] === 0xd0 && buf[1] === 0xcf && buf[2] === 0x11 && buf[3] === 0xe0;
  if ((kind === "xlsx" && !isZip) || (kind === "xls" && !isOle && !isZip)) throw new WorkbookError("The file could not be read as a spreadsheet");
  let wb: XLSX.WorkBook;
  try {
    wb = XLSX.read(buf, { type: "array", cellDates: false, dense: true });
  } catch {
    throw new WorkbookError("The file could not be read as a spreadsheet");
  }
  const sheets: Record<string, string[][]> = {};
  for (const n of wb.SheetNames) {
    const ws = wb.Sheets[n];
    if (!ws) continue;
    const rows = XLSX.utils.sheet_to_json<unknown[]>(ws, { header: 1, defval: "", raw: false, blankrows: false });
    sheets[n] = cap(rows.map((r) => r.map((c) => String(c ?? "").trim())).filter((r) => r.some((c) => c !== "")));
  }
  if (!wb.SheetNames.length) throw new WorkbookError("The workbook has no sheets");
  return { names: wb.SheetNames, sheets };
}

function cap(rows: string[][]): string[][] {
  return rows.slice(0, MAX_ROWS).map((r) => r.slice(0, MAX_COLS));
}

/** The sheet with the most rows — almost always the rate table. */
export function largestSheet(wb: Workbook): string {
  return wb.names.reduce((best, n) => (!best || (wb.sheets[n]?.length ?? 0) > (wb.sheets[best]?.length ?? 0) ? n : best), "");
}
