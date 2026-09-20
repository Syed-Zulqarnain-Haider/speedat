"use client";

/**
 * Animated hero for the quote page: a reactive dot field behind the headline,
 * the headline entering word by word, the sub-line blurring in, and the stats
 * counting up. Everything renders as plain text first (SEO, no layout shift)
 * and falls back to static when the visitor prefers reduced motion.
 */
import { useReducedMotion } from "@/lib/client/motion";
import { useMounted } from "@/lib/client/session";
import BlurText from "@/components/bits/BlurText";
import CountUp from "@/components/bits/CountUp";
import DotGrid from "@/components/bits/DotGrid";
import SplitText from "@/components/bits/SplitText";

export interface HeroStat {
  /** Numeric part animates; `null` renders `text` as-is. */
  value: number | null;
  text: string;
  suffix?: string;
  label: string;
}

export function Hero({ title, sub, stats }: { title: string; sub: string; stats: HeroStat[] }) {
  const reduced = useReducedMotion();
  const mounted = useMounted();
  const animate = mounted && !reduced;
  return (
    <div className="hero hero-animated">
      {animate ? (
        <div className="hero-bg" aria-hidden="true">
          <DotGrid dotSize={3} gap={30} baseColor="#d9d2c5" activeColor="#ea580c" proximity={120} shockRadius={220} shockStrength={4} returnDuration={1.2} className="hero-dots" />
        </div>
      ) : null}
      <div className="hero-fg">
        {animate ? (
          <SplitText tag="h1" text={title} splitType="words" delay={70} duration={0.9} ease="power3.out" textAlign="left" threshold={0} rootMargin="0px" from={{ opacity: 0, y: 24 }} to={{ opacity: 1, y: 0 }} />
        ) : (
          <h1>{title}</h1>
        )}
        {animate ? <BlurText text={sub} className="lede" animateBy="words" delay={40} direction="bottom" threshold={0} rootMargin="0px" /> : <p className="lede">{sub}</p>}
        <ul className="stats">
          {stats.map((s) => (
            <li key={s.label}>
              <strong>
                {animate && s.value != null ? <CountUp to={s.value} duration={1.4} delay={0.3} /> : s.text}
                {s.value != null ? s.suffix : ""}
              </strong>
              <span>{s.label}</span>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
