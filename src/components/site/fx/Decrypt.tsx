"use client";

/**
 * A short id that resolves from scrambled characters once in view (P2:
 * used for the quote id in the manifest card only when the home route's
 * budget allows). Server, reduced motion and the hydration pass render the
 * plain text; the vendored component keeps the real text for assistive tech.
 */
import DecryptedText from "@/components/bits/DecryptedText";
import { useReducedMotion } from "@/lib/client/motion";
import { useMounted } from "@/lib/client/session";

export function Decrypt({ text }: { text: string }) {
  const mounted = useMounted();
  const reduced = useReducedMotion();
  if (!mounted || reduced) return <span>{text}</span>;
  return <DecryptedText text={text} animateOn="view" sequential speed={40} characters="0123456789ABCDEFGHJKLMNPQRSTUVWXYZ" />;
}
