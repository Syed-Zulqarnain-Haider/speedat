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
