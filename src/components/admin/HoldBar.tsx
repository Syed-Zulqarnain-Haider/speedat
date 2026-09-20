"use client";

/**
 * The price hold switch. One button takes every price off the website
 * immediately; the owner puts them back here or by publishing new rates.
 * The draft is untouched either way — that is the whole point.
 */
import { useState } from "react";
import { setHoldAction } from "@/app/admin/actions";
import { fmtDateTime } from "@/lib/pricing/format";
import { HOLD_MESSAGE_MAX, type Hold } from "@/lib/site/hold-shared";

interface Props {
  hold: Hold;
  isOwner: boolean;
  onChange: (hold: Hold) => void;
  toast: (msg: string) => void;
}

export function HoldBar({ hold, isOwner, onChange, toast }: Props) {
  const [open, setOpen] = useState(false);
  const [message, setMessage] = useState(hold.message);
  const [busy, setBusy] = useState(false);

  const apply = async (on: boolean) => {
    setBusy(true);
    const res = await setHoldAction(on ? { on, message } : { on });
    setBusy(false);
    if (!res.ok) return toast(res.message);
    onChange(res.hold);
    setOpen(false);
    toast(on ? "Prices are off the website" : "Prices are showing again");
  };

  if (hold.on) {
    return (
      <div className="notice warn hold" role="status">
        <div className="hold-row">
          <div>
            <strong>Prices are on hold.</strong> Customers see your message and a WhatsApp form instead of prices
            {hold.since ? ` — since ${fmtDateTime(hold.since)}${hold.by ? ` by ${hold.by}` : ""}` : ""}.
            <div className="meta">“{hold.message}”</div>
            <div className="meta">Key in the new rates below and publish them to show prices again, or resume with the old ones.</div>
          </div>
          {isOwner ? (
            <button className="btn primary" type="button" disabled={busy} onClick={() => apply(false)}>
              Resume showing prices
            </button>
          ) : (
            <span className="meta">An owner resumes prices.</span>
          )}
        </div>
      </div>
    );
  }

  return (
    <div className="hold-bar">
      {open ? (
        <div className="notice info hold" role="dialog" aria-label="Hold prices">
          <strong>Take every price off the website now?</strong>
          <p className="desc">
            Nothing in the editor changes. Customers see the message below with a WhatsApp form until you publish new rates (or press Resume).
          </p>
          <label className="field">
            <span>Message shown to customers</span>
            <textarea className="long" value={message} maxLength={HOLD_MESSAGE_MAX} rows={3} onChange={(e) => setMessage(e.target.value)} />
            <span className="hint">
              {message.trim().length}/{HOLD_MESSAGE_MAX}
            </span>
          </label>
          <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
            <button className="btn primary" type="button" disabled={busy} onClick={() => apply(true)}>
              Hold prices now
            </button>
            <button className="btn" type="button" disabled={busy} onClick={() => setOpen(false)}>
              Cancel
            </button>
          </div>
        </div>
      ) : (
        <button className="btn small hold-btn" type="button" onClick={() => setOpen(true)} title="Hide every price from customers until new rates are published">
          Hold prices
        </button>
      )}
    </div>
  );
}
