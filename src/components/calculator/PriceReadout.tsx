"use client";

/**
 * The readout's number. The server (and anyone who asked for less motion)
 * gets the plain formatted text; in the browser each digit becomes a
 * vertical strip of 0–9 that rolls to its value like an odometer, keyed by
 * its distance from the units column so a longer price adds columns on the
 * left and the existing ones keep rolling in place. Each column also holds
 * its current digit in flow but invisible (`.w`): the display face has no
 * tabular figures, so that digit — not the widest of the ten — sets the
 * column's width and the number keeps the same proportions as the plain
 * text. The text lives once in a visually hidden span, so the live region
 * announces "PKR 4,500" — never the ten digits of every strip.
 */
import type { CSSProperties } from "react";
import { useReducedMotion } from "@/lib/client/motion";
import { useMounted } from "@/lib/client/session";
import { fmtMoney, fmtNum } from "@/lib/pricing/format";

const DIGITS = ["0", "1", "2", "3", "4", "5", "6", "7", "8", "9"] as const;

export function PriceReadout({ value, currency, idle }: { value: number; currency: string; idle: boolean }) {
  const mounted = useMounted();
  const reduced = useReducedMotion();
  const text = idle ? `${currency} 0` : fmtMoney(value, currency);
  if (!mounted || reduced) return <span className="tval">{text}</span>;
  const chars = fmtNum(idle ? 0 : value).split("");
  return (
    <span className="tval">
      <span className="sr">{text}</span>
      <span className="odo" aria-hidden="true">
        <span className="cur">{currency}</span>
        {chars.map((ch, i) => {
          const key = chars.length - 1 - i;
          if (ch >= "0" && ch <= "9")
            return (
              <span key={key} className="col">
                <span className="w">{ch}</span>
                <span className="strip" style={{ "--d": Number(ch) } as CSSProperties}>
                  {DIGITS.map((d) => (
                    <span key={d}>{d}</span>
                  ))}
                </span>
              </span>
            );
          return (
            <span key={key} className="sym">
              {ch}
            </span>
          );
        })}
      </span>
    </span>
  );
}
