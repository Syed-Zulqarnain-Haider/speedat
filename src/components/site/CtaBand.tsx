import Link from "next/link";

export function CtaBand({ whatsapp }: { whatsapp: string }) {
  return (
    <div className="cta-band">
      <div>
        <h2>Ready to send something?</h2>
        <p>Get an instant price and book on WhatsApp in two minutes.</p>
      </div>
      <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
        <Link className="btn primary" href="/">
          Get a quote
        </Link>
        <a className="btn wa" href={`https://wa.me/${whatsapp}`} target="_blank" rel="noopener">
          WhatsApp us
        </a>
      </div>
    </div>
  );
}
