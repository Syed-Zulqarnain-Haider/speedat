/**
 * Bring a stored document up to the current shape. Old snapshots (and the
 * prototype's exported data) may lack fields added later; every missing
 * field gets the same default the prototype used, so restoring version 1
 * years from now still renders.
 *
 * Website text that still reads exactly as an earlier round's default was
 * never the owner's own words, so it follows the current default (the v3
 * headline is generated from the rate document; a v2 default kept verbatim
 * would print its accent asterisks). Anything the owner typed himself is
 * left exactly as typed.
 */
import { SEED } from "./seed";
import type { Content, SiteData } from "./types";

type Loose = Partial<SiteData> & { settings?: Partial<SiteData["settings"]>; company?: Partial<SiteData["company"]> };

/** Every default a content field had in an earlier round (v1 "Manifest", v2 "Three taps"), keyed by field. */
export const RETIRED_DEFAULTS: Partial<Record<keyof Content, readonly string[]>> = {
  heroTitle: ["Send anything *abroad* from Lahore. Priced in seconds.", "Send anything *abroad*. See your price now."],
  heroSub: [
    "Documents, parcels and cargo, collected from your door and delivered to the receiver’s. Get an instant price, then book on WhatsApp.",
    "Tap your country and the weight. Your price appears — then book on WhatsApp.",
  ],
  promise: [
    "The price you see here is the price at pickup, unless the parcel weighs or measures differently.",
    "The price you see is the price at pickup, unless the parcel weighs or measures differently.",
  ],
  routesTitle: ["Where we fly from Lahore", "Where we deliver"],
  routesNote: [
    "Fastest service in working days. 1 kg parcel, shipping only; pickup charges are added in the quote.",
    "Price for a 1 kg parcel with the cheapest service. Pickup is added in your price.",
  ],
  stepsTitle: ["Three steps, no surprises", "Three taps, one price"],
  steps: [
    [
      "Price it | Choose the destination and weight. The price on this page is the price at pickup unless the parcel weighs or measures differently.",
      "Book on WhatsApp | Tap Book, send the ready-made message, and we confirm the pickup time and receiver details in the chat.",
      "Track to the door | We share the tracking number and updates on WhatsApp until it is delivered.",
    ].join("\n"),
    [
      "Tap your country | Pick where the parcel is going.",
      "Tap the weight | 1 to 25 kg. Not sure? Pick the nearest — we weigh it at pickup.",
      "See the price and book | Tap the green button. We reply on WhatsApp and collect from your door.",
    ].join("\n"),
  ],
  servicesTitle: ["What we carry", "Two ways to send"],
  servicesLede: ["Every service is door to door: we collect from you and deliver to the receiver. Prices are on the quote page."],
  ctaTitle: ["Ready to send something?", "Ready to send?"],
  ctaSub: ["Get an instant price and book on WhatsApp in two minutes.", "Tap WhatsApp or call. A person answers."],
  contactLede: ["WhatsApp is the fastest way to reach us. We reply during working hours and confirm every pickup in the chat."],
  faqLede: ["If yours is not here, ask us on WhatsApp."],
  services: [
    [
      "plane | Express international | Priority air service to most destinations in 3–6 working days, picked up from your door and delivered to the receiver’s.",
      "globe | Economy international | A lower-cost air service for parcels that are not urgent. Same door-to-door handling, a few days longer.",
      "doc | Documents | Passports, certificates, contracts and letters at a flat document rate, with tracking shared on WhatsApp.",
      "box | Cargo and bulk shipments | Over 70 kg, commercial goods or many boxes? Send us the details and we quote a cargo rate within the hour.",
      "truck | Pickup on request | We collect from homes and offices in Lahore and Faisalabad. Tell us the address when you book.",
      "shield | Packing and paperwork | Advice on safe packing, what can fly, and the customs paperwork your destination needs.",
    ].join("\n"),
    [
      "plane | Express international | Priority air service to most destinations in 3–6 working days, picked up from your door and delivered to the receiver’s.",
      "globe | Economy international | A lower-cost air service for parcels that are not urgent. Same door-to-door handling, a few days longer.",
      "doc | Documents | Passports, certificates, contracts and letters at a flat document rate, with tracking shared on WhatsApp.",
      "box | Cargo and bulk shipments | A very heavy parcel, commercial goods or many boxes? Send us the details and we quote a cargo rate within the hour.",
      "truck | Pickup on request | We collect from homes and offices in Lahore and Faisalabad. Tell us the address when you book.",
      "shield | Packing and paperwork | Advice on safe packing, what can fly, and the customs paperwork your destination needs.",
    ].join("\n"),
  ],
  story: [
    [
      "Speedat International Courier is based in Lahore, Pakistan. We move documents, parcels and cargo to destinations worldwide through partner airlines and express networks, collecting from your door and delivering to the receiver’s.",
      "Our slogan is “Speed Against Time”, and we mean it: clear prices before you book, pickup when you need it, and updates on WhatsApp until your shipment is delivered.",
    ].join("\n"),
  ],
  mission: ["To move every shipment with speed, care and a price you knew before you booked."],
  vision: ["To be the courier that families and businesses across Pakistan trust first when something has to reach abroad."],
  values: [
    [
      "Speed | Same-day pickup before the cutoff and the fastest service that fits your budget.",
      "Honesty | The price on this page is the price at pickup, unless the parcel weighs or measures differently.",
      "Care | Your shipment is handled as if it were our own, from packing advice to the last mile.",
      "Reachability | A real person answers on WhatsApp, and you get updates at every step.",
    ].join("\n"),
  ],
  faq: [
    [
      "How is the price calculated? | By the higher of actual weight and volumetric weight (length × width × height in cm ÷ 5000), rounded up to the next 0.5 kg. Every quote shows the working.",
      "What can I not send? | Anything airlines refuse: lithium batteries on their own, aerosols, flammable liquids, perfumes over the allowed limit, cash, and perishable food. Ask us on WhatsApp if you are unsure.",
      "Who pays duties and taxes at the destination? | The receiver, if the destination charges them. We tell you when a country usually does.",
      "How do I book? | Get a quote here, tap “Book on WhatsApp”, and send the message. We confirm the pickup time and the receiver details in the chat.",
      "How do I track my shipment? | We share the tracking number and updates with you on WhatsApp as soon as the shipment is handed to the airline.",
    ].join("\n"),
  ],
};

