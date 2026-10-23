"use client";

/**
 * Uncontrolled form fields for the admin. Uncontrolled on purpose: number
 * inputs keep a half-typed "4." while the draft stores 4, and the caret never
 * jumps. The editor remounts them (via a key) whenever the whole draft is
 * replaced — discard, restore, import.
 *
 * Numbers are typed into `type="text" inputMode="decimal"` boxes, never
 * `type="number"`: Chrome hands a number box's "4,500" (how every price here
 * is written) to JavaScript as "", and "" means "clear this price", so pasting
 * a price with a separator silently deleted it. A box whose text does not
 * read as a number keeps the stored value and is marked invalid instead.
 */
import { useState, type CSSProperties, type ReactNode } from "react";

/** How a box that does not read as a number looks; there is no stylesheet rule for it yet. */
export const INVALID_STYLE: CSSProperties = { borderColor: "var(--orange-ink)", background: "var(--red-soft)" };

interface FldProps {
  label: ReactNode;
  type?: "text" | "number" | "tel" | "email";
  value: string | number | null | undefined;
  onChange: (raw: string) => void;
  placeholder?: string;
  step?: string;
  disabled?: boolean;
  hint?: string;
}

export function Fld({ label, type = "text", value, onChange, placeholder, step, disabled, hint }: FldProps) {
  const numeric = type === "number";
  const [bad, setBad] = useState(false);
  return (
    <label className="field">
      <span>{label}</span>
      <input
        type={numeric ? "text" : type}
        defaultValue={value == null ? "" : String(value)}
        placeholder={placeholder}
        // `step` means nothing to a text box; kept on the props so callers read the same.
        data-step={numeric ? step : undefined}
        inputMode={numeric ? "decimal" : undefined}
        autoComplete={numeric ? "off" : undefined}
        aria-invalid={bad || undefined}
        style={bad ? INVALID_STYLE : undefined}
        disabled={disabled}
        onChange={(e) => {
          if (numeric) setBad(numOrNull(e.target.value) === undefined);
          onChange(e.target.value);
        }}
      />
      {bad ? <span className="hint">Not a number — the previous value is kept.</span> : hint ? <span className="hint">{hint}</span> : null}
    </label>
  );
}

interface NumBoxProps {
  value: number | null | undefined;
  /** Called with the parsed value: a number, or null for an emptied box. Never called for text that is not a number. */
  onValue: (v: number | null) => void;
  className?: string;
  placeholder?: string;
  disabled?: boolean;
  "aria-label": string;
}

/** A bare price box (rates table, grid editor): accepts "4,500" and "Rs 4500"; junk is flagged, never stored as a deletion. */
export function NumBox({ value, onValue, className, placeholder, disabled, "aria-label": ariaLabel }: NumBoxProps) {
  const [bad, setBad] = useState(false);
  return (
    <input
      className={className}
      type="text"
      inputMode="decimal"
      autoComplete="off"
      defaultValue={value == null || !Number.isFinite(value) ? "" : String(value)}
      placeholder={placeholder}
      aria-label={ariaLabel}
      aria-invalid={bad || undefined}
      title={bad ? "Not a number — the previous price is kept" : undefined}
      style={bad ? INVALID_STYLE : undefined}
      disabled={disabled}
      onChange={(e) => {
        const v = numOrNull(e.target.value);
        setBad(v === undefined);
        if (v !== undefined) onValue(v);
      }}
    />
  );
}

interface AreaProps {
  label: ReactNode;
  value: string | null | undefined;
  onChange: (raw: string) => void;
  placeholder?: string;
  disabled?: boolean;
  mono?: boolean;
}

export function Area({ label, value, onChange, placeholder, disabled, mono }: AreaProps) {
  return (
    <label className="field">
      <span>{label}</span>
      <textarea className={mono ? "paste" : "long"} defaultValue={value ?? ""} placeholder={placeholder} disabled={disabled} onChange={(e) => onChange(e.target.value)} />
    </label>
  );
}

/**
 * "" → null (the box was emptied), "4,500" / "4 500" / "Rs 4500" / "4." → the
 * number, junk → undefined (the caller keeps the old value; it must never
 * read as "clear"). Like `toNumLoose` it forgives separators and a currency
 * label, but it is stricter about the rest: "12abc" is a typo, not 12.
 */
export function numOrNull(raw: string): number | null | undefined {
  const s = raw
    .trim()
    .replace(/^(?:rs\.?|pkr|usd|\$|€|£)\s*/i, "")
    .replace(/[,\s\u00a0\u202f'_]/g, "");
  if (s === "") return raw.trim() === "" ? null : undefined;
  if (!/^-?(\d+\.?\d*|\.\d+)$/.test(s)) return undefined;
  const n = Number(s);
  return Number.isFinite(n) ? n : undefined;
}
