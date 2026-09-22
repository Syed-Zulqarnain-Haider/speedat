"use client";

import { useEffect } from "react";
import { NotFoundBody } from "@/components/site/pages/NotFoundBody";

/**
 * Something in the page crashed after it loaded. The site header (with its
 * WhatsApp button) stays; the body is the 404's composition with "Try
 * again" first and the home link stepped down beside it.
 */
export default function SiteError({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  useEffect(() => {
    console.error(error);
  }, [error]);
  return (
    <NotFoundBody title="Something went wrong" line="This page did not load.">
      <button className="btn primary big" type="button" onClick={reset}>
        Try again
      </button>
    </NotFoundBody>
  );
}
