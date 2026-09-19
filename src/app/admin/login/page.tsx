import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { LoginForm } from "@/components/admin/LoginForm";
import { devBypassEmail, getAdminUser } from "@/lib/auth/session";
import { firebaseConfigured } from "@/lib/auth/firebase-admin";

export const metadata: Metadata = { title: "Sign in", robots: { index: false } };

// Reads the session cookie: never prerender.
export const dynamic = "force-dynamic";

export default async function LoginPage() {
  if (await getAdminUser()) redirect("/admin");
  const dev = devBypassEmail();
  const serverReady = firebaseConfigured();
  const webReady = !!(process.env.NEXT_PUBLIC_FIREBASE_API_KEY && process.env.NEXT_PUBLIC_FIREBASE_AUTH_DOMAIN && process.env.NEXT_PUBLIC_FIREBASE_PROJECT_ID);
  return (
    <div className="wrap" style={{ maxWidth: 460 }}>
      <section className="page">
        <h1>Rates admin</h1>
        <p className="lede">Sign in with the account that was added as an admin.</p>
        {dev ? (
          <div className="notice warn">
            Development bypass is on for <strong>{dev}</strong>, but that address is not in the admins table. Run{" "}
            <code>ADMIN_EMAIL={dev} pnpm db:seed</code>.
          </div>
        ) : !serverReady || !webReady ? (
          <div className="notice warn">
            Sign-in is not configured on this deployment yet: {!webReady ? "the public Firebase web config" : ""}
            {!webReady && !serverReady ? " and " : ""}
            {!serverReady ? "the server service account" : ""} {!webReady && !serverReady ? "are" : "is"} missing. See <code>.env.example</code>.
          </div>
        ) : (
          <LoginForm />
        )}
        <p style={{ marginTop: 20 }}>
          <Link href="/">Back to the website</Link>
        </p>
      </section>
    </div>
  );
}
