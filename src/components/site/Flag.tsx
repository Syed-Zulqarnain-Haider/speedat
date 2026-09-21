import Image from "next/image";

interface Props {
  /** Lowercase ISO code from `flagCode()`, or null for the two-letter badge. */
  code: string | null;
  /** The destination's name; always printed beside the flag, so the image itself is decorative. */
  name: string;
  /** Width in px; the height is 3/4 of it (flag-icons' 4:3 set). */
  size?: number;
  /**
   * Defer the file until the flag scrolls near the viewport. Off by default:
   * the flag is the one symbol a first-time visitor recognises, so the country
   * tiles, the "1 Country" tick and the hold panel's flag must be there on the
   * first paint, not pop in a moment later. Pass `lazy` only for flags that
   * start below the fold (the "Where we deliver" grid).
   */
  lazy?: boolean;
}

/**
 * A country flag from public/flags/<code>.svg (local files, so the CSP's
 * img-src 'self' allows them), or a two-letter badge when the country is not
 * in the map. Server-safe; `next/image` with `unoptimized` emits a plain
 * `<img>` with width and height (no layout shift) and no external request.
 * Above-the-fold flags are fetched with high priority (a preload hint plus
 * `fetchpriority="high"`), so they land with the first paint.
 */
export function Flag({ code, name, size = 32, lazy = false }: Props) {
  const h = Math.round(size * 0.75);
  if (!code) {
    return (
      <span className="flag flag-badge" style={{ width: size, height: h }} aria-hidden="true">
        {name.replace(/[^A-Za-z]/g, "").slice(0, 2).toUpperCase()}
      </span>
    );
  }
  const src = `/flags/${code}.svg`;
  if (lazy) return <Image className="flag" src={src} alt="" width={size} height={h} unoptimized loading="lazy" />;
  return <Image className="flag" src={src} alt="" width={size} height={h} unoptimized priority />;
}
