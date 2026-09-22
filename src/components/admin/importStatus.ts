/**
 * The one-line status of a rate sheet in the admin's "Recent sheets" list.
 * Pure, so the wording can be tested without the panel.
 */
import type { ImportSummary } from "@/lib/import/intake";

export const STATUS_LABEL: Record<string, string> = {
  needs_mapping: "needs mapping",
  applied: "in the editor — publish to go live",
  published: "published",
  rejected: "rejected",
  failed: "could not be read",
};

export type ImportStatusInput = Pick<ImportSummary, "status" | "appliedVersion" | "publishedAuto" | "rows" | "errors" | "error">;

/** e.g. "published (version 20) · 3 rows", "published automatically (version 5) · 11 rows", "could not be read · The file…". */
export function importStatusText(i: ImportStatusInput): string {
  const label = i.status === "published" && i.publishedAuto ? "published automatically" : (STATUS_LABEL[i.status] ?? i.status);
  return (
    label +
    (i.appliedVersion ? ` (version ${i.appliedVersion})` : "") +
    (i.rows ? ` · ${i.rows} rows` : "") +
    (i.errors ? ` · ${i.errors} skipped` : "") +
    (i.error ? ` · ${i.error}` : "")
  );
}
