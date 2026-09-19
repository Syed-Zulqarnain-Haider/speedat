"use client";

import { useState } from "react";
import { beginTotpAction, confirmTotpAction, disableTotpAction } from "@/app/admin/security/actions";
import { Toast, useToast } from "@/components/calculator/Toast";

export function TotpSetup({ enabled: initialEnabled }: { enabled: boolean }) {
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
    if (!res.ok) return setError(res.message);
    setEnrol({ uri: res.uri, qrDataUrl: res.qrDataUrl, secret: res.secret });
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
  };

  return (
    <div className="panel" style={{ paddingBottom: 16 }}>
      <h2 className="step">Two-factor sign-in</h2>
      <p className="meta" style={{ marginBottom: 12 }}>
        {enabled ? "On: after your Google or password sign-in, a 6-digit code from your authenticator app is required." : "Off: your account signs in with Google or a password only."}
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
          <p>Scan this with Google Authenticator, Authy or 1Password, then enter the code it shows.</p>
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img src={enrol.qrDataUrl} alt="QR code for the authenticator app" width={220} height={220} style={{ display: "block", margin: "12px 0", borderRadius: 8, background: "#fff" }} />
          <details style={{ marginBottom: 12 }}>
            <summary className="hint">Cannot scan? Enter the key by hand</summary>
            <code style={{ wordBreak: "break-all" }}>{enrol.secret}</code>
          </details>
          <div className="inline">
            <label className="field" style={{ maxWidth: 200 }}>
              <span>6-digit code</span>
              <input type="text" inputMode="numeric" autoComplete="one-time-code" maxLength={7} value={code} onChange={(e) => setCode(e.target.value)} />
            </label>
            <button className="btn primary" type="button" disabled={busy || code.replace(/\s/g, "").length < 6} onClick={confirm}>
              Turn on
            </button>
            <button className="btn" type="button" disabled={busy} onClick={() => setEnrol(null)}>
              Cancel
            </button>
          </div>
        </>
      ) : null}
      {enabled ? (
        <div className="inline">
          <label className="field" style={{ maxWidth: 200 }}>
            <span>Current code to turn off</span>
            <input type="text" inputMode="numeric" autoComplete="one-time-code" maxLength={7} value={code} onChange={(e) => setCode(e.target.value)} />
          </label>
          <button className="btn danger" type="button" disabled={busy || code.replace(/\s/g, "").length < 6} onClick={disable}>
            Turn off two-factor
          </button>
        </div>
      ) : null}
      <Toast message={toast} />
    </div>
  );
}
