"use client";

import type { ReactNode } from "react";

/** The page container: one width everywhere (`--wrap`), gutters from `--gutter`. */
export function ShellWrap({ children }: { children: ReactNode }) {
  return <div className="wrap">{children}</div>;
}
