/**
 * Splits an owner-typed line into plain text and phone-number runs, so a
 * content field such as `phone2` ("Mobile: +92 333 766 7076 · +92 334 77 33
 * 328") can print every number as a `tel:` link while the words around it
 * stay as typed. Pure; no React.
 *
 * A run is an optional "+" or "(", a digit, then digits, spaces, dots,
 * dashes and parentheses up to the last digit. It counts as a phone number
 * only with at least seven digits, so "9 am – 7 pm" or "Shop 4" stay text.
 * The href keeps only the digits and a leading "+", exactly as the other
 * `tel:` links on the site are built.
 */
export type PhoneSegment = { text: string; tel?: string };

const RUN = /[+(]?\d[\d ().-]*\d/g;
const MIN_DIGITS = 7;

export function phoneSegments(text: string): PhoneSegment[] {
  const s = String(text ?? "");
  const out: PhoneSegment[] = [];
  let last = 0;
  for (const m of s.matchAll(RUN)) {
    const run = m[0];
    const at = m.index ?? 0;
    const digits = run.replace(/\D/g, "");
    if (digits.length < MIN_DIGITS) continue;
    if (at > last) out.push({ text: s.slice(last, at) });
    out.push({ text: run, tel: `tel:${run.replace(/[^0-9+]/g, "")}` });
    last = at + run.length;
  }
  if (last < s.length) out.push({ text: s.slice(last) });
  return out;
}

/** The digits of a number as typed, without a leading 0 or 00, so two spellings of one number compare equal. */
function digitKey(s: string): string {
  return s.replace(/\D/g, "").replace(/^0+/, "");
}

/**
 * True when two spellings name one number: "+92 333 766 7076", "0333 766
 * 7076", "923337667076" and "333 7667076" all end in the same digits. Both
 * need at least seven digits, and the shorter must be the tail of the
 * longer (a local spelling drops the country code, never the other way).
 */
export function samePhone(a: string, b: string): boolean {
  const x = digitKey(a);
  const y = digitKey(b);
  if (x.length < MIN_DIGITS || y.length < MIN_DIGITS) return false;
  return x === y || x.endsWith(y) || y.endsWith(x);
}

/** An item separator the owner typed between two facts: a middle dot, a comma, a semicolon, a bar or a new line. */
const ITEM_SPLIT = /\s*(?:[·•,;|]|\r?\n)+\s*/;
/** Text between two numbers of one item that only joins them ("or", "and", "&", "/", a dash, spaces). */
const JOINER = /^\s*(?:or|and|&|\/|-|–|—)?\s*$/i;

/**
 * `phoneSegments` minus the numbers the page already states elsewhere, so a
 * free-text field such as `phone2` never repeats the WhatsApp number or the
 * landline under a second label. The line is read as items parted by the
 * owner's separators ("Mobile: +92 333 … · +92 334 …" is two items): an
 * item whose every number is already stated goes, label and all; an item
 * with a number to keep drops only the stated one and the word joining it;
 * an item with no number stays as typed. What remains is joined with " · ".
 * A line with nothing to drop comes back exactly as `phoneSegments` reads
 * it, and a line that loses every number comes back empty, so the caller
 * can skip its row.
 */
export function phoneSegmentsExcept(text: string, known: readonly string[]): PhoneSegment[] {
  const s = String(text ?? "");
  const stated = known.map((k) => String(k ?? "")).filter((k) => digitKey(k).length >= MIN_DIGITS);
  const isStated = (seg: PhoneSegment) => Boolean(seg.tel) && stated.some((k) => samePhone(seg.text, k));
  const all = phoneSegments(s);
  if (!all.some(isStated)) return all;
  const items = s
    .split(ITEM_SPLIT)
    .map((it) => it.trim())
    .filter(Boolean);
  const kept: PhoneSegment[][] = [];
  for (const item of items) {
    const segs = phoneSegments(item);
    const nums = segs.filter((x) => x.tel);
    if (nums.length && nums.every(isStated)) continue;
    if (!nums.some(isStated)) {
      kept.push(segs);
      continue;
    }
    const out: PhoneSegment[] = [];
    let skip = false;
    for (let i = 0; i < segs.length; i++) {
      if (skip) {
        skip = false;
        continue;
      }
      const seg = segs[i];
      if (!isStated(seg)) {
        out.push(seg);
        continue;
      }
      const next = segs[i + 1];
      const after = segs[i + 2];
      if (next && !next.tel && JOINER.test(next.text) && after?.tel) {
        skip = true;
      } else {
        const prev = out[out.length - 1];
        if (prev && !prev.tel && JOINER.test(prev.text)) out.pop();
      }
    }
    const clean = out
      .map((x, i) => {
        if (x.tel) return x;
        let t = x.text;
        if (i === 0) t = t.trimStart();
        if (i === out.length - 1) t = t.trimEnd();
        return { text: t };
      })
      .filter((x) => x.tel || x.text);
    if (clean.length) kept.push(clean);
  }
  const joined: PhoneSegment[] = [];
  kept.forEach((segs, i) => {
    if (i) joined.push({ text: " · " });
    joined.push(...segs);
  });
  return joined;
}
