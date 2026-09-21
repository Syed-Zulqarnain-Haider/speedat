import { AdminEditor } from "@/components/admin/AdminEditor";
import { AdminShell } from "@/components/admin/AdminShell";
import { requireAdminPage } from "@/lib/auth/session";
import { getIntakeSettings, listImports } from "@/lib/import/intake";
import { leadCounts } from "@/lib/leads";
import { getHold } from "@/lib/site/hold";
import { getDraft, getLatestVersion, listVersions } from "@/lib/site/repo";

export const dynamic = "force-dynamic";

export default async function AdminPage() {
  const user = await requireAdminPage();
  const [live, draft, versions, imports, intake, counts, hold] = await Promise.all([
    getLatestVersion(),
    getDraft(),
    listVersions(20),
    listImports(15),
    getIntakeSettings(),
    leadCounts(),
    getHold(),
  ]);
  if (!live) {
    return (
      <div className="admin-app">
        <main className="admin-main">
          <section className="admin">
            <div className="topbar">
              <div>
                <p className="eyebrow">Admin</p>
                <h1>Rates admin</h1>
              </div>
            </div>
            <div className="notice err">
              The database has no published version yet. Run <code>pnpm db:seed</code> once.
            </div>
          </section>
        </main>
      </div>
    );
  }
  return (
    <AdminShell companyName={live.company.name} user={user} badges={{ inbox: counts.new }}>
      <AdminEditor
        live={live}
        draft={draft}
        versions={versions}
        user={user}
        imports={imports}
        intake={intake}
        hold={hold}
        newLeads={counts.new}
        sheetsWaiting={imports.filter((i) => i.status === "needs_mapping").length}
      />
    </AdminShell>
  );
}
