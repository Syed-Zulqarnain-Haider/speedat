/**
 * Who is using the admin. Identity comes from a Firebase session cookie;
 * authorisation comes from the `admins` table — a valid Google account that
 * is not listed gets nothing. In development only, DEV_ADMIN_EMAIL lets the
 * admin be opened without Firebase, and even then the address must exist in
 * `admins`. NODE_ENV is "production" on every real build, so the bypass
 * cannot activate there.
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

export function devBypassEmail(): string | null {
  if (process.env.NODE_ENV !== "development") return null;
  return process.env.DEV_ADMIN_EMAIL?.trim().toLowerCase() || null;
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
