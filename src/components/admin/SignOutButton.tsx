"use client";

import { signOut } from "firebase/auth";
import { useRouter } from "next/navigation";
import { useState } from "react";
import { clientAuth, firebaseWebConfigured } from "@/lib/auth/firebase-client";

export function SignOutButton() {
  const router = useRouter();
  const [busy, setBusy] = useState(false);
  const out = async () => {
    setBusy(true);
    await fetch("/api/auth/session", { method: "DELETE" }).catch(() => {});
    if (firebaseWebConfigured()) await signOut(clientAuth()).catch(() => {});
    router.replace("/admin/login");
    router.refresh();
  };
  return (
    <button className="btn small" type="button" onClick={out} disabled={busy}>
      Sign out
    </button>
  );
}
