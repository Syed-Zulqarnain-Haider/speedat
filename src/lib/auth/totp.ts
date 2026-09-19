/**
 * App-level TOTP second factor for admins (works on any Firebase plan).
 * Secrets are stored encrypted (AES-256-GCM) with TOTP_ENCRYPTION_KEY —
 * APP_SECRET as a fallback — and a code is accepted once per time step.
 */
import "server-only";
import { createCipheriv, createDecipheriv, createHash, randomBytes } from "node:crypto";
import { eq } from "drizzle-orm";
import * as OTPAuth from "otpauth";
import QRCode from "qrcode";
import { db, schema } from "@/lib/db";

const ISSUER = "Speedat Admin";

function key(): Buffer {
  const raw = process.env.TOTP_ENCRYPTION_KEY || process.env.APP_SECRET;
  if (!raw) throw new Error("TOTP_ENCRYPTION_KEY (or APP_SECRET) is not set");
  return createHash("sha256").update(raw).digest();
}

export function encryptSecret(plain: string): string {
  const iv = randomBytes(12);
  const cipher = createCipheriv("aes-256-gcm", key(), iv);
  const enc = Buffer.concat([cipher.update(plain, "utf8"), cipher.final()]);
  return [iv.toString("base64"), enc.toString("base64"), cipher.getAuthTag().toString("base64")].join(".");
}

export function decryptSecret(stored: string): string {
  const [iv, enc, tag] = stored.split(".");
  if (!iv || !enc || !tag) throw new Error("bad secret format");
  const decipher = createDecipheriv("aes-256-gcm", key(), Buffer.from(iv, "base64"));
  decipher.setAuthTag(Buffer.from(tag, "base64"));
  return Buffer.concat([decipher.update(Buffer.from(enc, "base64")), decipher.final()]).toString("utf8");
}

function totpFor(secret: string, email: string): OTPAuth.TOTP {
  return new OTPAuth.TOTP({ issuer: ISSUER, label: email, algorithm: "SHA1", digits: 6, period: 30, secret: OTPAuth.Secret.fromBase32(secret) });
}

/** Start enrolment: a new (not yet enabled) secret, its otpauth URI and a QR image. */
export async function beginEnrolment(email: string): Promise<{ uri: string; qrDataUrl: string; secret: string }> {
  const secret = new OTPAuth.Secret({ size: 20 }).base32;
  const uri = totpFor(secret, email).toString();
  const qrDataUrl = await QRCode.toDataURL(uri, { margin: 1, width: 220 });
  await db.update(schema.admins).set({ totpSecret: encryptSecret(secret), totpEnabled: false, totpLastStep: null }).where(eq(schema.admins.email, email));
  return { uri, qrDataUrl, secret };
}

/** Verify a code against the stored secret; on success, remember the time step so it cannot be replayed. */
export async function verifyCode(email: string, code: string): Promise<boolean> {
  const [row] = await db.select().from(schema.admins).where(eq(schema.admins.email, email)).limit(1);
  if (!row?.totpSecret) return false;
  const token = code.replace(/\s+/g, "");
  if (!/^\d{6}$/.test(token)) return false;
  const totp = totpFor(decryptSecret(row.totpSecret), email);
  const delta = totp.validate({ token, window: 1 });
  if (delta == null) return false;
  const step = Math.floor(Date.now() / 1000 / 30) + delta;
  if (row.totpLastStep != null && step <= row.totpLastStep) return false;
  await db.update(schema.admins).set({ totpLastStep: step }).where(eq(schema.admins.email, email));
  return true;
}

export async function enableTotp(email: string): Promise<void> {
  await db.update(schema.admins).set({ totpEnabled: true }).where(eq(schema.admins.email, email));
}

export async function disableTotp(email: string): Promise<void> {
  await db.update(schema.admins).set({ totpEnabled: false, totpSecret: null, totpLastStep: null }).where(eq(schema.admins.email, email));
}

export async function totpStatus(email: string): Promise<{ enabled: boolean; pending: boolean }> {
  const [row] = await db.select({ enabled: schema.admins.totpEnabled, secret: schema.admins.totpSecret }).from(schema.admins).where(eq(schema.admins.email, email)).limit(1);
  return { enabled: !!row?.enabled, pending: !!row?.secret && !row?.enabled };
}
