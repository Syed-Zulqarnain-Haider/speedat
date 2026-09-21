"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { Fragment } from "react";
import { SECTIONS } from "./sections";

const LINKS = [
  { href: "/admin", label: "Rates", key: "rates" },
  { href: "/admin/inbox", label: "Inbox", key: "inbox" },
  { href: "/admin/shipments", label: "Shipments", key: "shipments" },
  { href: "/admin/security", label: "Security", key: "security" },
] as const;

interface Props {
  badges: Partial<Record<string, number>>;
  /** `side` is the desktop sidebar list (with the editor's section sub-list); `top` is the scrollable phone row. */
  variant?: "side" | "top";
}

export function AdminNav({ badges, variant = "top" }: Props) {
  const path = usePathname();
  const onEditor = path === "/admin";
  return (
    <nav className={`anav anav-${variant}`} aria-label="Admin sections">
      {LINKS.map((l) => {
        const active = l.href === "/admin" ? onEditor : path.startsWith(l.href);
        const n = badges[l.key];
        return (
          <Fragment key={l.href}>
            <Link href={l.href} aria-current={active ? "page" : undefined}>
              {l.label}
              {n ? <span className="count">{n}</span> : null}
            </Link>
            {variant === "side" && l.key === "rates" && onEditor ? (
              <ul className="anav-sub" aria-label="Rates sections">
                {SECTIONS.map(([id, label], i) => (
                  <li key={id}>
                    <a href={`#sec-${id}`}>
                      <span className="anav-n" aria-hidden="true">
                        {String(i + 1).padStart(2, "0")}
                      </span>
                      {label}
                    </a>
                  </li>
                ))}
              </ul>
            ) : null}
          </Fragment>
        );
      })}
    </nav>
  );
}
