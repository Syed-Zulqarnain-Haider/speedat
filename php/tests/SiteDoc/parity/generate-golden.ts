/**
 * Differential harness, TypeScript side. Runs the REAL specification
 * (src/lib/site/*.ts) over a battery of fixtures and writes both the fixture
 * inputs and the golden outputs to JSON, so the PHP port can be fed exactly the
 * same inputs and compared character for character.
 *
 * Read-only: imports the spec, never modifies it.
 */
import { writeFileSync } from "node:fs";
import { SEED, gridSeed } from "@/lib/site/seed";
import { migrate, upgradeContent, RETIRED_DEFAULTS } from "@/lib/site/migrate";
import { diffSite, validateSite, warnSite, summarise, fieldChange, FLAG_PCT } from "@/lib/site/diff";
import { normalizeCountry, flagCode, COUNTRY_CODES } from "@/lib/site/countries";
import { cleanHoldMessage, DEFAULT_HOLD_MESSAGE, HOLD_MESSAGE_MAX, NO_HOLD } from "@/lib/site/hold-shared";
import { SiteDataSchema } from "@/lib/site/schema";
import type { SiteData } from "@/lib/site/types";

/* ------------------------------------------------------------ canonical dump */

/** An order-sensitive, number-exact textual dump. The PHP side builds the identical string. */
function dump(v: unknown): string {
  if (v === null || v === undefined) return "null";
  if (typeof v === "boolean") return v ? "true" : "false";
  if (typeof v === "number") return "#" + String(v);
  if (typeof v === "string") return "\u0002" + v + "\u0003";
  if (Array.isArray(v)) return "[" + v.map(dump).join(",") + "]";
  if (typeof v === "object") {
    const o = v as Record<string, unknown>;
    return "{" + Object.keys(o).map((k) => k + ":" + dump(o[k])).join(",") + "}";
  }
  return "?";
}

/** A diff, flattened so neither key order nor undefined-vs-null can confuse the comparison. */
function dumpDiff(d: ReturnType<typeof diffSite>): string {
  const lines = d.lines.map((l) => [l.kind, l.label, l.old ?? "\u0000", l.new ?? "\u0000", l.flag ?? "\u0000"].join("\u0001"));
  return [String(d.count), Object.keys(d.byDest).sort().join(","), ...lines].join("\u0004");
}

const clone = <T,>(x: T): T => structuredClone(x);

/* ----------------------------------------------------------------- fixtures */

const cases: { name: string; kind: string; input: unknown; expected: string }[] = [];
const add = (name: string, kind: string, input: unknown, expected: string) => cases.push({ name, kind, input, expected });

// --- 1. seed -----------------------------------------------------------------
add("seed", "doc", null, dump(SEED));
add("gridSeed", "doc", null, dump(gridSeed()));

// --- 2. migrate --------------------------------------------------------------
const migrateInputs: Record<string, unknown> = {
  empty: {},
  blankish: { live: false, company: {}, settings: {}, content: {}, destinations: [], services: [], importProfiles: [] },
  seed: clone(SEED),
  gridSeed: clone(gridSeed()),
  cutoffEmptyString: { settings: { cutoffHour: "" } },
  cutoffStringNumber: { settings: { cutoffHour: "9" } },
  cutoffJunk: { settings: { cutoffHour: "half past" } },
  cutoffNull: { settings: { cutoffHour: null } },
  docMaxNull: { settings: { docMaxKg: null, firstKg: 1.5 } },
  modeJunk: { settings: { pricingMode: "whatever" } },
  modeGrid: { settings: { pricingMode: "grid" } },
  liveTruthyString: { live: "0" },
  liveTrue: { live: true },
  emptyServices: { services: [] },
  keptServices: { services: [{ id: "x", name: "X", note: "" }] },
  // Every retired default, verbatim, expecting the current copy back.
  allRetiredV1: {
    content: Object.fromEntries(Object.entries(RETIRED_DEFAULTS).map(([k, v]) => [k, v![0]])),
  },
  allRetiredLast: {
    content: Object.fromEntries(Object.entries(RETIRED_DEFAULTS).map(([k, v]) => [k, v![v!.length - 1]])),
  },
  retiredWithPadding: { content: { heroTitle: "  " + RETIRED_DEFAULTS.heroTitle![0] + "  " } },
  ownWords: { content: { heroTitle: "Send it with Speedat.", promise: "We mean it." } },
  unknownKeys: { content: { heroTitle: "Hi", somethingNew: "ignored" }, company: { name: "Acme", nope: 1 } },
};
for (const [name, input] of Object.entries(migrateInputs)) add(name, "migrate", input, dump(migrate(input as SiteData)));

