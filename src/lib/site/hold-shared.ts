/**
 * The price hold's shape and message hygiene — importable from client
 * components. The database side lives in `hold.ts` (server-only).
 */
export interface Hold {
  on: boolean;
  /** Shown to customers in place of the calculator while the hold is on. */
  message: string;
  /** Who switched it on (or off), and when — ISO timestamp, null when never touched. */
  by: string;
  since: string | null;
}

export const HOLD_MESSAGE_MAX = 300;
export const DEFAULT_HOLD_MESSAGE = "Our rates are being updated right now. Send us your destination and parcel weight and we will price it by hand within the hour.";

export const NO_HOLD: Hold = { on: false, message: DEFAULT_HOLD_MESSAGE, by: "", since: null };

/** Trim, collapse whitespace, cap the length; fall back to the default when empty. */
export function cleanHoldMessage(raw: unknown): string {
  const s = String(raw ?? "")
    .replace(/\s+/g, " ")
    .trim()
    .slice(0, HOLD_MESSAGE_MAX);
  return s || DEFAULT_HOLD_MESSAGE;
}
