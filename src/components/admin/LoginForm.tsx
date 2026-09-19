"use client";

import { GoogleAuthProvider, signInWithEmailAndPassword, signInWithPopup, signOut } from "firebase/auth";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { clientAuth } from "@/lib/auth/firebase-client";

type SessionResult = { ok: true } | { ok: false; mfa: boolean; message: string };

async function establishSession(idToken: string, code?: string): Promise<SessionResult> {
  const res = await fetch("/api/auth/session", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ idToken, code }) });
  if (res.ok) return { ok: true };
  const body = (await res.json().catch(() => null)) as { mfa?: boolean; message?: string; error?: { message?: string } } | null;
  return { ok: false, mfa: !!body?.mfa, message: body?.error?.message ?? body?.message ?? "Sign-in failed" };
}

export function LoginForm() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Set when the server wants a second factor; the Firebase token is kept in memory only until then.
  const [mfaToken, setMfaToken] = useState<string | null>(null);
  const [code, setCode] = useState("");

  const finish = async (idToken: string, otp?: string) => {
    const r = await establishSession(idToken, otp);
    if (r.ok) {
      router.replace("/admin");
      router.refresh();
      return;
    }
    if (r.mfa) {
      setMfaToken(idToken);
      setError(otp ? r.message : null);
      setBusy(false);
      return;
    }
    // Do not leave a Firebase session lying around for an account that is not an admin.
    await signOut(clientAuth()).catch(() => {});
    setError(r.message);
    setBusy(false);
  };

  if (mfaToken) {
    return (
      <form
        className="panel"
        style={{ paddingBottom: 18 }}
        onSubmit={(e) => {
          e.preventDefault();
          setBusy(true);
          void finish(mfaToken, code);
        }}
      >
        <p style={{ marginBottom: 12 }}>Enter the 6-digit code from your authenticator app.</p>
        <label className="field">
          <span>Code</span>
          <input type="text" inputMode="numeric" autoComplete="one-time-code" maxLength={7} value={code} onChange={(e) => setCode(e.target.value)} autoFocus />
        </label>
        <button className="btn primary" type="submit" disabled={busy || code.replace(/\s/g, "").length < 6} style={{ width: "100%" }}>
          Continue
        </button>
        {error ? (
          <div className="notice err" role="alert">
            {error}
          </div>
        ) : null}
      </form>
    );
  }

  const withGoogle = async () => {
    setBusy(true);
    setError(null);
    try {
      const cred = await signInWithPopup(clientAuth(), new GoogleAuthProvider());
      await finish(await cred.user.getIdToken());
    } catch (e) {
      setError(e instanceof Error ? e.message : "Sign-in failed");
      setBusy(false);
    }
  };

  const withPassword = async (ev: React.FormEvent) => {
    ev.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const cred = await signInWithEmailAndPassword(clientAuth(), email.trim(), password);
      await finish(await cred.user.getIdToken());
    } catch {
      setError("Email or password is not right");
      setBusy(false);
    }
  };

  return (
    <div className="panel" style={{ paddingBottom: 18 }}>
      <button className="btn primary" type="button" onClick={withGoogle} disabled={busy} style={{ width: "100%" }}>
        Continue with Google
      </button>
      <p className="hint" style={{ textAlign: "center", margin: "12px 0" }}>
        or with email and password
      </p>
      <form onSubmit={withPassword}>
        <label className="field">
          <span>Email</span>
          <input type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </label>
        <label className="field">
          <span>Password</span>
          <input type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
        </label>
        <button className="btn" type="submit" disabled={busy} style={{ width: "100%" }}>
          Sign in
        </button>
      </form>
      {error ? (
        <div className="notice err" role="alert">
          {error}
        </div>
      ) : null}
    </div>
  );
}
