"use client";

import { useEffect } from "react";
import { PageHead } from "@/components/site/PageHead";

/** Something in the page crashed after it loaded. The customer still gets a way to reach us. */
export default function SiteError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  useEffect(() => {
    console.error(error);
  }, [error]);
  return (
    <section className="page nf">
      <span className="ghost nf-ghost" aria-hidden="true">
        Error
      </span>
      <PageHead
        no="Error"
        name="Something went wrong"
        title="This page could not *load*"
        lede="Please try again, or use the WhatsApp button at the top of the page and we will price your shipment directly."
      />
      <div className="nf-actions">
        <button className="btn primary big" type="button" onClick={reset}>
          Try again
        </button>
      </div>
    </section>
  );
}
