/**
 * The prototype's sample data, byte-for-byte in meaning. Used to seed a fresh
 * database and as the fixture the pricing tests pin their expectations to.
 * `live: false` keeps the "sample rates" notice on the site until real rates
 * are published.
 */
import { slabToGrid } from "@/lib/pricing/engine";
import type { Destination } from "@/lib/pricing/types";
import type { SiteData } from "./types";

function d(
  id: string,
  name: string,
  ef: number,
  ea: number,
  ed: string,
  nf: number,
  na: number,
  nd: string,
  edoc: number,
  ndoc: number,
): Destination {
  return {
    id,
    name,
    active: true,
    rates: {
      express: { first: ef, addl: ea, days: ed, doc: edoc },
      normal: { first: nf, addl: na, days: nd, doc: ndoc },
    },
  };
}

export const SEED: SiteData = {
  live: false,
  company: {
    name: "Speedat International Courier",
    tagline: "Speed Against Time",
    origin: "Pakistan",
    whatsapp: "923157667076",
    phone: "+92 315 766 7076",
    email: "info@speedat.net",
    includes: "Door-to-door delivery · Pickup on request · Tracking shared on WhatsApp",
    originCities: "Lahore, Faisalabad",
    notes: [
      "Have the receiver's full address and phone number ready.",
      "Some items cannot be sent by air (batteries, liquids, perishables) — ask us if unsure.",
      "Any duties or taxes charged at the destination are paid by the receiver.",
    ].join("\n"),
  },
  settings: {
    pricingMode: "slab",
    currency: "PKR",
    volumetricDivisor: 5000,
    firstKg: 0.5,
    stepKg: 0.5,
    docMaxKg: 0.5,
    taxPct: 0,
    roundTo: 10,
    maxKg: 70,
    showEta: true,
    cutoffHour: 15,
    workingDays: "Mon, Tue, Wed, Thu, Fri, Sat",
    holidays: "",
    addons: "Pickup and service charges | 500 | on",
    disclaimer:
      "Prices are for the weight and size entered here and are confirmed when your shipment is weighed and measured at pickup. Delivery dates are estimates and exclude customs delays.",
  },
  services: [
    { id: "express", name: "Express", note: "Priority air service, door to door" },
    { id: "normal", name: "Normal", note: "Economy air service, door to door" },
  ],
  destinations: [
    d("gb", "United Kingdom", 4500, 1100, "3–5", 3200, 850, "6–9", 3900, 2800),
    d("us", "United States", 5200, 1300, "4–6", 3800, 950, "7–10", 4500, 3300),
    d("ca", "Canada", 5400, 1350, "4–6", 3900, 980, "7–10", 4700, 3400),
    d("au", "Australia", 5600, 1400, "4–6", 4000, 1000, "7–10", 4900, 3500),
    d("ae", "United Arab Emirates", 3200, 800, "2–3", 2400, 600, "4–6", 2800, 2100),
    d("sa", "Saudi Arabia", 3400, 850, "2–4", 2500, 650, "4–7", 3000, 2200),
    d("de", "Germany", 4700, 1150, "3–5", 3300, 880, "6–9", 4100, 2900),
    d("it", "Italy", 4700, 1150, "3–5", 3300, 880, "6–9", 4100, 2900),
    d("tr", "Türkiye", 4300, 1050, "3–5", 3100, 800, "6–9", 3700, 2700),
    d("my", "Malaysia", 4100, 1000, "3–5", 3000, 780, "6–9", 3600, 2600),
  ],
  // Brief v3 §1: every customer sentence carries a place, a number or a day count, or it is generated from the
  // rate document. Blank = automatic for heroTitle (heroTitleAuto), heroSub (heroSubAuto) and routesNote
  // (boardNote). No number that lives in settings or company is typed here; the page computes it.
  content: {
    heroTitle: "",
    heroSub: "",
    stats: "",
    promise: "Confirmed at pickup when we weigh and measure the parcel. Duties at the destination, if any, are paid by the receiver.",
    routesTitle: "Rates",
    routesNote: "",
    // Hidden in the admin form and rendered nowhere; the keys stay so old documents load.
    stepsTitle: "",
    steps: "",
    servicesTitle: "",
    servicesLede: "Two air services from Lahore, both door to door: Express and Normal. Documents have a flat rate. Heavier cargo is quoted on WhatsApp.",
    ctaTitle: "",
    ctaSub: "",
    contactLede: "One WhatsApp number. We answer during working hours and confirm every pickup in the chat.",
    faqLede: "",
    // `icon | Title | text`: the icon token is parsed and ignored (v3 prints no icons); /services adds the cities,
    // the cutoff hour, docMaxKg and maxKg itself.
    services: [
      "plane | Express | Priority air. The faster of the two on every route; the days are in the table.",
      "globe | Normal | Economy air. Same handling, a few days longer, the lower price.",
      "doc | Documents | Passports, certificates, contracts. A flat rate per envelope, in the table.",
      "box | Cargo | Above the calculator's top weight, commercial goods or many boxes: send the details on WhatsApp and we quote within the hour.",
      "truck | Pickup | We collect from your door in our pickup cities; book before the cutoff and we come the same day.",
      "shield | Packing and customs | We tell you what can fly and what paperwork the destination needs.",
    ].join("\n"),
    story: [
      "Speedat International Courier is a courier office in Model Town, Lahore. We send documents, parcels and cargo abroad through partner airlines and express networks, collecting from your door and delivering to the receiver's.",
      "The price on this site is the price we charge: you see it before you book, we confirm it at pickup when the parcel is weighed, and we send the tracking on WhatsApp once the shipment is with the airline.",
    ].join("\n"),
    mission: "",
    vision: "",
    values: "",
    address: "Office 1, 1st Floor, Shaikh Plaza, International Market, M Block, Model Town, Lahore",
    hours: "Monday to Saturday, 9 am – 7 pm",
    mapUrl: "",
    phone2: "+92 334 77 33 328",
    faq: [
      "How is the price worked out? | By weight: the higher of the scale weight and the volumetric weight (length × width × height), rounded up to the next half kilo. The detailed price shows the working.",
      "What cannot be sent? | What airlines refuse: loose lithium batteries, aerosols, flammable liquids, perfume above the limit, cash, fresh food. Ask on WhatsApp if unsure.",
      "Who pays duties at the destination? | The receiver, when the country charges them.",
      "How do I book? | Tap Book on WhatsApp under the price and send the message. We confirm the pickup time in the chat.",
      "How do I track it? | We send the tracking number on WhatsApp as soon as the airline has the shipment.",
    ].join("\n"),
  },
  importProfiles: [],
};

/**
 * The sample data as it ships to a new site: per-kilogram price boxes up to
 * the 25 kg cargo threshold, each box filled from the slab prices above so
 * the numbers stay the ones the prototype was checked against.
 */
export function gridSeed(): SiteData {
  const s = structuredClone(SEED);
  s.settings.pricingMode = "grid";
  s.settings.maxKg = 25;
  for (const dest of s.destinations) {
    for (const rate of Object.values(dest.rates)) if (rate) rate.grid = slabToGrid(s.settings, rate);
  }
  return s;
}
