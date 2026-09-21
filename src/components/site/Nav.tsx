"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

/** Short plain words; "Get a price" is the whole product, so it comes first. All five stay on every phone. */
const LINKS: readonly { href: string; label: string }[] = [
  { href: "/", label: "Get a price" },
  { href: "/services", label: "Services" },
  { href: "/about", label: "About" },
  { href: "/contact", label: "Contact" },
  { href: "/faq", label: "FAQ" },
];

export function Nav() {
  const path = usePathname();
  return (
    <nav className="nav" aria-label="Site">
      {LINKS.map((l) => (
        <Link key={l.href} href={l.href} aria-current={path === l.href ? "page" : undefined}>
          {l.label}
        </Link>
      ))}
    </nav>
  );
}
