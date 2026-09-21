/**
 * Country name → ISO 3166-1 alpha-2 code, so a destination can show its
 * flag (public/flags/<code>.svg) beside its name. Names are matched after
 * normalising accents, case, punctuation and a leading "the", with the
 * aliases people actually type (UK, USA, Dubai, KSA…). A destination id that
 * is itself a known two-letter code is the fallback; anything else is
 * `null`, and the Flag component prints a two-letter badge instead. Pure.
 */

/** NFD → strip combining marks → lowercase → punctuation to spaces → collapse spaces → drop a leading "the ". */
export function normalizeCountry(s: string): string {
  return s
    .normalize("NFD")
    .replace(/\p{M}/gu, "")
    .toLowerCase()
    .replace(/[^\p{L}\p{N}]+/gu, " ")
    .trim()
    .replace(/^the /, "");
}

/** `[code, ...names and aliases]`; every code has a file in public/flags/. */
const LIST: ReadonlyArray<readonly [string, ...string[]]> = [
  ["gb", "united kingdom", "uk", "u k", "britain", "great britain", "england", "scotland", "wales", "northern ireland", "london"],
  ["us", "united states", "usa", "u s a", "u s", "america", "united states of america", "states"],
  ["ca", "canada"],
  ["au", "australia"],
  ["nz", "new zealand"],
  ["ae", "united arab emirates", "uae", "u a e", "emirates", "dubai", "abu dhabi", "sharjah"],
  ["sa", "saudi arabia", "ksa", "saudi", "kingdom of saudi arabia"],
  ["qa", "qatar", "doha"],
  ["om", "oman", "muscat"],
  ["kw", "kuwait"],
  ["bh", "bahrain"],
  ["de", "germany", "deutschland"],
  ["fr", "france"],
  ["it", "italy", "italia"],
  ["es", "spain", "espana"],
  ["pt", "portugal"],
  ["nl", "netherlands", "holland"],
  ["be", "belgium"],
  ["lu", "luxembourg"],
  ["ch", "switzerland"],
  ["at", "austria"],
  ["ie", "ireland", "republic of ireland"],
  ["se", "sweden"],
  ["no", "norway"],
  ["dk", "denmark"],
  ["fi", "finland"],
  ["pl", "poland"],
  ["cz", "czechia", "czech republic"],
  ["hu", "hungary"],
  ["ro", "romania"],
  ["gr", "greece"],
  ["cy", "cyprus"],
  ["mt", "malta"],
  ["tr", "turkiye", "turkey"],
  ["ru", "russia", "russian federation"],
  ["ua", "ukraine"],
  ["my", "malaysia", "kuala lumpur"],
  ["sg", "singapore"],
  ["th", "thailand", "bangkok"],
  ["id", "indonesia"],
  ["vn", "vietnam", "viet nam"],
  ["ph", "philippines"],
  ["cn", "china"],
  ["hk", "hong kong"],
  ["tw", "taiwan"],
  ["jp", "japan"],
  ["kr", "south korea", "korea", "republic of korea"],
  ["in", "india"],
  ["bd", "bangladesh"],
  ["lk", "sri lanka"],
  ["np", "nepal"],
  ["mv", "maldives"],
  ["af", "afghanistan"],
  ["ir", "iran"],
  ["iq", "iraq"],
  ["jo", "jordan"],
  ["lb", "lebanon"],
  ["eg", "egypt"],
  ["ma", "morocco"],
  ["tn", "tunisia"],
  ["za", "south africa"],
  ["ke", "kenya"],
  ["ng", "nigeria"],
  ["mu", "mauritius"],
  ["uz", "uzbekistan"],
  ["kz", "kazakhstan"],
  ["az", "azerbaijan"],
  ["br", "brazil"],
  ["mx", "mexico"],
  ["ar", "argentina"],
  ["pk", "pakistan"],
];

/** Normalised name or alias → lowercase ISO code. */
export const COUNTRY_CODES: Readonly<Record<string, string>> = Object.fromEntries(LIST.flatMap(([code, ...names]) => names.map((n) => [normalizeCountry(n), code])));

const CODES: ReadonlySet<string> = new Set(LIST.map(([code]) => code));

/**
 * The flag code for a destination: by name (with aliases), else by its id when
 * that is exactly a known two-letter code, else `null`.
 */
export function flagCode(name: string, id?: string): string | null {
  const byName = COUNTRY_CODES[normalizeCountry(name)];
  if (byName) return byName;
  if (id && /^[A-Za-z]{2}$/.test(id) && CODES.has(id.toLowerCase())) return id.toLowerCase();
  return null;
}
