"use client";

import { useActionState } from "react";
import { contactAction } from "@/app/(site)/contact/actions";
import { INITIAL_CONTACT } from "@/app/(site)/contact/state";
import { UI } from "@/components/Icons";

interface Props {
  destinations: { id: string; name: string }[];
  whatsapp: string;
  /** Signed render timestamp from the server. */
  token: string;
}

/**
 * The message form on /contact: 52px fields with short plain labels and
 * one big "Send message" (the WhatsApp way out is the contact line right
 * under the form). Field names, the signed token, the honeypot, validation
 * echo and the success card are the server action's contract and stay
 * exactly as they are.
 */
export function ContactForm({ destinations, whatsapp, token }: Props) {
  const [state, action, pending] = useActionState(contactAction, INITIAL_CONTACT);
  if (state.ok) {
    return (
      <div className="card contact-done" role="status">
        <h3>Thank you — we have your message</h3>
        <p>We reply during working hours. For anything urgent, WhatsApp is fastest.</p>
        <a className="btn wa big" href={`https://wa.me/${whatsapp}`} target="_blank" rel="noopener">
          <UI.wa />
          WhatsApp us
        </a>
      </div>
    );
  }
  const v = state.values;
  const err = (k: string) => (state.errors[k] ? <span className="contact-form-err">{state.errors[k]}</span> : null);
  return (
    <form action={action} className="panel contact-form" noValidate>
      {state.errors._ ? (
        <div className="notice err" role="alert">
          {state.errors._}
        </div>
      ) : null}
      <input type="hidden" name="t" value={token} />
      {/* Honeypot: hidden from people, filled by bots. */}
      <div style={{ position: "absolute", left: -9999, top: -9999 }} aria-hidden="true">
        <label>
          Website <input type="text" name="website" tabIndex={-1} autoComplete="off" />
        </label>
      </div>
      <div className="row">
        <label className="field">
          <span>Your name</span>
          <input type="text" name="name" defaultValue={v.name} autoComplete="name" required maxLength={120} />
          {err("name")}
        </label>
        <label className="field">
          <span>Phone or WhatsApp</span>
          <input type="tel" name="phone" defaultValue={v.phone} autoComplete="tel" required maxLength={20} placeholder="+92 3xx xxx xxxx" />
          {err("phone")}
        </label>
      </div>
      <div className="row">
        <label className="field">
          <span>
            Country <span className="hint">(optional)</span>
          </span>
          <select name="destId" defaultValue={v.destId ?? ""}>
            <option value="">Not sure yet</option>
            {destinations.map((d) => (
              <option key={d.id} value={d.id}>
                {d.name}
              </option>
            ))}
          </select>
        </label>
        <label className="field">
          <span>
            Weight in kg <span className="hint">(optional)</span>
          </span>
          <input type="number" name="weight" defaultValue={v.weight} inputMode="decimal" min="0.1" step="any" />
        </label>
      </div>
      <label className="field">
        <span>Message</span>
        <textarea className="long" name="message" defaultValue={v.message} required maxLength={2000} placeholder="What are you sending, and when?" />
        {err("message")}
      </label>
      <div className="contact-form-actions">
        <button className="btn primary big" type="submit" disabled={pending}>
          {pending ? "Sending…" : "Send message"}
        </button>
      </div>
    </form>
  );
}
