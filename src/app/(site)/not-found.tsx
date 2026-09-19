import Link from "next/link";

export default function NotFound() {
  return (
    <section className="page">
      <h1>That page is not here</h1>
      <p className="lede">The link may be old. The price calculator is on the home page, and we are one message away on WhatsApp.</p>
      <Link className="btn primary" href="/">
        Get a quote
      </Link>
    </section>
  );
}
