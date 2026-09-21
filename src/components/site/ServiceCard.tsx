"use client";

import type { ReactNode } from "react";
import AnimatedContent from "@/components/bits/AnimatedContent";
import SpotlightCard from "@/components/bits/SpotlightCard";
import { useReducedMotion } from "@/lib/client/motion";
import { useMounted } from "@/lib/client/session";

/**
 * A service card: slides in on scroll and carries a warm spotlight under the
 * pointer. The server, the hydration pass and reduced motion all paint the
 * plain `.card` with the same children, so nothing shifts when the effect mounts.
 */
export function ServiceCard({ index, children }: { index: number; children: ReactNode }) {
  const reduced = useReducedMotion();
  const mounted = useMounted();
  if (!mounted || reduced) return <div className="card">{children}</div>;
  return (
    <AnimatedContent distance={32} direction="vertical" duration={0.7} ease="power3.out" initialOpacity={0} threshold={0.15} delay={Math.min(index, 5) * 0.08}>
      <SpotlightCard className="card card-spot" spotlightColor="rgba(234, 88, 12, 0.14)">
        {children}
      </SpotlightCard>
    </AnimatedContent>
  );
}
