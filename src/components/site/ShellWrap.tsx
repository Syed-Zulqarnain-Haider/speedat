"use client";

import { usePathname } from "next/navigation";
import type { ReactNode } from "react";

/** The prototype narrows the shell on the quote page and widens it elsewhere. */
export function ShellWrap({ children }: { children: ReactNode }) {
  const path = usePathname();
  return <div className={path === "/" ? "wrap" : "wrap mid"}>{children}</div>;
}
