import { parseAccent } from "@/lib/site/accent";

/** Renders a content field with its one `*word*` set as the italic serif accent. Server-safe. */
export function Accent({ text }: { text: string }) {
  const { before, accent, after } = parseAccent(text);
  if (!accent) return <>{before}</>;
  return (
    <>
      {before}
      <em className="accent">{accent}</em>
      {after}
    </>
  );
}
