"use client";

/**
 * The readout's number. Idle renders nothing — the readout's note says what
 * to do next, never "PKR 0". Live, the server (and anyone who asked for less
 * motion) gets the plain formatted text; in the browser the figure counts up
 * from the previous price (or from 60 % of the first one) to its value over
 * 700 ms, ease-out cubic, through a short rAF loop driven by React state,
 * with a settle timer so a hidden tab still lands on the real figure. The
 * text lives once in a visually hidden span, so the live region announces
 * "PKR 4,500" once per change — never every frame of the count.
 */
import { useEffect, useRef, useState } from "react";
import { useReducedMotion } from "@/lib/client/motion";
import { useMounted } from "@/lib/client/session";
import { fmtMoney, fmtNum } from "@/lib/pricing/format";

const DURATION = 700;

interface Shown {
  /** The value this frame belongs to; a stale frame for an older value is ignored. */
  for: number;
  n: number;
}

export function PriceReadout({ value, currency, idle }: { value: number; currency: string; idle: boolean }) {
  const mounted = useMounted();
  const reduced = useReducedMotion();
  const [shown, setShown] = useState<Shown | null>(null);
  // The last value the count settled on; only the effect reads or writes it.
  const lastRef = useRef<number | null>(null);

  useEffect(() => {
    if (idle || reduced) return;
    const from = lastRef.current ?? Math.round(value * 0.6);
    lastRef.current = value;
    if (from === value) return;
    let raf = 0;
    let settle = 0;
    let started = 0;
    const tick = (now: number) => {
      if (!started) started = now;
      const t = Math.min(1, (now - started) / DURATION);
      const eased = 1 - (1 - t) ** 3;
      setShown({ for: value, n: t < 1 ? Math.round(from + eased * (value - from)) : value });
      if (t < 1) raf = requestAnimationFrame(tick);
      else clearTimeout(settle);
    };
    raf = requestAnimationFrame(tick);
    settle = window.setTimeout(() => {
      cancelAnimationFrame(raf);
      setShown({ for: value, n: value });
    }, DURATION + 100);
    return () => {
      cancelAnimationFrame(raf);
      clearTimeout(settle);
    };
  }, [value, idle, reduced]);

  if (idle) return null;
  const text = fmtMoney(value, currency);
  if (!mounted || reduced) return <span className="tval">{text}</span>;
  // Before the first frame: the previous settled figure, or 60 % of a first price — the same start the count uses.
  const n = shown ? shown.n : Math.round(value * 0.6);
  return (
    <span className="tval">
      <span className="sr">{text}</span>
      <span className="num" aria-hidden="true">
        <span className="cur">{currency}</span> {fmtNum(n)}
      </span>
    </span>
  );
}
