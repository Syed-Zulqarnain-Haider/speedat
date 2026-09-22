/**
 * Where the page must scroll so a box lands in the band the owner can actually see:
 * below the sticky section tabs and above the fixed publish bar. Used for the review
 * card after "Review and publish", which `scrollIntoView({ block: "nearest" })` used
 * to align with the viewport's bottom edge — under the bar, buttons and all.
 */

/** A box's edges in viewport pixels, as getBoundingClientRect reports them. */
export interface Edges {
  top: number;
  bottom: number;
}

/**
 * How far to scroll (positive = down) so `box` is clear of the chrome. `floor` is the
 * top of the publish bar (the bottom of the visible band), `ceiling` the bottom of
 * the sticky tabs (0 when there are none). When the box fits in the band it is brought
 * in by its nearest edge with `gap` to spare; when it is taller than the band its top
 * lands under the tabs so it reads from the start. 0 when it is already fully in view.
 */
export function scrollToClear(box: Edges, floor: number, ceiling = 0, gap = 16): number {
  const top = ceiling + gap;
  const bottom = floor - gap;
  if (box.top >= top && box.bottom <= bottom) return 0;
  const fits = box.bottom - box.top <= bottom - top;
  if (fits && box.bottom > bottom) return box.bottom - bottom;
  return box.top - top;
}
