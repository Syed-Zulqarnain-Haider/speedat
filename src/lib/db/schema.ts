/**
 * Database schema (Drizzle). The site document is stored as an immutable
 * JSONB snapshot per publish — the calculator needs the whole card at once and
 * the admin edits it as a whole, so normalising rates into rows would only add
 * joins. History, diffs and restores all work on snapshots.
 */
import { sql } from "drizzle-orm";
import {
  bigserial,
  boolean,
  index,
  integer,
  jsonb,
  pgTable,
  primaryKey,
  text,
  timestamp,
  uniqueIndex,
} from "drizzle-orm/pg-core";
import type { SiteData } from "@/lib/site/types";
import type { ImportResult } from "@/lib/import/types";

/** Every publish. INSERT-only; the site serves the highest `version`. */
export const versions = pgTable(
  "versions",
  {
    id: bigserial("id", { mode: "number" }).primaryKey(),
    version: integer("version").notNull(),
    publishedAt: timestamp("published_at", { withTimezone: true }).notNull().defaultNow(),
    publishedBy: text("published_by").notNull(),
    /** "admin" | "import:<file>" | "email:<from>" | "restore:<n>" | "seed" */
    source: text("source").notNull(),
    summary: text("summary").notNull(),
    changeCount: integer("change_count").notNull().default(0),
    data: jsonb("data").$type<SiteData>().notNull(),
  },
  (t) => [uniqueIndex("versions_version_idx").on(t.version)],
);

/** The single working copy the admin edits. Row id is always 1. */
export const draft = pgTable("draft", {
  id: integer("id").primaryKey(),
  /** Version the draft was branched from; publishing when a newer version exists is a conflict. */
  baseVersion: integer("base_version").notNull(),
  data: jsonb("data").$type<SiteData>().notNull(),
  updatedAt: timestamp("updated_at", { withTimezone: true }).notNull().defaultNow(),
  updatedBy: text("updated_by").notNull(),
});

/** Who may open the admin. Identity comes from Firebase; authorisation comes from here. */
export const admins = pgTable("admins", {
  email: text("email").primaryKey(),
  name: text("name").notNull().default(""),
  /** "owner" publishes and manages admins; "editor" edits the draft and stages imports. */
  role: text("role").notNull().default("editor"),
  addedAt: timestamp("added_at", { withTimezone: true }).notNull().defaultNow(),
  addedBy: text("added_by").notNull().default(""),
});

/** A rate sheet that arrived (upload or email), what we made of it, and what happened to it. */
export const imports = pgTable(
  "imports",
  {
    id: bigserial("id", { mode: "number" }).primaryKey(),
    receivedAt: timestamp("received_at", { withTimezone: true }).notNull().defaultNow(),
    /** "upload" | "email" */
    source: text("source").notNull(),
    fileName: text("file_name").notNull(),
    fromEmail: text("from_email"),
    /** "needs_mapping" | "ready" | "applied" | "published" | "rejected" | "failed" */
    status: text("status").notNull(),
    profileSignature: text("profile_signature"),
    sheetName: text("sheet_name"),
    result: jsonb("result").$type<ImportResult>(),
    error: text("error"),
    appliedVersion: integer("applied_version"),
    decidedBy: text("decided_by"),
    decidedAt: timestamp("decided_at", { withTimezone: true }),
    /** Raw workbook rows, kept so the admin can re-map without re-uploading. */
    rows: jsonb("rows").$type<Record<string, string[][]>>(),
  },
  (t) => [index("imports_status_idx").on(t.status, t.receivedAt)],
);

/** Quotes customers generated on the site. Written fire-and-forget; never blocks a price. */
export const quotes = pgTable(
  "quotes",
  {
    id: text("id").primaryKey(),
    createdAt: timestamp("created_at", { withTimezone: true }).notNull().defaultNow(),
    version: integer("version").notNull(),
    destId: text("dest_id").notNull(),
    serviceId: text("service_id").notNull(),
    /** "pkg" | "doc" */
    type: text("type").notNull(),
    billableG: integer("billable_g").notNull(),
    total: integer("total").notNull(),
    /** Pieces, add-ons, ETA and whether the WhatsApp button was pressed. */
    detail: jsonb("detail").$type<Record<string, unknown>>().notNull(),
    booked: boolean("booked").notNull().default(false),
  },
  (t) => [index("quotes_created_idx").on(t.createdAt)],
);

/** Append-only. The application role has INSERT and SELECT only. */
export const auditLog = pgTable(
  "audit_log",
  {
    id: bigserial("id", { mode: "number" }).primaryKey(),
    at: timestamp("at", { withTimezone: true }).notNull().defaultNow(),
    actor: text("actor").notNull(),
    action: text("action").notNull(),
    detail: jsonb("detail").$type<Record<string, unknown>>().notNull().default(sql`'{}'::jsonb`),
    ip: text("ip"),
    userAgent: text("user_agent"),
  },
  (t) => [index("audit_log_at_idx").on(t.at)],
);

/** Operational key/value settings that are not part of the published document (intake mailbox, tolerances). */
export const appSettings = pgTable(
  "app_settings",
  {
    key: text("key").notNull(),
    value: jsonb("value").$type<unknown>().notNull(),
    updatedAt: timestamp("updated_at", { withTimezone: true }).notNull().defaultNow(),
    updatedBy: text("updated_by").notNull().default(""),
  },
  (t) => [primaryKey({ columns: [t.key] })],
);
