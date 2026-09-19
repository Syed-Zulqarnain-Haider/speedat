"use client";

/**
 * Uncontrolled form fields for the admin. Uncontrolled on purpose: number
 * inputs keep a half-typed "4." while the draft stores 4, and the caret never
 * jumps. The editor remounts them (via a key) whenever the whole draft is
 * replaced — discard, restore, import.
 */
import type { ReactNode } from "react";

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
  return (
    <label className="field">
      <span>{label}</span>
      <input
        type={type}
        defaultValue={value == null ? "" : String(value)}
        placeholder={placeholder}
        step={step}
        inputMode={type === "number" ? "decimal" : undefined}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
      />
      {hint ? <span className="hint">{hint}</span> : null}
    </label>
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

/** "" → null, "4,500" → 4500, junk → undefined (caller keeps the old value). */
export function numOrNull(raw: string): number | null | undefined {
  const s = raw.replace(/,/g, "").trim();
  if (s === "") return null;
  const n = Number(s);
  return Number.isFinite(n) ? n : undefined;
}
