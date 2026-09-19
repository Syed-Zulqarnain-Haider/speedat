"use client";

import { useEffect } from "react";

/** Something in the page crashed after it loaded. The customer still gets a way to reach us. */
export default function SiteError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  useEffect(() => {
    console.error(error);
  }, [error]);
  return (
    <section className="page">
      <h1>This page could not load</h1>
      <p className="lede">Please try again, or use the WhatsApp button at the top of the page and we will price your shipment directly.</p>
      <button className="btn primary" type="button" onClick={reset}>
        Try again
      </button>
    </section>
  );
}
