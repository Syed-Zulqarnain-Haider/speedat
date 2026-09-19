import Link from "next/link";
import { AdminEditor } from "@/components/admin/AdminEditor";
import { SignOutButton } from "@/components/admin/SignOutButton";
import { requireAdminPage } from "@/lib/auth/session";
import { getIntakeSettings, listImports } from "@/lib/import/intake";
import { getDraft, getLatestVersion, listVersions } from "@/lib/site/repo";

export const dynamic = "force-dynamic";

export default async function AdminPage() {
  const user = await requireAdminPage();
  const [live, draft, versions, imports, intake] = await Promise.all([getLatestVersion(), getDraft(), listVersions(20), listImports(15), getIntakeSettings()]);
  if (!live) {
    return (
      <div className="wrap">
        <section className="page">
          <h1>Rates admin</h1>
          <div className="notice err">The database has no published version yet. Run <code>pnpm db:seed</code> once.</div>
        </section>
      </div>
    );
  }
  return (
    <div className="wrap wide">
      <header className="site-head">
        <div className="brand-row">
          <Link className="brand-link" href="/">
            <span className="mark" aria-hidden="true" />
            <span>
              <span className="brand-name">{live.company.name}</span>
              <span className="brand-tag">Rates admin</span>
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
      </header>
      <AdminEditor live={live} draft={draft} versions={versions} user={user} imports={imports} intake={intake} />
    </div>
  );
}
