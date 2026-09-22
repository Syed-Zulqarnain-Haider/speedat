/** Small parsers for the free-text settings the admin edits. */
import { fmtHour } from "@/lib/pricing/format";
import type { Company } from "./types";

export function originCities(company: Pick<Company, "originCities">): string[] {
  return String(company.originCities ?? "")
    .split(/[,;\n]+/)
    .map((t) => t.trim())
    .filter(Boolean);
}

/**
 * The pickup promise the Services, About and Contact pages print from
 * `settings.cutoffHour`, worded to match what the calculator actually does
 * (`estimateDelivery`: a booking at or after the cutoff hour moves pickup to
 * the next working day). At 0 that test is true at every hour, so "Book before
 * 12 am for same-day pickup" would promise what the calculator never gives;
 * the sentence says next-day instead. Blank (null) = no cutoff, no sentence.
 * No trailing full stop: About and Contact print it bare in a row, Services
 * adds its own inside the facts paragraph.
 */
export function cutoffFact(cutoffHour: number | null | undefined): string {
  if (cutoffHour == null) return "";
  const h = Number(cutoffHour);
  if (!Number.isFinite(h)) return "";
  if (h <= 0) return "No same-day pickup; we collect on the next working day";
  return `Book before ${fmtHour(h)} for same-day pickup`;
}