/** A stored text that is exactly an earlier round's default becomes the current default; typed text is kept. */
export function upgradeContent(content: Content): Content {
  const out = { ...content };
  for (const key of Object.keys(RETIRED_DEFAULTS) as (keyof Content)[]) {
    const retired = RETIRED_DEFAULTS[key];
    if (retired?.some((old) => old.trim() === String(out[key] ?? "").trim())) out[key] = SEED.content[key];
  }
  return out;
}

export function migrate(input: SiteData | Loose): SiteData {
  const s = structuredClone(input) as Loose;
  const settings = { ...SEED.settings, ...(s.settings ?? {}) };
  // The prototype stored a blank cutoff as ""; we store null.
  const rawCutoff = (s.settings as { cutoffHour?: unknown } | undefined)?.cutoffHour;
  settings.cutoffHour = rawCutoff === "" || rawCutoff == null ? null : Number(rawCutoff);
  if (!Number.isFinite(settings.cutoffHour as number)) settings.cutoffHour = null;
  if (settings.docMaxKg == null) settings.docMaxKg = settings.firstKg;
  if (settings.pricingMode !== "grid") settings.pricingMode = "slab";
  const company = { ...SEED.company, ...(s.company ?? {}) };
  const content = upgradeContent({ ...SEED.content, ...(s.content ?? {}) });
  return {
    live: !!s.live,
    company,
    settings,
    services: s.services?.length ? s.services : structuredClone(SEED.services),
    destinations: s.destinations ?? [],
    content,
    importProfiles: s.importProfiles ?? [],
  };
}
