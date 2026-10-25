// Dump the Next.js Postgres database to JSON, so bin/export-from-nextjs.php can
// turn it into MySQL.
//
// WHY THIS FILE EXISTS AT ALL
//   The exporter would rather read Postgres directly with PDO. It cannot on this
//   machine: D:\php (PHP 8.2.34) is built without pdo_pgsql - `PDO::getAvailableDrivers()`
//   returns ["mysql"] and nothing else, and there is no pgsql extension either.
//   Compiling one is not a reasonable step in a migration that has to be
//   repeatable by the owner.
//
//   So the read is done here, by the one tool on this machine that already has a
//   working Postgres client: Node, with the `postgres` package the Next.js app
//   itself depends on. Nothing new is installed.
//
//   bin/export-from-nextjs.php still contains the direct-PDO path. The day it
//   runs somewhere with pdo_pgsql it skips this file entirely.
//
// EVERY COLUMN COMES BACK AS TEXT
//   The SELECTs below cast everything - timestamps, integers, booleans, jsonb -
//   to text in SQL, and this script is a dumb pipe. That is deliberate:
//
//     * jsonb::text is byte-faithful. Parsing JSON into JavaScript and
//       re-serialising it would reorder keys, turn 1.0 into 1, and quietly
//       round any integer past 2^53 - inside a rate card, that is a wrong price.
//     * bigint::text cannot lose precision on the way through a JS number.
//     * timestamptz is converted to UTC and formatted as MySQL's DATETIME here,
//       where Postgres itself does the arithmetic, instead of in two languages'
//       date libraries afterwards.
//     * booleans arrive as '0'/'1', which is what MySQL stores.
//
//   Sub-second precision is dropped: the MySQL schema uses DATETIME with no
//   fractional part, so to_char truncates rather than the database rejecting it.
//   Rows written in the same second keep their order through their id.
//
// USAGE
//   node php/deploy/export/dump-postgres.mjs \
//     --url=postgres://prometheus:prometheus@127.0.0.1:55432/speedat_dev \
//     --out=../../../var/nextjs-dump.json
//
//   --url   defaults to $DATABASE_URL, then to the local dev database above.
//   --out   defaults to php/var/nextjs-dump.json.
//   --pretty  readable JSON (bigger file; fine, it is read once).
//
// IT READS AND NEVER WRITES. The only statements in this file are SELECTs, and
// the connection is opened without a transaction, so it cannot modify the source
// database even by accident. Run it against production Postgres with a
// read-only role if you have one.

import { mkdir, writeFile } from "node:fs/promises";
import { createRequire } from "node:module";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const phpRoot = resolve(here, "../..");          // .../speedat/php
const repoRoot = resolve(phpRoot, "..");         // .../speedat

