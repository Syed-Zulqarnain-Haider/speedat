"use server";

import { z } from "zod";
import type { ActionResult } from "@/app/admin/actions";
import { audit } from "@/lib/audit";
import { Forbidden, requireAdmin, revokeAllSessions } from "@/lib/auth/session";
import { rateLimit } from "@/lib/limits";
import { beginEnrolment, disableTotp, enableTotp, TotpAlreadyEnabled, verifyCode } from "@/lib/auth/totp";

/**
 * Code attempts per account. The IP limit on sign-in does not cover these
 * actions, and someone holding a stolen session could otherwise brute-force
 * the 6-digit code (to confirm their own authenticator, or to switch the
 * owner's off). Keyed by email, so changing address does not reset it.
 */
async function codeAttemptsLeft(email: string): Promise<boolean> {
  return (await rateLimit("totp-action", email, 5, 15 * 60)).ok;
}

const TOO_MANY = { ok: false as const, code: "rate_limited", message: "Too many code attempts. Wait 15 minutes and try again." };

function onError(err: unknown): ActionResult<never> {
  if (err instanceof Forbidden) return { ok: false, code: "forbidden", message: err.message };
  if (err instanceof Error && /not set/.test(err.message)) return { ok: false, code: "unconfigured", message: "Set APP_SECRET (or TOTP_ENCRYPTION_KEY) on the server before enrolling two-factor." };
  console.error(err);
  return { ok: false, code: "error", message: "Something went wrong. Try again." };
}

export async function beginTotpAction(): Promise<ActionResult<{ uri: string; qrDataUrl: string; secret: string }>> {
  try {
    const user = await requireAdmin();
    const r = await beginEnrolment(user.email);
    await audit(user.email, "totp_enrol_started", {});
    return { ok: true, ...r };
  } catch (err) {
    if (err instanceof TotpAlreadyEnabled) {
      return { ok: false, code: "already_enabled", message: "Two-factor is already on for this account. To set up a new authenticator, turn it off first with a current code." };
    }
    return onError(err);
  }
}

export async function confirmTotpAction(input: unknown): Promise<ActionResult<{ signedOut: boolean }>> {
  try {
    const user = await requireAdmin();
    const { code } = z.object({ code: z.string().max(12) }).parse(input);
    if (!(await codeAttemptsLeft(user.email))) return TOO_MANY;
    if (!(await verifyCode(user.email, code))) return { ok: false, code: "bad_code", message: "That code did not match. Check the time on your phone and try the next code." };
    await enableTotp(user.email);
    // Sessions opened before this point never passed a code; end them all so
    // every device (this one too) signs in again with the new second factor.
    const revoked = await revokeAllSessions(user.email);
    await audit(user.email, "totp_enabled", { sessionsRevoked: revoked });
    return { ok: true, signedOut: revoked };
  } catch (err) {
    return onError(err);
  }
}

export async function disableTotpAction(input: unknown): Promise<ActionResult> {
  try {
    const user = await requireAdmin();
    const { code } = z.object({ code: z.string().max(12) }).parse(input);
    if (!(await codeAttemptsLeft(user.email))) return TOO_MANY;
    if (!(await verifyCode(user.email, code))) return { ok: false, code: "bad_code", message: "Enter a current code from your authenticator to turn it off." };
    await disableTotp(user.email);
    await audit(user.email, "totp_disabled", {});
    return { ok: true };
  } catch (err) {
    return onError(err);
  }
}
