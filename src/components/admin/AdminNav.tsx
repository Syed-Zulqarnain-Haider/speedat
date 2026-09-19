"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

const LINKS = [
  { href: "/admin", label: "Rates", key: "rates" },
  { href: "/admin/inbox", label: "Inbox", key: "inbox" },
  { href: "/admin/shipments", label: "Shipments", key: "shipments" },
  { href: "/admin/security", label: "Security", key: "security" },
] as const;

export function AdminNav({ badges }: { badges: Partial<Record<string, number>> }) {
  const path = usePathname();
  return (
    <nav className="nav" aria-label="Admin sections">
      {LINKS.map((l) => {
        const active = l.href === "/admin" ? path === "/admin" : path.startsWith(l.href);
        const n = badges[l.key];
        return (
          <Link key={l.href} href={l.href} aria-current={active ? "page" : undefined}>
            {l.label}
            {n ? <span className="opt-badge" style={{ marginLeft: 6 }}>{n}</span> : null}
          </Link>
        );
      })}
    </nav>
  );
}
