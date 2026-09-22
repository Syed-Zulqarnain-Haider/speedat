/**
 * The inbox list's copy of a lead after the server stored a status / note
 * change. Everything the card shows about the save comes from the server's
 * row (status, notes, who touched it and when), so the "Last touched by"
 * footer appears on the save itself and not on the next reload.
 */
export interface LeadSaved {
  status: string;
  notes: string;
  updatedAt: string;
  updatedBy: string;
}

export function applyLeadSave<T extends LeadSaved & { id: number }>(rows: T[], id: number, saved: LeadSaved): T[] {
  return rows.map((r) => (r.id === id ? { ...r, status: saved.status, notes: saved.notes, updatedAt: saved.updatedAt, updatedBy: saved.updatedBy } : r));
}