// upgradeContent on its own
add("upgradeContentIdempotent", "upgradeContent", clone(SEED.content), dump(upgradeContent(clone(SEED.content))));
add(
  "upgradeContentRetired",
  "upgradeContent",
  { ...clone(SEED.content), heroSub: RETIRED_DEFAULTS.heroSub![1], faq: RETIRED_DEFAULTS.faq![0] },
  dump(upgradeContent({ ...clone(SEED.content), heroSub: RETIRED_DEFAULTS.heroSub![1], faq: RETIRED_DEFAULTS.faq![0] })),
);
add("retiredDefaults", "raw", null, dump(RETIRED_DEFAULTS));

// --- 3. diff -----------------------------------------------------------------
const base = migrate(clone(SEED));
const gbase = migrate(clone(gridSeed()));

function edit(from: SiteData, fn: (s: SiteData) => void): SiteData {
  const s = clone(from);
  fn(s);
  return s;
}

const long = "The price you see on this page is the price we charge you at pickup, confirmed when the parcel is weighed";
const diffPairs: Record<string, [SiteData, SiteData]> = {
  identical: [base, clone(base)],
  addedDestination: [base, edit(base, (s) => s.destinations.push({ id: "fr", name: "France", active: true, rates: { express: { first: 4600, addl: 1120, days: "3–5", doc: 4000 } } }))],
  addedHidden: [base, edit(base, (s) => s.destinations.push({ id: "fr", name: "France", active: false, rates: {} }))],
  removedDestination: [base, edit(base, (s) => s.destinations.splice(2, 1))],
  renamed: [base, edit(base, (s) => { s.destinations[0]!.name = "Britain"; })],
  hidden: [base, edit(base, (s) => { s.destinations[1]!.active = false; })],
  shown: [edit(base, (s) => { s.destinations[1]!.active = false; }), base],
  bigRise: [base, edit(base, (s) => { s.destinations[0]!.rates.express!.first = 6000; })],
  smallRise: [base, edit(base, (s) => { s.destinations[0]!.rates.express!.first = 4600; })],
  bigFall: [base, edit(base, (s) => { s.destinations[0]!.rates.express!.first = 2000; })],
  exactlyFlagged: [base, edit(base, (s) => { s.destinations[0]!.rates.express!.first = 4500 * 1.25; })],
  daysChanged: [base, edit(base, (s) => { s.destinations[0]!.rates.express!.days = "4–6"; })],
  docCleared: [base, edit(base, (s) => { s.destinations[0]!.rates.express!.doc = null; })],
  addlFromZero: [edit(base, (s) => { s.destinations[0]!.rates.express!.addl = 0; }), base],
  rateRemoved: [base, edit(base, (s) => { delete s.destinations[0]!.rates.normal; })],
  gridChanged: [gbase, edit(gbase, (s) => { s.destinations[0]!.rates.express!.grid!["3"] = 99999; })],
  gridBoxAdded: [gbase, edit(gbase, (s) => { s.destinations[0]!.rates.express!.grid!["26"] = 30000; })],
  gridBoxRemoved: [gbase, edit(gbase, (s) => { delete s.destinations[0]!.rates.express!.grid!["5"]; })],
  liveFlipped: [base, edit(base, (s) => { s.live = true; })],
  singleLineShort: [base, edit(base, (s) => { s.content.routesTitle = "Prices"; })],
  singleLineLongTailEdit: [edit(base, (s) => { s.content.promise = long + " at our office."; }), edit(base, (s) => { s.content.promise = long + " at the pickup address."; })],
  singleLineLongHeadEdit: [edit(base, (s) => { s.content.promise = "A" + long; }), edit(base, (s) => { s.content.promise = "B" + long; })],
  multiLineMiddle: [base, edit(base, (s) => { const l = s.content.faq.split("\n"); l[2] = "Who pays duties? | The sender."; s.content.faq = l.join("\n"); })],
  multiLineAdded: [base, edit(base, (s) => { s.content.faq = s.content.faq + "\nDo you ship food? | No."; })],
  multiLineRemoved: [base, edit(base, (s) => { const l = s.content.faq.split("\n"); l.splice(1, 1); s.content.faq = l.join("\n"); })],
  multiLineTwoChanged: [base, edit(base, (s) => { const l = s.content.faq.split("\n"); l[1] = "A? | B."; l[2] = "C? | D."; s.content.faq = l.join("\n"); })],
  multiLineToEmpty: [base, edit(base, (s) => { s.content.faq = ""; })],
  emptyToMultiLine: [edit(base, (s) => { s.content.faq = ""; }), base],
  settingsNumber: [base, edit(base, (s) => { s.settings.roundTo = 5; })],
  settingsBool: [base, edit(base, (s) => { s.settings.showEta = false; })],
  settingsCutoffNulled: [base, edit(base, (s) => { s.settings.cutoffHour = null; })],
  companyChanged: [base, edit(base, (s) => { s.company.phone = "+92 300 000 0000"; s.company.notes = s.company.notes + "\nBring the box."; })],
  profileRemembered: [base, edit(base, (s) => { s.importProfiles.push({ signature: "a|b|c", name: "rates.xlsx", map: { first: 1 }, savedAt: "2026-01-01", cost: false, margin: 0, mround: 10 }); })],
  profileAnonymous: [base, edit(base, (s) => { s.importProfiles.push({ signature: "a|b", name: "", map: {}, savedAt: "", cost: true, margin: 15, mround: 50 }); })],
  everything: [base, edit(base, (s) => {
    s.live = true;
    s.destinations[0]!.name = "Britain";
    s.destinations[1]!.active = false;
    s.destinations[2]!.rates.express!.first = 9000;
    s.destinations.push({ id: "fr", name: "France", active: true, rates: {} });
    s.destinations.splice(3, 1);
    s.content.routesTitle = "Our prices";
    s.settings.taxPct = 5;
  })],
};
for (const [name, [a, b]] of Object.entries(diffPairs)) {
  const d = diffSite(a, b);
  add(name, "diff", { a, b }, dumpDiff(d) + "\u0005" + summarise(d));
}

