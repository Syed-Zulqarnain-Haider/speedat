import Link from "next/link";
import type { ReactNode } from "react";
import type { AdminUser } from "@/lib/auth/session";
import { SignOutButton } from "./SignOutButton";
import { AdminNav } from "./AdminNav";

interface Props {
  companyName: string;
  user: AdminUser;
  children: ReactNode;
  /** Badge counts for the nav, e.g. new leads. */
  badges?: Partial<Record<"inbox" | "shipments", number>>;
}

/**
 * The admin frame: a sticky sidebar from 1024px (brand, sections, the
 * signed-in user) and a compact top bar with a scrollable nav below it.
 * Both are always in the DOM; CSS shows one, so nothing branches on the
 * client and the server HTML is final.
 */
export function AdminShell({ companyName, user, children, badges }: Props) {
  const b = badges ?? {};
  return (
    <div className="admin-app">
      <aside className="admin-side">
        <Link className="brand-link" href="/admin">
          <span className="mark" aria-hidden="true" />
          <span className="brand-text">
            <span className="brand-name">{companyName}</span>
            <span className="eyebrow">Admin</span>
          </span>
        </Link>
        <AdminNav variant="side" badges={b} />
        <div className="admin-user">
          <span className="mono admin-email" title={user.email}>
            {user.email}
          </span>
          <span className="tag">{user.role}</span>
          <div className="admin-user-actions">
            <Link className="btn outline small" href="/">
              View website ↗
            </Link>
            <SignOutButton />
          </div>
        </div>
      </aside>
      <header className="admin-top">
        <div className="admin-top-row">
          <Link className="brand-link" href="/admin" aria-label={`${companyName} admin`}>
            <span className="mark" aria-hidden="true" />
            <span className="eyebrow">Admin</span>
          </Link>
          <div className="admin-top-user">
            <span className="mono admin-email" title={user.email}>
              {user.email}
            </span>
            <SignOutButton />
          </div>
        </div>
        <AdminNav variant="top" badges={b} />
      </header>
      <main className="admin-main">{children}</main>
    </div>
  );
}
