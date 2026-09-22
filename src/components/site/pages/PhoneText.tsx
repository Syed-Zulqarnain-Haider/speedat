import { Fragment } from "react";
import type { PhoneSegment } from "./phones";

/**
 * An owner-typed line with every phone number in it as a `tel:` link and
 * the rest as plain text (brief v3 §3: "every number a plain link"). Used
 * for `content.phone2` on /contact, whose value may carry a label and more
 * than one number; the page reads the field with `phoneSegmentsExcept` so
 * the numbers it already states are not repeated, and decides from the
 * result whether the row renders at all. Server component; renders
 * nothing for no segments.
 */
export function PhoneText({ segments }: { segments: readonly PhoneSegment[] }) {
  if (!segments.length) return null;
  return (
    <>
      {segments.map((s, i) =>
        s.tel ? (
          <a key={i} href={s.tel}>
            {s.text}
          </a>
        ) : (
          <Fragment key={i}>{s.text}</Fragment>
        ),
      )}
    </>
  );
}
