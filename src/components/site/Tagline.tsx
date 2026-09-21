/** The brand tagline under the name: a plain sentence-case line (site.css `.brand-tag`). Nothing loops. Server component. */
export function Tagline({ text }: { text: string }) {
  return <span className="brand-tag">{text}</span>;
}
