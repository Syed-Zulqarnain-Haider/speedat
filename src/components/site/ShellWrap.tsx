"use client";

import type { ReactNode } from "react";

/**
 * The page container: one width everywhere (`--wrap`), gutters from
 * `--gutter`. `.site` is the scope every customer override hangs from
 * (the v2 type scale, targets and skins in tokens.css), so the admin is
 * untouched by them.
 */
export function ShellWrap({ children }: { children: ReactNode }) {
  return <div className="site wrap">{children}</div>;
}