// fieldChange on its own, including non-string inputs
const fcInputs: [unknown, unknown][] = [
  ["", ""], ["a", "b"], [null, "x"], [5, 6], [true, false],
  [long + " one", long + " two"],
  ["a\nb\nc", "a\nB\nc"],
  ["a\nb", "a\nb\nc"],
  ["a\r\nb\r\nc", "a\r\nb"],
  [long + "\n" + long, long + "\n" + long + "!"],
];
fcInputs.forEach(([a, b], i) => {
  const c = fieldChange(a, b);
  add("fc" + i, "fieldChange", { a, b }, [c.where ?? "\u0000", c.old, c.new].join("\u0001"));
});

// --- 4. validate / warn ------------------------------------------------------
const bad1 = edit(base, (s) => {
  s.company.name = "   ";
  s.company.whatsapp = "12345";
  s.settings.volumetricDivisor = 500;
  s.settings.firstKg = 0;
  s.settings.stepKg = -1;
  s.settings.taxPct = 150;
  s.settings.roundTo = 0;
  s.settings.maxKg = -5;
  s.settings.docMaxKg = 0;
  s.settings.addons = "Pickup | abc | on\n | 5 | on\nGood | 10 | off\nNeg | -3 | on";
  s.settings.cutoffHour = 30;
  s.settings.workingDays = "Xyz";
  s.settings.currency = "";
  s.settings.holidays = "not-a-date\n2026-13-45\n2026-01-01 | New Year\nalso bad\nfour\nfive\nsix";
});
const bad2 = edit(base, (s) => {
  s.services = [];
  s.destinations = [
    { id: "a", name: "", active: true, rates: {} },
    { id: "b", name: "United Kingdom", active: true, rates: {} },
    { id: "c", name: "united  kingdom", active: false, rates: {} },
    { id: "d", name: "Nowhere", active: true, rates: {} },
  ];
});
const bad3 = edit(base, (s) => {
  s.destinations = [
    { id: "a", name: "Docs Only", active: true, rates: { express: { doc: 100 } } },
    { id: "b", name: "Bad Prices", active: true, rates: { express: { first: 0, addl: null }, normal: { first: 100, addl: -5, doc: 0 } } },
  ];
});
const bad4 = edit(gbase, (s) => {
  s.settings.maxKg = 0;
  s.destinations = [
    { id: "a", name: "Grid Docs", active: true, rates: { express: { doc: 100, grid: {} } } },
    { id: "b", name: "Grid Bad", active: true, rates: { express: { grid: { "1": 0, "2": -5, "3": 100 } } } },
  ];
});
const bad5 = edit(base, (s) => { s.destinations = s.destinations.map((d) => ({ ...d, active: false })); });
const bad6 = edit(base, (s) => { s.settings.workingDays = ""; s.settings.currency = "PakistaniRupees"; s.settings.cutoffHour = null; });

