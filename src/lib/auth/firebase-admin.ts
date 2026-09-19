/**
 * Server-side Firebase (token and session-cookie verification). The service
 * account is read from FIREBASE_SERVICE_ACCOUNT_B64 — the JSON key file,
 * base64-encoded — which lives in the host's secret store, never in the repo.
 */
import "server-only";
import { cert, getApps, initializeApp, type App } from "firebase-admin/app";
import { getAuth, type Auth } from "firebase-admin/auth";

let app: App | null = null;

export function firebaseConfigured(): boolean {
  return !!process.env.FIREBASE_SERVICE_ACCOUNT_B64;
}

function getApp(): App {
  if (app) return app;
  const existing = getApps()[0];
  if (existing) return (app = existing);
  const b64 = process.env.FIREBASE_SERVICE_ACCOUNT_B64;
  if (!b64) throw new Error("FIREBASE_SERVICE_ACCOUNT_B64 is not set; admin sign-in is unavailable");
  let json: { project_id: string; client_email: string; private_key: string };
  try {
    json = JSON.parse(Buffer.from(b64, "base64").toString("utf8"));
  } catch {
    throw new Error("FIREBASE_SERVICE_ACCOUNT_B64 is not valid base64 JSON");
  }
  app = initializeApp({ credential: cert({ projectId: json.project_id, clientEmail: json.client_email, privateKey: json.private_key }) });
  return app;
}

export function adminAuth(): Auth {
  return getAuth(getApp());
}
