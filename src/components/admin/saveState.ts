/**
 * The pubbar's save tracker: whether the draft in memory matches the server's
 * copy, and when the server last stored it ("Draft saved <time>").
 */
export type SaveState = "saved" | "dirty" | "saving" | "error";

/**
 * The tracker after the whole draft is replaced. Discard, restore, import and
 * publish hand back a document the server has ALREADY stored, together with
 * the time it stored it: the draft is saved as of that moment, not as of the
 * previous autosave. Pasted rows replace the draft locally only, so they are
 * dirty and autosave like typing (the last save time stands until then).
 */
export function afterReplace(storedAt: string | undefined): { save: SaveState; savedAt?: string } {
  return storedAt == null ? { save: "dirty" } : { save: "saved", savedAt: storedAt };
}

/**
 * Whether discard and restore must wait. An autosave already on the wire lands
 * after them and writes the thrown-away edits back over the server's draft, so
 * both buttons stay disabled until it returns. A pending (not yet sent) save is
 * fine: replacing the draft cancels its timer.
 */
export function replaceBlocked(save: SaveState): boolean {
  return save === "saving";
}