const warn1 = edit(base, (s) => {
  s.destinations[0]!.rates.express!.addl = 9000;
  s.destinations[1]!.rates.express!.first = 1000;
  s.destinations[2]!.rates.express!.days = "";
  s.destinations[3]!.rates.express!.doc = 99999;
});
const warn2 = edit(gbase, (s) => {
  const g = s.destinations[0]!.rates.express!.grid!;
  g["4"] = 1;
  delete g["6"];
  delete g["7"];
  delete g["23"];
  delete g["24"];
  delete g["25"];
  const h = s.destinations[1]!.rates.normal!.grid!;
  for (const k of Object.keys(h)) if (Number(k) > 3) delete h[k];
});
const warn3 = edit(gbase, (s) => {
  for (const k of Object.keys(s.destinations[2]!.rates.express!.grid!)) delete s.destinations[2]!.rates.express!.grid![k];
  s.destinations[2]!.rates.express!.doc = 50000;
});

const vwDocs: Record<string, SiteData> = { base, gbase, bad1, bad2, bad3, bad4, bad5, bad6, warn1, warn2, warn3 };
for (const [name, doc] of Object.entries(vwDocs)) {
  add(name, "validate", doc, validateSite(doc).join("\u0001"));
  add(name, "warn", doc, warnSite(doc).join("\u0001"));
}

