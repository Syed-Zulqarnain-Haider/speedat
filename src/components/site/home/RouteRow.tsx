"use client";

/**
 * One destination tile of "Where we deliver". On the home page a tap
 * selects the country in the calculator (which already subscribes to the
 * `sp-last` session key; the remembered kg is preserved) and scrolls to the
 * calculator. On /services (`href` given) it renders a link, writes the
 * same key and lets the navigation proceed.
 */
import type { ReactNode } from "react";
import { useReducedMotion } from "@/lib/client/motion";
import { setSession, useSession } from "@/lib/client/session";

interface Remembered {
  destId?: string;
  kg?: string;
}

function safeParse(raw: string | null): Remembered {
  if (!raw) return {};
  try {
    const v = JSON.parse(raw) as Remembered;
    return typeof v === "object" && v ? v : {};
  } catch {
    return {};
  }
}

export function RouteRow({ destId, href, children }: { destId: string; href?: string; children: ReactNode }) {
  const raw = useSession("sp-last");
  const reduced = useReducedMotion();
  const remember = () => setSession("sp-last", JSON.stringify({ ...safeParse(raw), destId }));
  if (href) {
    return (
      <a className="dest" href={href} onClick={remember}>
        {children}
      </a>
    );
  }
  return (
    <button
      className="dest"
      type="button"
      onClick={() => {
        remember();
        document.getElementById("quote-instrument")?.scrollIntoView({ behavior: reduced ? "auto" : "smooth", block: "start" });
      }}
    >
      {children}
    </button>
  );
}
