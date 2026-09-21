/**
 * The italic accent convention for headlines: the owner wraps exactly one
 * word (or phrase) in *asterisks* and the site sets it in the serif italic.
 * Pure string parsing, shared by the server components that render content
 * fields and the hero title's per-word spans. An unbalanced or empty pair
 * prints literally, so a typo never eats the headline.
 */

export interface AccentParts {
  before: string;
  /** Empty string means no accent: `before` is the whole text as typed. */
  accent: string;
  after: string;
}

export function parseAccent(text: string): AccentParts {
  const i = text.indexOf("*");
  const j = i >= 0 ? text.indexOf("*", i + 1) : -1;
  const inner = j > i + 1 ? text.slice(i + 1, j) : "";
  if (inner.trim() === "" || inner.includes("\n")) return { before: text, accent: "", after: "" };
  return { before: text.slice(0, i), accent: inner, after: text.slice(j + 1) };
}

export interface AccentWord {
  text: string;
  accent: boolean;
  /** Characters glued to the front of the first accent word, e.g. an opening bracket. */
  head?: string;
  /** Characters glued to the end of the last accent word, e.g. a comma. */
  tail?: string;
}

function words(s: string): string[] {
  return s.split(/\s+/).filter(Boolean);
}

/** The text as whitespace-separated words with the accent words flagged; punctuation never becomes its own word. */
export function accentWords(text: string): AccentWord[] {
  const { before, accent, after } = parseAccent(text);
  if (!accent) return words(before).map((w) => ({ text: w, accent: false }));
  const pre = words(before);
  const acc = words(accent);
  const post = words(after);
  const out: AccentWord[] = [];
  // A non-space run touching the opening asterisk belongs to the first accent word.
  const head = before.length && !/\s$/.test(before) ? pre.pop() : undefined;
  // A non-space run touching the closing asterisk belongs to the last accent word.
  const tail = after.length && !/^\s/.test(after) ? post.shift() : undefined;
  for (const w of pre) out.push({ text: w, accent: false });
  acc.forEach((w, k) => {
    const item: AccentWord = { text: w, accent: true };
    if (k === 0 && head) item.head = head;
    if (k === acc.length - 1 && tail) item.tail = tail;
    out.push(item);
  });
  for (const w of post) out.push({ text: w, accent: false });
  return out;
}