// --- 5. schema ---------------------------------------------------------------
function schemaDump(doc: unknown): string {
  const r = SiteDataSchema.safeParse(doc);
  if (r.success) return "OK";
  return r.error.issues.map((i) => i.path.join(".") + ": " + i.message).join("\u0001");
}
const schemaDocs: Record<string, unknown> = {
  seed: clone(SEED),
  gridSeed: clone(gridSeed()),
  empty: {},
  longHero: edit(base, (s) => { s.content.heroTitle = "x".repeat(201); }),
  exactHero: edit(base, (s) => { s.content.heroTitle = "x".repeat(200); }),
  longFaq: edit(base, (s) => { s.content.faq = "x".repeat(12001); }),
  multiByteAtCap: edit(base, (s) => { s.content.hours = "é".repeat(120); }),
  multiByteOverCap: edit(base, (s) => { s.content.hours = "é".repeat(121); }),
  emojiAtCap: edit(base, (s) => { s.content.routesTitle = "🙂".repeat(60); }),
  badMode: edit(base, (s) => { (s.settings as { pricingMode: string }).pricingMode = "nope"; }),
  cutoffAbsent: (() => { const s = clone(base) as Record<string, unknown>; delete (s.settings as Record<string, unknown>).cutoffHour; return s; })(),
  cutoffNull: edit(base, (s) => { s.settings.cutoffHour = null; }),
  badId: edit(base, (s) => { s.destinations[0]!.id = "GB_1"; }),
  badServiceId: edit(base, (s) => { s.services[0]!.id = "Express One"; }),
  tooManyServices: edit(base, (s) => { s.services = Array.from({ length: 7 }, (_, i) => ({ id: "s" + i, name: "S", note: "" })); }),
  tooManyDests: edit(base, (s) => { s.destinations = Array.from({ length: 501 }, (_, i) => ({ id: "d" + i, name: "D", active: true, rates: {} })); }),
  tooManyProfiles: edit(base, (s) => { s.importProfiles = Array.from({ length: 21 }, () => ({ signature: "s", name: "n", map: {}, savedAt: "", cost: false, margin: 0, mround: 0 })); }),
  gridKeyDecimal: edit(gbase, (s) => { s.destinations[0]!.rates.express!.grid!["2.5"] = 100; }),
  gridKeyFourDigits: edit(gbase, (s) => { s.destinations[0]!.rates.express!.grid!["1000"] = 100; }),
  gridKeyWord: edit(gbase, (s) => { s.destinations[0]!.rates.express!.grid!["heavy"] = 100; }),
  moneyNegative: edit(base, (s) => { s.destinations[0]!.rates.express!.first = -1; }),
  moneyHuge: edit(base, (s) => { s.destinations[0]!.rates.express!.first = 100_000_001; }),
  moneyAtCap: edit(base, (s) => { s.destinations[0]!.rates.express!.first = 100_000_000; }),
  moneyString: edit(base, (s) => { (s.destinations[0]!.rates.express as { first: unknown }).first = "4500"; }),
  moneyNull: edit(base, (s) => { s.destinations[0]!.rates.express!.first = null; }),
  liveString: edit(base, (s) => { (s as { live: unknown }).live = "yes"; }),
  profileMapFloat: edit(base, (s) => { s.importProfiles = [{ signature: "s", name: "n", map: { first: 1.5 }, savedAt: "", cost: false, margin: 0, mround: 0 }]; }),
  manyProblems: edit(base, (s) => { s.content.heroTitle = "x".repeat(201); s.content.faq = "y".repeat(12001); s.destinations[0]!.id = "NO"; }),
  // Zod 4 measures a string in CODE POINTS: 61 emoji is 61 characters (122 code
  // units) and fits a cap of 120; 121 of them does not.
  emojiUnderCap: edit(base, (s) => { s.content.routesTitle = "🙂".repeat(61); }),
  emojiOverCap: edit(base, (s) => { s.content.routesTitle = "🙂".repeat(121); }),
  ratesKeyLong: edit(base, (s) => { s.destinations[0]!.rates["x".repeat(41)] = { first: 1 }; }),
  ratesKeyLongBadRate: edit(base, (s) => { (s.destinations[0]!.rates as Record<string, unknown>)["x".repeat(41)] = "nope"; }),
  rateNull: edit(base, (s) => { (s.destinations[0]!.rates as Record<string, unknown>).express = null; }),
  rateNotObject: edit(base, (s) => { (s.destinations[0]!.rates as Record<string, unknown>).express = 7; }),
  gridNull: edit(gbase, (s) => { (s.destinations[0]!.rates.express as Record<string, unknown>).grid = null; }),
  gridBadKeyAndBadValue: edit(gbase, (s) => {
    const g = s.destinations[0]!.rates.express!.grid as Record<string, unknown>;
    for (const k of Object.keys(g)) delete g[k];
    g["2.5"] = 1;
    g["3"] = "x";
    g["4"] = -1;
  }),
  daysWrongType: edit(base, (s) => { (s.destinations[0]!.rates.express as Record<string, unknown>).days = 5; }),
  daysLong: edit(base, (s) => { s.destinations[0]!.rates.express!.days = "x".repeat(41); }),
  rateFieldOrder: edit(base, (s) => { s.destinations[0]!.rates.express = { first: "a", addl: "b", days: 5, doc: "c" } as never; }),
  profileFieldOrder: edit(base, (s) => { s.importProfiles = [{ signature: 1, name: 2, map: "x", savedAt: 3, cost: "x", margin: "x", mround: "x" }] as never; }),
  mapKeyLong: edit(base, (s) => { s.importProfiles = [{ signature: "s", name: "n", map: { ["k".repeat(61)]: 1, ok: 2.5 }, savedAt: "", cost: false, margin: 0, mround: 0 }]; }),
  servicesElementAndMax: edit(base, (s) => { s.services = Array.from({ length: 7 }, (_, i) => ({ id: i === 0 ? "BAD ID" : "s" + i, name: "S", note: "" })); }),
  elementNotObject: edit(base, (s) => { (s.destinations as unknown[])[0] = "x"; }),
  missingPricingMode: (() => { const s = clone(base) as Record<string, unknown>; delete (s.settings as Record<string, unknown>).pricingMode; return s; })(),
  settingMissingNumber: (() => { const s = clone(base) as Record<string, unknown>; delete (s.settings as Record<string, unknown>).roundTo; return s; })(),
  contentMissingKey: (() => { const s = clone(base) as Record<string, unknown>; delete (s.content as Record<string, unknown>).phone2; return s; })(),
  ratesNotRecord: edit(base, (s) => { (s.destinations[0]! as Record<string, unknown>).rates = null; }),
  mapNotRecord: edit(base, (s) => { s.importProfiles = [{ signature: "s", name: "n", map: null, savedAt: "", cost: false, margin: 0, mround: 0 }] as never; }),
};
for (const [name, doc] of Object.entries(schemaDocs)) add(name, "schema", doc, schemaDump(doc));

