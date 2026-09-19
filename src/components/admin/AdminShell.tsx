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

export function AdminShell({ companyName, user, children, badges }: Props) {
  return (
    <div className="wrap wide">
      <header className="site-head">
        <div className="brand-row">
          <Link className="brand-link" href="/admin">
            <span className="mark" aria-hidden="true" />
            <span>
              <span className="brand-name">{companyName}</span>
              <span className="brand-tag">Admin</span>
            </span>
          </Link>
          <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
            <span className="meta">
              {user.email} · {user.role}
            </span>
            <Link className="btn small" href="/">
              Back to the website
            </Link>
            <SignOutButton />
          </div>
        </div>
        <AdminNav badges={badges ?? {}} />
      </header>
      <main>{children}</main>
    </div>
  );
}
