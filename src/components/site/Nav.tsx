"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

/** Short plain words; "Get a price" is the whole product, so it comes first. */
const LINKS: readonly { href: string; label: string }[] = [
  { href: "/", label: "Get a price" },
  { href: "/services", label: "Services" },
  { href: "/about", label: "About" },
  { href: "/contact", label: "Contact" },
  { href: "/faq", label: "FAQ" },
];

/**
 * The five page links with the current page marked. Rendered twice per page:
 * in the header (hidden below 1024 by CSS) and in the footer as `Pages`, which
 * on a phone is the site's only nav (brief v3 §3). Nothing but links lives
 * inside the landmark; the theme toggle is the footer's own sibling.
 */
export function Nav({ label = "Site" }: { label?: string }) {
  const path = usePathname();
  return (
    <nav className="nav" aria-label={label}>
      {LINKS.map((l) => (
        <Link key={l.href} href={l.href} aria-current={path === l.href ? "page" : undefined}>
          {l.label}
        </Link>
      ))}
    </nav>
  );
}