// `postgres` lives in the Next.js app's node_modules, not in a package.json of
// our own - the PHP port has no dependencies and is not getting one. Resolve it
// from the repository root so this works regardless of the cwd.
const require = createRequire(resolve(repoRoot, "package.json"));
let postgres;
try {
  postgres = require("postgres").default ?? require("postgres");
} catch (cause) {
  console.error(
    "Cannot load the `postgres` package.\n" +
      `  Looked in: ${resolve(repoRoot, "node_modules")}\n` +
      "  Fix: run `pnpm install` in the repository root (it is already a\n" +
      "  dependency of the Next.js app; nothing new is being added).",
  );
  console.error(String(cause));
  process.exit(2);
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

const args = new Map();
for (const raw of process.argv.slice(2)) {
  if (!raw.startsWith("--")) continue;
  const eq = raw.indexOf("=");
  if (eq === -1) args.set(raw.slice(2), "");
  else args.set(raw.slice(2, eq), raw.slice(eq + 1));
}

const url =
  args.get("url") ||
  process.env.DATABASE_URL ||
  "postgres://prometheus:prometheus@127.0.0.1:55432/speedat_dev";

const out = resolve(args.get("out") || resolve(phpRoot, "var/nextjs-dump.json"));
const pretty = args.has("pretty");

// ---------------------------------------------------------------------------
// The queries
// ---------------------------------------------------------------------------
//
// One entry per table the PHP app has a home for. The order is the order the
// exporter emits, which is the order the data has to be imported in - not
// because of foreign keys (there are none, on either engine) but because a
// half-finished import should leave the site able to serve: the published
// document first, then who may edit it, then the customer records.
//
// `utc(col)` is spelled out rather than hidden in a helper so that what reaches
// MySQL is visible in the query: Postgres converts to UTC, Postgres formats it,
// and the string that comes out is already a MySQL DATETIME literal.
//
// EVERY IDENTIFIER IS DOUBLE-QUOTED. Both schemas are full of words one engine
// or the other reserves - "key", "value", "rows", "by", "type", "count", "at",
// "source", "version". Quoting all of them is one rule instead of a list of
// exceptions to remember, and an unquoted `by AS by` is a syntax error waiting
// for the day somebody adds a column beside it.
const utc = (col) =>
  `to_char("${col}" AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')`;

const bool = (col) => `CASE WHEN "${col}" THEN '1' ELSE '0' END`;

/** @type {Array<{table: string, sql: string}>} */
const TABLES = [
  {
    // versions.id (a bigserial) is deliberately NOT selected. In MySQL the
    // version number IS the primary key, which is what the Postgres unique
    // index on `version` always meant.
    table: "versions",
    sql: `SELECT "version"::text        AS "version",
                 ${utc("published_at")} AS "published_at",
                 "published_by"         AS "published_by",
                 "source"               AS "source",
                 "summary"              AS "summary",
                 "change_count"::text   AS "change_count",
                 "data"::text           AS "data"
            FROM "versions"
        ORDER BY "version"`,
  },
  {
    table: "draft",
    sql: `SELECT "id"::text             AS "id",
                 "base_version"::text   AS "base_version",
                 "data"::text           AS "data",
                 ${utc("updated_at")}   AS "updated_at",
                 "updated_by"           AS "updated_by"
            FROM "draft"
        ORDER BY "id"`,
  },
  {
    // key -> k and value -> v: both are reserved in MySQL. Renamed here so the
    // exporter never has to remember which engine it is talking about.
    table: "app_settings",
    sql: `SELECT "key"                  AS "k",
                 "value"::text          AS "v",
                 ${utc("updated_at")}   AS "updated_at",
                 "updated_by"           AS "updated_by"
            FROM "app_settings"
        ORDER BY "key"`,
  },
  {
    // added_at -> created_at, and totp_secret -> totp_secret_enc. The Postgres
    // column is ALREADY the AES-256-GCM "iv.ct.tag" envelope (see
    // src/lib/auth/totp.ts), the same format php/app/Domain/Auth/SecretBox.php
    // reads, so it travels verbatim - no plaintext seed is ever in this dump.
    table: "admins",
    sql: `SELECT "email"                AS "email",
                 "name"                 AS "name",
                 "role"                 AS "role",
                 ${utc("added_at")}     AS "created_at",
                 "added_by"             AS "added_by",
                 "totp_secret"          AS "totp_secret_enc",
                 ${bool("totp_enabled")} AS "totp_enabled",
                 "totp_last_step"::text AS "totp_last_step"
            FROM "admins"
        ORDER BY "email"`,
  },
  {
    table: "leads",
    sql: `SELECT "id"::text             AS "id",
                 ${utc("created_at")}   AS "created_at",
                 ${utc("updated_at")}   AS "updated_at",
                 "kind"                 AS "kind",
                 "quote_id"             AS "quote_id",
                 "name"                 AS "name",
                 "phone"                AS "phone",
                 "email"                AS "email",
                 "message"              AS "message",
                 "dest_id"              AS "dest_id",
                 "weight_g"::text       AS "weight_g",
                 "status"               AS "status",
                 "notes"                AS "notes",
                 "updated_by"           AS "updated_by",
                 "ip_hash"              AS "ip_hash"
            FROM "leads"
        ORDER BY "id"`,
  },
  {
    table: "quotes",
    sql: `SELECT "id"                   AS "id",
                 ${utc("created_at")}   AS "created_at",
                 "version"::text        AS "version",
                 "dest_id"              AS "dest_id",
                 "service_id"           AS "service_id",
                 "type"                 AS "type",
                 "billable_g"::text     AS "billable_g",
                 "total"::text          AS "total",
                 "detail"::text         AS "detail",
                 ${bool("booked")}      AS "booked"
            FROM "quotes"
        ORDER BY "created_at", "id"`,
  },
  {
    table: "shipments",
    sql: `SELECT "id"                   AS "id",
                 ${utc("created_at")}   AS "created_at",
                 ${utc("updated_at")}   AS "updated_at",
                 "lead_id"::text        AS "lead_id",
                 "quote_id"             AS "quote_id",
                 "tracking_no"          AS "tracking_no",
                 "carrier"              AS "carrier",
                 "customer_name"        AS "customer_name",
                 "customer_phone"       AS "customer_phone",
                 "receiver_name"        AS "receiver_name",
                 "dest_id"              AS "dest_id",
                 "service_id"           AS "service_id",
                 "status"               AS "status",
                 "notes"                AS "notes",
                 "created_by"           AS "created_by"
            FROM "shipments"
        ORDER BY "created_at", "id"`,
  },
  {
    // "by" is reserved in MySQL. It keeps its name (so the data is a straight
    // copy) and the exporter writes it in backticks.
    table: "shipment_events",
    sql: `SELECT "id"::text             AS "id",
                 "shipment_id"          AS "shipment_id",
                 "status"               AS "status",
                 "note"                 AS "note",
                 ${utc("at")}           AS "at",
                 "by"                   AS "by"
            FROM "shipment_events"
        ORDER BY "id"`,
  },
  {
    // rows -> raw_rows (`rows` is reserved in MySQL 8).
    table: "imports",
    sql: `SELECT "id"::text             AS "id",
                 ${utc("received_at")}  AS "received_at",
                 "source"               AS "source",
                 "file_name"            AS "file_name",
                 "from_email"           AS "from_email",
                 "status"               AS "status",
                 "profile_signature"    AS "profile_signature",
                 "sheet_name"           AS "sheet_name",
                 "result"::text         AS "result",
                 "error"                AS "error",
                 "applied_version"::text AS "applied_version",
                 "decided_by"           AS "decided_by",
                 ${utc("decided_at")}   AS "decided_at",
                 "rows"::text           AS "raw_rows"
            FROM "imports"
        ORDER BY "id"`,
  },
  {
    // ip (the raw address) -> ip_hash. The address itself is NOT carried into
    // the new database: see the exporter, which hashes it with the new
    // application key or drops it.
    table: "audit_log",
    sql: `SELECT "id"::text             AS "id",
                 ${utc("at")}           AS "at",
                 "actor"                AS "actor",
                 "action"               AS "action",
                 "detail"::text         AS "detail",
                 "ip"                   AS "ip",
                 "user_agent"           AS "user_agent"
            FROM "audit_log"
        ORDER BY "id"`,
  },
  {
    // Included so the exporter can REPORT on it. It does not emit these rows
    // unless asked: the keys are salted with the old app's IP_HASH_SALT, so a
    // carried-over counter counts a visitor nobody can identify any more, in a
    // window that has long since closed.
    table: "rate_limits",
    sql: `SELECT "key"                  AS "k",
                 ${utc("window_start")} AS "window_start",
                 "count"::text          AS "count"
            FROM "rate_limits"
        ORDER BY "window_start", "key"`,
  },
];

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

const redactedUrl = url.replace(/\/\/([^:/@]+):[^@]*@/, "//$1:***@");
console.log(`source : ${redactedUrl}`);
console.log(`target : ${out}`);

const sql = postgres(url, {
  max: 1,
  idle_timeout: 5,
  // Do not let the driver "help": we asked for text and we want text.
  transform: { undefined: null },
  onnotice: () => {},
});

/** @type {Record<string, Array<Record<string, string|null>>>} */
const tables = {};
/** @type {Record<string, number>} */
const counts = {};
const missing = [];

let failed = false;

try {
  const { version } = (await sql`SELECT version() AS version`)[0];
  console.log(`server : ${version.split(",")[0]}`);
  console.log("");

  for (const { table, sql: query } of TABLES) {
    const exists = (
      await sql`SELECT to_regclass(${"public." + table}) IS NOT NULL AS ok`
    )[0].ok;

    if (!exists) {
      // A table the Next.js app never created is not an error - it is a schema
      // that predates a migration. Record it and carry on; the exporter reports
      // "not in dump" rather than "0 rows", which are different facts.
      missing.push(table);
      console.log(`  --   ${table.padEnd(16)} table does not exist`);
      continue;
    }

    const rows = await sql.unsafe(query);

    // Normalise to plain objects of string|null. postgres.js returns its own
    // row class; JSON.stringify would work on it, but an explicit map means the
    // dump's shape cannot change under us when the driver is upgraded.
    tables[table] = rows.map((row) => {
      /** @type {Record<string, string|null>} */
      const flat = {};
      for (const [key, value] of Object.entries(row)) {
        flat[key] = value === null || value === undefined ? null : String(value);
      }
      return flat;
    });

    counts[table] = tables[table].length;
    console.log(`  ok   ${table.padEnd(16)} ${String(counts[table]).padStart(7)} row(s)`);
  }
} catch (cause) {
  console.error("\nThe dump failed and nothing was written.");
  console.error(String(cause));
  failed = true;
} finally {
  await sql.end({ timeout: 5 });
}

if (failed) process.exit(1);

const dump = {
  // A version on the envelope, so the exporter can refuse a dump it does not
  // understand rather than mis-reading one.
  format: "speedat-nextjs-dump/1",
  generatedAt: new Date().toISOString().replace("T", " ").slice(0, 19) + " UTC",
  source: redactedUrl,
  missingTables: missing,
  counts,
  tables,
};

await mkdir(dirname(out), { recursive: true });
await writeFile(out, JSON.stringify(dump, null, pretty ? 2 : 0), "utf8");

const total = Object.values(counts).reduce((a, b) => a + b, 0);
const bytes = Buffer.byteLength(JSON.stringify(dump, null, pretty ? 2 : 0), "utf8");

console.log("");
console.log(`wrote  ${out}`);
console.log(`       ${total} row(s) across ${Object.keys(counts).length} table(s), ${(bytes / 1024).toFixed(1)} KiB`);
if (missing.length) console.log(`       tables absent from the source: ${missing.join(", ")}`);
console.log("");
console.log("Next:  /d/php/php.exe bin/export-from-nextjs.php --dump=" + out);
