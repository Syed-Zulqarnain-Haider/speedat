/**
 * POST  {idToken} → verifies the Firebase ID token, checks the admins table,
 *                   sets the session cookie. Unknown accounts get 403 and no cookie.
 * DELETE          → clears the cookie and revokes the account's refresh tokens.
 */
import { cookies } from "next/headers";
import { NextResponse } from "next/server";
import { z } from "zod";
import { audit } from "@/lib/audit";
import { adminAuth, firebaseConfigured } from "@/lib/auth/firebase-admin";
import { SESSION_COOKIE, SESSION_DAYS, lookupAdmin } from "@/lib/auth/session";
import { totpStatus, verifyCode } from "@/lib/auth/totp";
import { clientIp, rateLimit } from "@/lib/limits";

const Body = z.object({ idToken: z.string().min(20).max(4096), code: z.string().max(12).optional() });

const cookieOpts = {
  httpOnly: true,
  secure: process.env.NODE_ENV === "production",
  sameSite: "lax" as const,
  path: "/",
};

export async function POST(req: Request) {
  if (!firebaseConfigured()) return NextResponse.json({ error: { code: "unavailable", message: "Sign-in is not configured" } }, { status: 503 });
  const limit = await rateLimit("login", await clientIp(), 20, 15 * 60);
  if (!limit.ok) return NextResponse.json({ error: { code: "rate_limited", message: "Too many sign-in attempts. Try again in a few minutes." } }, { status: 429 });
  let idToken: string;
  let code: string | undefined;
  try {
    ({ idToken, code } = Body.parse(await req.json()));
  } catch {
    return NextResponse.json({ error: { code: "bad_request", message: "Invalid request" } }, { status: 400 });
  }
  let email: string | undefined;
  try {
    const decoded = await adminAuth().verifyIdToken(idToken, true);
    if (!decoded.email_verified) return NextResponse.json({ error: { code: "unverified", message: "Verify your email address first" } }, { status: 403 });
    email = decoded.email;
  } catch {
    return NextResponse.json({ error: { code: "invalid_token", message: "Sign-in failed" } }, { status: 401 });
  }
  const admin = email ? await lookupAdmin(email) : null;
  if (!admin) {
    await audit(email ?? "unknown", "login_denied", {});
    return NextResponse.json({ error: { code: "not_admin", message: "This account is not an admin" } }, { status: 403 });
  }
  const totp = await totpStatus(admin.email);
  if (totp.enabled) {
    if (!code) return NextResponse.json({ mfa: true, message: "Enter the code from your authenticator app" }, { status: 401 });
    if (!(await verifyCode(admin.email, code))) {
      await audit(admin.email, "login_mfa_failed", {});
      return NextResponse.json({ mfa: true, error: { code: "bad_code", message: "That code did not match" } }, { status: 401 });
    }
  }
  const expiresIn = SESSION_DAYS * 24 * 60 * 60 * 1000;
  const session = await adminAuth().createSessionCookie(idToken, { expiresIn });
  const jar = await cookies();
  jar.set(SESSION_COOKIE, session, { ...cookieOpts, maxAge: expiresIn / 1000 });
  await audit(admin.email, "login", { role: admin.role, mfa: totp.enabled });
  return NextResponse.json({ ok: true, role: admin.role });
}

export async function DELETE() {
  const jar = await cookies();
  const cookie = jar.get(SESSION_COOKIE)?.value;
  jar.set(SESSION_COOKIE, "", { ...cookieOpts, maxAge: 0 });
  if (cookie && firebaseConfigured()) {
    try {
      const decoded = await adminAuth().verifySessionCookie(cookie);
      await adminAuth().revokeRefreshTokens(decoded.sub);
      await audit(decoded.email ?? decoded.sub, "logout", {});
    } catch {
      /* already invalid */
    }
  }
  return NextResponse.json({ ok: true });
}
