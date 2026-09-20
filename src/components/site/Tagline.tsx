"use client";

import ShinyText from "@/components/bits/ShinyText";
import { useReducedMotion } from "@/lib/client/motion";
import { useMounted } from "@/lib/client/session";

/** The brand tagline with a slow shine sweep; plain text until mounted or when motion is reduced. */
export function Tagline({ text }: { text: string }) {
  const reduced = useReducedMotion();
  const mounted = useMounted();
  if (!mounted || reduced) return <span className="brand-tag">{text}</span>;
  return (
    <span className="brand-tag">
      <ShinyText text={text} speed={4} color="var(--muted)" shineColor="#ea580c" spread={90} />
    </span>
  );
}
