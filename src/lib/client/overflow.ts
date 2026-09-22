/**
 * Which sides of a sideways-scrolling box still hide content, from the box's own
 * scroll metrics. Drives the shadow the admin tables cast from a pinned column, the
 * cue that there is more to the side: the scrollbar itself is an overlay on Windows 11
 * and macOS and is not drawn until the pointer moves over it.
 */
export type HiddenEdges = "" | "left" | "right" | "left right";

export function hiddenEdges(scrollLeft: number, clientWidth: number, scrollWidth: number): HiddenEdges {
  // A pixel of slack: scrollLeft can be fractional and the widths are rounded.
  if (scrollWidth - clientWidth <= 1) return "";
  const left = scrollLeft > 1;
  const right = scrollLeft + clientWidth < scrollWidth - 1;
  if (left && right) return "left right";
  if (left) return "left";
  if (right) return "right";
  return "";
}
