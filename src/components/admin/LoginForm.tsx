"use client";

import { GoogleAuthProvider, signInWithEmailAndPassword, signInWithPopup, signOut } from "firebase/auth";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { clientAuth } from "@/lib/auth/firebase-client";

async function establishSession(idToken: string): Promise<string | null> {
  const res = await fetch("/api/auth/session", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ idToken }) });
  if (res.ok) return null;
  const body = (await res.json().catch(() => null)) as { error?: { message?: string } } | null;
  return body?.error?.message ?? "Sign-in failed";
}

export function LoginForm() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const finish = async (idToken: string) => {
    const err = await establishSession(idToken);
    if (err) {
      // Do not leave a Firebase session lying around for an account that is not an admin.
      await signOut(clientAuth()).catch(() => {});
      setError(err);
      setBusy(false);
      return;
    }
    router.replace("/admin");
    router.refresh();
  };

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