// --- 6. countries ------------------------------------------------------------
const names = [
  "United Kingdom", "the United Kingdom", "UK", "U.K.", "Britain", "Great Britain", "England", "London",
  "USA", "U.S.A.", "United States", "United States of America", "America", "the states",
  "UAE", "Dubai", "U.A.E.", "Abu  Dhabi", "KSA", "Saudi", "Saudi Arabia", "Türkiye", "Turkiye", "TURKEY",
  "Deutschland", "España", "Espana", "Holland", "Netherlands", "Czech Republic", "Czechia",
  "South Korea", "korea", "Hong  Kong", "Viet Nam", "Vietnam", "Sri Lanka", "  Pakistan  ",
  "Atlantis", "", "the", "New Zealand", "Côte d'Ivoire", "MALTA", "ireland", "Republic of Ireland",
];
for (const n of names) {
  add(n || "(blank)", "normalizeCountry", n, normalizeCountry(n));
  add(n || "(blank)", "flagCode", { name: n, id: null }, String(flagCode(n) ?? "\u0000"));
}
const flagWithIds: [string, string][] = [["Atlantis", "gb"], ["Atlantis", "GB"], ["Atlantis", "zz"], ["Atlantis", "gbr"], ["Atlantis", "g"], ["United Kingdom", "us"]];
flagWithIds.forEach(([n, id], i) => add("flagId" + i, "flagCode", { name: n, id }, String(flagCode(n, id) ?? "\u0000")));
add("countryCodes", "raw", null, dump(COUNTRY_CODES));

// --- 7. hold -----------------------------------------------------------------
const holdRaws: unknown[] = [
  "", "   ", "\n\t ", null, undefined, 42, true, false,
  "Back soon.", "  Back   soon.  ", "line one\nline two", "tabs\tand\t\tspaces",
  "x".repeat(299), "x".repeat(300), "x".repeat(301), "x".repeat(500),
  ("word ".repeat(80)), " padded ", "é".repeat(400), "🙂".repeat(200),
  DEFAULT_HOLD_MESSAGE,
  // JavaScript's \s, spelled out: every one of these must collapse to a space.
  "a﻿b", "﻿", "a b c", "a　b c d", "a b c", "\u000b\u000c tabbed \u000b",
  // 300 code units exactly (150 emoji) is kept whole; 151 emoji is cut to 150.
  "🙂".repeat(150), "🙂".repeat(151),
  // A cut that lands mid-word, on a plain BMP boundary.
  "é".repeat(299) + "ZZZ",
];
holdRaws.forEach((raw, i) => add("hold" + i, "cleanHoldMessage", { raw: raw === undefined ? null : raw }, cleanHoldMessage(raw)));
add("holdConstants", "raw", null, dump({ max: HOLD_MESSAGE_MAX, def: DEFAULT_HOLD_MESSAGE, none: NO_HOLD, flagPct: FLAG_PCT }));

/* -------------------------------------------------------------------- output */

const out = process.argv[2]!;
writeFileSync(out, JSON.stringify({ cases }, null, 0), "utf8");
console.log(`wrote ${cases.length} cases to ${out}`);
