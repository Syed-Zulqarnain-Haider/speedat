/**
 * The seven sections of the rates editor, in page order. The editor's
 * anchors and the sidebar's sub-list both read this so the `sec-*` ids
 * (which restore's scrollIntoView relies on) never drift apart.
 */
export const SECTIONS = [
  ["rates", "Rates"],
  ["import", "Import from Excel"],
  ["bulk", "Bulk adjust"],
  ["test", "Test a price"],
  ["settings", "Settings"],
  ["content", "Website pages"],
  ["history", "History"],
] as const;

export type SectionId = (typeof SECTIONS)[number][0];

/** "01", "02", … the numbered eyebrow above each section heading. */
export function sectionNo(id: SectionId): string {
  const i = SECTIONS.findIndex(([x]) => x === id);
  return String(i + 1).padStart(2, "0");
}
