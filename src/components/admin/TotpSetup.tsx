"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { beginTotpAction, confirmTotpAction, disableTotpAction } from "@/app/admin/security/actions";
import { Toast, useToast } from "@/components/calculator/Toast";

export function TotpSetup({ enabled: initialEnabled }: { enabled: boolean }) {
  const router = useRouter();
  const [enabled, setEnabled] = useState(initialEnabled);
  const [enrol, setEnrol] = useState<{ uri: string; qrDataUrl: string; secret: string } | null>(null);
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [toast, showToast] = useToast();

  const begin = async () => {
    setBusy(true);
    setError(null);
    const res = await beginTotpAction();
    setBusy(false);
    if (!res.ok) {
      // A stale tab: two-factor was turned on elsewhere after this page loaded.
      if (res.code === "already_enabled") {
        setEnabled(true);
        router.refresh();
      }
      return setError(res.message);
    }
    setEnrol({ uri: res.uri, qrDataUrl: res.qrDataUrl, secret: res.secret });
    router.refresh(); // Recent activity now has totp_enrol_started
  };
  const confirm = async () => {
    setBusy(true);
    setError(null);
    const res = await confirmTotpAction({ code });
    setBusy(false);
    if (!res.ok) return setError(res.message);
    setEnabled(true);
    setEnrol(null);
    setCode("");
    showToast("Two-factor sign-in is on");
    // The Admins list ("2FA on") and Recent activity are rendered on the server; re-read them
    // now so the page agrees with the pill, rather than on the next reload. This component's
    // own state (pill, toast) is client state and survives the refresh.
    router.refresh();
  };
  const disable = async () => {
    setBusy(true);
    setError(null);
    const res = await disableTotpAction({ code });
    setBusy(false);
    if (!res.ok) return setError(res.message);
    setEnabled(false);
    setCode("");
    showToast("Two-factor sign-in is off");
    router.refresh();
  };

  return (
    <div className="panel totp">
      <h2 className="step">Two-factor sign-in</h2>
      <p className="totp-state">
        <span className={`pill ${enabled ? "live" : ""}`}>{enabled ? "on" : "off"}</span>
        <span className="meta">
          {enabled ? "After your Google or password sign-in, a 6-digit code from your authenticator app is required." : "Your account signs in with Google or a password only."}
        </span>
      </p>
      {error ? (
        <div className="notice err" role="alert">
          {error}
        </div>
      ) : null}
      {!enabled && !enrol ? (
        <button className="btn primary" type="button" disabled={busy} onClick={begin}>
          Set up an authenticator app
        </button>
      ) : null}
      {!enabled && enrol ? (
        <>
          <div className="card qr">
            <p className="hint">Scan this with Google Authenticator, Authy or 1Password, then enter the code it shows.</p>
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src={enrol.qrDataUrl} alt="QR code for the authenticator app" width={200} height={200} />
            <details className="fold">
              <summary>Cannot scan? Enter the key by hand</summary>
              <code className="secret">{enrol.secret}</code>
            </details>
          </div>
          <div className="inline">
            <label className="field code-field">
              <span>6-digit code</span>
              <input type="text" className="code" inputMode="numeric" autoComplete="one-time-code" maxLength={7} value={code} onChange={(e) => setCode(e.target.value)} />
            </label>
            <button className="btn primary" type="button" disabled={busy || code.replace(/\s/g, "").length < 6} onClick={confirm}>
              Turn on
            </button>
            <button className="btn outline" type="button" disabled={busy} onClick={() => setEnrol(null)}>
              Cancel
            </button>
          </div>
        </>
      ) : null}
      {enabled ? (
        <div className="inline">
          <label className="field code-field">
            <span>Current code to turn off</span>
            <input type="text" className="code" inputMode="numeric" autoComplete="one-time-code" maxLength={7} value={code} onChange={(e) => setCode(e.target.value)} />
          </label>
          <button className="btn danger outline" type="button" disabled={busy || code.replace(/\s/g, "").length < 6} onClick={disable}>
            Turn off two-factor
          </button>
        </div>
      ) : null}
      <Toast message={toast} />
    </div>
  );
}
