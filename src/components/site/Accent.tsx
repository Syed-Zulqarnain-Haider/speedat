/**
 * Kept so callers from earlier rounds compile: v3 has no italic accent, so a
 * content field prints exactly as typed, asterisks and all. Server-safe.
 * (`lib/site/accent.ts` stays for its tests; nothing renders its parts.)
 */
export function Accent({ text }: { text: string }) {
  return <>{text}</>;
}
