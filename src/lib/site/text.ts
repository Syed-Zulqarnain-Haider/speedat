/** Small parsers for the free-text settings the admin edits. */
import type { Company } from "./types";

export function originCities(company: Pick<Company, "originCities">): string[] {
  return String(company.originCities ?? "")
    .split(/[,;\n]+/)
    .map((t) => t.trim())
    .filter(Boolean);
}
