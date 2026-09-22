import { isCardIcon } from "@/components/Icons";
import { lines, parts } from "@/lib/pricing/engine";

/**
 * Every `content.services` line as one row of a plain list (brief v3 §3):
 * the name, then the sentence — side by side from 720, stacked below, a
 * hairline under each. The line format `icon | Title | text` is kept for
 * the admin's sake; the icon token is parsed and dropped (nothing on the
 * page is an icon unless it is the affordance). A line without a known
 * icon token reads as `Title | text`. Server component.
 */
export function ServiceList({ services }: { services: string }) {
  const items = lines(services).map((ln) => {
    const p = parts(ln, 3);
    const named = isCardIcon(p[0].toLowerCase());
    const title = named ? p[1] : p[0];
    const text = named ? p[2] : p[1] || p[2];
    return { title, text };
  });
  if (!items.length) return null;
  return (
    <ul className="svc-list">
      {items.map((x, i) => (
        <li key={i}>
          {x.title ? <strong>{x.title}</strong> : null}
          {x.text ? <span>{x.text}</span> : null}
        </li>
      ))}
    </ul>
  );
}
