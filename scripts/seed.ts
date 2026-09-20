/**
 * Seed an empty database with version 1 (the prototype's sample rates) and
 * the first admin. Safe to re-run: does nothing when a version already exists.
 *
 *   pnpm db:seed                      # sample rates only
 *   ADMIN_EMAIL=you@x.com pnpm db:seed  # also allowlist the first owner
 */
import { db, schema } from "@/lib/db";
import { gridSeed } from "@/lib/site/seed";
import { getLatestVersion, publishVersion } from "@/lib/site/repo";

async function main() {
  const live = await getLatestVersion();
  if (live) {
    console.log(`Database already has version ${live.version}; leaving rates alone.`);
  } else {
    const v = await publishVersion({ data: gridSeed(), by: "seed", source: "seed", summary: "Sample rates from the prototype", changeCount: 0 });
    console.log(`Published version ${v.version} with ${v.destinations.length} sample destinations (live=${v.live}).`);
  }
  const email = process.env.ADMIN_EMAIL?.trim().toLowerCase();
  if (email) {
    await db
      .insert(schema.admins)
      .values({ email, name: process.env.ADMIN_NAME ?? "", role: "owner", addedBy: "seed" })
      .onConflictDoNothing();
    console.log(`Admin owner ensured: ${email}`);
  }
}

main()
  .then(() => process.exit(0))
  .catch((err) => {
    console.error(err);
    process.exit(1);
  });
