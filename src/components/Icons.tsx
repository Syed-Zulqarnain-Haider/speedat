/** Inline SVG icons from the prototype. Keyed names are what the admin's "icon | Title | Text" lines use. */
import type { SVGProps } from "react";

type P = SVGProps<SVGSVGElement>;

const base = { viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", "aria-hidden": true } as const;

export const CardIcons = {
  plane: (p: P) => (
    <svg {...base} strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" className="ico" {...p}>
      <path d="M2.5 13.5 21 4l-6 17-3-7.5z" />
      <path d="M12 13.5 21 4" />
    </svg>
  ),
  globe: (p: P) => (
    <svg {...base} strokeWidth={1.8} className="ico" {...p}>
      <circle cx="12" cy="12" r="9" />
      <path d="M3 12h18M12 3c3 3.2 3 14.8 0 18M12 3c-3 3.2-3 14.8 0 18" />
    </svg>
  ),
  doc: (p: P) => (
    <svg {...base} strokeWidth={1.8} strokeLinejoin="round" className="ico" {...p}>
      <path d="M6 3h8l5 5v13H6z" />
      <path d="M14 3v5h5M9 13h7M9 17h7" />
    </svg>
  ),
  box: (p: P) => (
    <svg {...base} strokeWidth={1.8} strokeLinejoin="round" className="ico" {...p}>
      <path d="M3 8l9-4 9 4v9l-9 4-9-4z" />
      <path d="M3 8l9 4 9-4M12 12v9" />
    </svg>
  ),
  truck: (p: P) => (
    <svg {...base} strokeWidth={1.8} strokeLinejoin="round" className="ico" {...p}>
      <path d="M3 6h11v10H3zM14 10h4l3 3v3h-7z" />
      <circle cx="7" cy="17.5" r="1.8" />
      <circle cx="17" cy="17.5" r="1.8" />
    </svg>
  ),
  shield: (p: P) => (
    <svg {...base} strokeWidth={1.8} strokeLinejoin="round" className="ico" {...p}>
      <path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6z" />
      <path d="M9 12l2 2 4-4" />
    </svg>
  ),
  clock: (p: P) => (
    <svg {...base} strokeWidth={1.8} strokeLinecap="round" className="ico" {...p}>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3 2" />
    </svg>
  ),
  phone: (p: P) => (
    <svg {...base} strokeWidth={1.8} strokeLinejoin="round" className="ico" {...p}>
      <path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z" />
    </svg>
  ),
} as const;

export type CardIconName = keyof typeof CardIcons;
export const CARD_ICON_ORDER: CardIconName[] = ["plane", "globe", "doc", "box", "truck", "shield", "clock", "phone"];

export function isCardIcon(name: string): name is CardIconName {
  return Object.prototype.hasOwnProperty.call(CardIcons, name);
}

/** Small UI glyphs used inside labels and buttons. */
export const UI = {
  pin: (p: P) => (
    <svg {...base} strokeWidth={2} strokeLinejoin="round" {...p}>
      <path d="M12 21s-6-5.3-6-11a6 6 0 0 1 12 0c0 5.7-6 11-6 11z" />
      <circle cx="12" cy="10" r="2.2" />
    </svg>
  ),
  scale: (p: P) => (
    <svg {...base} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M12 3v3M4 7h16M12 6l-5 9h10z" />
      <path d="M6 20h12" />
    </svg>
  ),
  clock: (p: P) => (
    <svg {...base} strokeWidth={2} strokeLinecap="round" {...p}>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3 2" />
    </svg>
  ),
  calc: (p: P) => (
    <svg {...base} strokeWidth={2} strokeLinejoin="round" {...p}>
      <rect x="5" y="3" width="14" height="18" rx="2" />
      <path d="M8 7h8M8 12h3M13 12h3M8 16h3M13 16h3" />
    </svg>
  ),
  chat: (p: P) => (
    <svg {...base} strokeWidth={2} strokeLinejoin="round" {...p}>
      <path d="M4 5h16v11H9l-5 4z" />
    </svg>
  ),
  box: (p: P) => (
    <svg {...base} strokeWidth={2} strokeLinejoin="round" {...p}>
      <path d="M3 8l9-4 9 4v9l-9 4-9-4z" />
      <path d="M3 8l9 4 9-4M12 12v9" />
    </svg>
  ),
  /** A rounded speech bubble with a small handset inside ("message us by phone"): original paths, not a trademarked logo. */
  wa: (p: P) => (
    <svg {...base} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M20 13.5a2.5 2.5 0 0 1-2.5 2.5H10l-5 4V5.5A2.5 2.5 0 0 1 7.5 3h10A2.5 2.5 0 0 1 20 5.5z" />
      <path d="M9 6.5h1.8l.9 2.2-1.1.7a4.9 4.9 0 0 0 2.2 2.2l.7-1.1 2.2.9v1.8a.9.9 0 0 1-.9.9A7.2 7.2 0 0 1 8.1 7.4a.9.9 0 0 1 .9-.9z" />
    </svg>
  ),
  /** A price tag: step 3 of the calculator ("Your price"). */
  tag: (p: P) => (
    <svg {...base} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M3 12.5V4h8.5l9.5 9.5-8.5 8.5z" />
      <circle cx="7.5" cy="8.5" r="1.5" />
    </svg>
  ),
  /** The tick beside a finished step. */
  check: (p: P) => (
    <svg {...base} strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M5 12.5l4.5 4.5L19 7.5" />
    </svg>
  ),
  /** The theme toggle while dark mode is on (tap for light). */
  sun: (p: P) => (
    <svg {...base} strokeWidth={2} strokeLinecap="round" {...p}>
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2.5V5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3l1.8 1.8M16.9 16.9l1.8 1.8M5.3 18.7l1.8-1.8M16.9 7.1l1.8-1.8" />
    </svg>
  ),
  /** The theme toggle while light mode is on (tap for dark). */
  moon: (p: P) => (
    <svg {...base} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11z" />
    </svg>
  ),
  /** A handset: the Call buttons. */
  phone: (p: P) => (
    <svg {...base} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
      <path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z" />
    </svg>
  ),
} as const;
