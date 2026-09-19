/**
 * The prototype's sample data, byte-for-byte in meaning. Used to seed a fresh
 * database and as the fixture the pricing tests pin their expectations to.
 * `live: false` keeps the "sample rates" notice on the site until real rates
 * are published.
 */
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
  content: {
    heroTitle: "Send anything abroad from Lahore. Priced in seconds.",
    heroSub:
      "Documents, parcels and cargo to the UK, USA, Gulf, Europe and beyond. Get an instant price, then book on WhatsApp.",
    stats: "",
    services: [
      "plane | Express international | Priority air service to most destinations in 3–6 working days, picked up from your door and delivered to the receiver’s.",
      "globe | Economy international | A lower-cost air service for parcels that are not urgent. Same door-to-door handling, a few days longer.",
      "doc | Documents | Passports, certificates, contracts and letters at a flat document rate, with tracking shared on WhatsApp.",
      "box | Cargo and bulk shipments | Over 70 kg, commercial goods or many boxes? Send us the details and we quote a cargo rate within the hour.",
      "truck | Pickup on request | We collect from homes and offices in Lahore and Faisalabad. Tell us the address when you book.",
      "shield | Packing and paperwork | Advice on safe packing, what can fly, and the customs paperwork your destination needs.",
    ].join("\n"),
    story: [
      "Speedat International Courier is based in Lahore, Pakistan. We move documents, parcels and cargo to destinations worldwide through partner airlines and express networks, collecting from your door and delivering to the receiver’s.",
      "Our slogan is “Speed Against Time”, and we mean it: clear prices before you book, pickup when you need it, and updates on WhatsApp until your shipment is delivered.",
    ].join("\n"),
    mission: "To move every shipment with speed, care and a price you knew before you booked.",
    vision: "To be the courier that families and businesses across Pakistan trust first when something has to reach abroad.",
    values: [
      "Speed | Same-day pickup before the cutoff and the fastest service that fits your budget.",
      "Honesty | The price on this page is the price at pickup, unless the parcel weighs or measures differently.",
      "Care | Your shipment is handled as if it were our own, from packing advice to the last mile.",
      "Reachability | A real person answers on WhatsApp, and you get updates at every step.",
    ].join("\n"),
    address: "Office 1, 1st Floor, Shaikh Plaza, International Market, M Block, Model Town, Lahore",
    hours: "Monday to Saturday, 9 am – 7 pm",
    mapUrl: "",
    phone2: "+92 334 77 33 328",
    faq: [
      "How is the price calculated? | By the higher of actual weight and volumetric weight (length × width × height in cm ÷ 5000), rounded up to the next 0.5 kg. Every quote shows the working.",
      "What can I not send? | Anything airlines refuse: lithium batteries on their own, aerosols, flammable liquids, perfumes over the allowed limit, cash, and perishable food. Ask us on WhatsApp if you are unsure.",
      "Who pays duties and taxes at the destination? | The receiver, if the destination charges them. We tell you when a country usually does.",
      "How do I book? | Get a quote here, tap “Book on WhatsApp”, and send the message. We confirm the pickup time and the receiver details in the chat.",
      "How do I track my shipment? | We share the tracking number and updates with you on WhatsApp as soon as the shipment is handed to the airline.",
    ].join("\n"),
  },
  importProfiles: [],
};
