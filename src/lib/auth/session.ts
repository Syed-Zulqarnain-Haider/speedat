/**
 * Who is using the admin. Identity comes from a Firebase session cookie;
 * authorisation comes from the `admins` table — a valid Google account that
 * is not listed gets nothing. In development only, DEV_ADMIN_EMAIL lets the
 * admin be opened without Firebase, and even then the address must exist in
 * `admins`. NODE_ENV is "production" on every real build, and the bypass
 * also refuses any host that looks deployed (see `devBypassAllowed`), so it
 * cannot activate there even if a build were run in development mode.
 */
import "server-only";
import { eq } from "drizzle-orm";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { db, schema } from "@/lib/db";
import { adminAuth, firebaseConfigured } from "./firebase-admin";

export const SESSION_COOKIE = "speedat_session";
export const SESSION_DAYS = 5;

export type AdminRole = "owner" | "editor";

export interface AdminUser {
  email: string;
  name: string;
  role: AdminRole;
}

export async function lookupAdmin(email: string): Promise<AdminUser | null> {
  const e = email.trim().toLowerCase();
  if (!e) return null;
  const [row] = await db.select().from(schema.admins).where(eq(schema.admins.email, e)).limit(1);
  if (!row) return null;
  return { email: row.email, name: row.name, role: row.role === "owner" ? "owner" : "editor" };
}

/**
 * Whether the DEV_ADMIN_EMAIL bypass may run in this environment. NODE_ENV
 * alone is one misconfigured flag away from an open admin, so any sign of a
 * real deployment vetoes it too: a Vercel preview or production runtime
 * (VERCEL_ENV other than "development"), or an explicit APP_ENV/ENVIRONMENT
 * naming production or staging. Pure, for tests.
 */
export function devBypassAllowed(env: Record<string, string | undefined> = process.env): boolean {
  if (env.NODE_ENV !== "development") return false;
  if (env.VERCEL_ENV && env.VERCEL_ENV !== "development") return false;
  for (const name of [env.APP_ENV, env.ENVIRONMENT]) {
    if (name && /^(prod|production|staging|preview)$/i.test(name.trim())) return false;
  }
  return true;
}

let warnedBypass = false;

export function devBypassEmail(env: Record<string, string | undefined> = process.env): string | null {
  const email = env.DEV_ADMIN_EMAIL?.trim().toLowerCase() || null;
  if (!email) return null;
  if (!devBypassAllowed(env)) {
    if (!warnedBypass && env.NODE_ENV !== "test") {
      warnedBypass = true;
      console.warn("[auth] DEV_ADMIN_EMAIL is set but ignored: this is not a local development environment");
    }
    return null;
  }
  return email;
}

/**
 * Sign the account out everywhere by revoking its Firebase refresh tokens;
 * session cookies are verified with `checkRevoked`, so every cookie issued
 * before now stops working (the caller's own included — Firebase revokes per
 * user, not per session). Used when two-factor is turned on, so a session
 * opened without a code does not outlive the change. Returns false, without
 * throwing, when Firebase is not configured (the dev bypass) or the call fails.
 */
export async function revokeAllSessions(email: string): Promise<boolean> {
  if (!firebaseConfigured()) return false;
  try {
    const user = await adminAuth().getUserByEmail(email);
    await adminAuth().revokeRefreshTokens(user.uid);
    return true;
  } catch (err) {
    console.error("[auth] could not revoke sessions", err);
    return false;
  }
}

/** The signed-in admin, or null. Never throws for a missing/expired cookie. */
export async function getAdminUser(): Promise<AdminUser | null> {
  const dev = devBypassEmail();
  if (dev) return lookupAdmin(dev);
  if (!firebaseConfigured()) return null;
  const jar = await cookies();
  const cookie = jar.get(SESSION_COOKIE)?.value;
  if (!cookie) return null;
  try {
    const decoded = await adminAuth().verifySessionCookie(cookie, true);
    if (!decoded.email || !decoded.email_verified) return null;
    return lookupAdmin(decoded.email);
  } catch {
    return null;
  }
}

/** For pages: redirect to the login screen when not signed in. */
export async function requireAdminPage(): Promise<AdminUser> {
  const user = await getAdminUser();
  if (!user) redirect("/admin/login");
  return user;
}

export class Forbidden extends Error {
  constructor(msg = "Not allowed") {
    super(msg);
  }
}

/** For server actions and route handlers: throw instead of redirecting. */
export async function requireAdmin(role?: AdminRole): Promise<AdminUser> {
  const user = await getAdminUser();
  if (!user) throw new Forbidden("Sign in to continue");
  if (role === "owner" && user.role !== "owner") throw new Forbidden("Only an owner can do that");
  return user;
}
