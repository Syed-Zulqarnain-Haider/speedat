/**
 * Database client. One postgres.js pool per process; in development the
 * instance is cached on `globalThis` so Next's hot reload does not open a
 * new pool on every save. Prepared statements are off because Neon's pooled
 * endpoint (PgBouncer, transaction mode) does not support them.
 */
import { drizzle } from "drizzle-orm/postgres-js";
import postgres from "postgres";
import * as schema from "./schema";

function connectionString(): string {
  const url = process.env.DATABASE_URL;
  if (!url) throw new Error("DATABASE_URL is not set");
  return url;
}

function create() {
  const client = postgres(connectionString(), {
    prepare: false,
    max: process.env.NODE_ENV === "production" ? 5 : 3,
    idle_timeout: 20,
    connect_timeout: 10,
  });
  return drizzle(client, { schema });
}

type Db = ReturnType<typeof create>;

const g = globalThis as unknown as { __speedatDb?: Db };

export const db: Db = g.__speedatDb ?? create();
if (process.env.NODE_ENV !== "production") g.__speedatDb = db;

export { schema };
