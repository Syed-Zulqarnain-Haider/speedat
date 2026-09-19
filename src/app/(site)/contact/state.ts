/** Shape shared by the contact form and its server action (plain module: "use server" files may only export functions). */
export interface ContactState {
  ok: boolean;
  /** Field → message for inline errors; "_" for the form as a whole. */
  errors: Record<string, string>;
  /** Echoed values so a failed submit does not empty the form. */
  values: Record<string, string>;
  leadId?: number;
}

export const INITIAL_CONTACT: ContactState = { ok: false, errors: {}, values: {} };
