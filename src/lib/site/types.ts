/**
 * Everything the public site renders from, as one published document:
 * the rate card plus company details and page copy. Publishing writes a
 * new immutable version; the site always reads the latest one.
 */
import type { RateCard } from "@/lib/pricing/types";

export interface Company {
  name: string;
  tagline: string;
  /** Country shipped from, shown as "From". */
  origin: string;
  /** Digits only with country code, e.g. 923157667076. */
  whatsapp: string;
  phone: string;
  email: string;
  /** Shown under the prices, e.g. "Door-to-door · Pickup on request". */
  includes: string;
  /** Comma-separated pickup cities. */
  originCities: string;
  /** "Good to know" points, one per line. */
  notes: string;
}

/**
 * Every text field prints exactly as typed (brief v3: no accent, no
 * markup). Three are "blank = automatic": the site generates them from the
 * rate document (`lib/site/copy.ts`) unless the owner types his own. No
 * key is ever removed — `migrate` keeps old documents loading — but some
 * render nowhere any more and are hidden in the admin form.
 */
export interface Content {
  /** The home headline; blank = `heroTitleAuto` ("Lahore to Australia, Canada, France and Germany."). */
  heroTitle: string;
  /** The line under it; blank = `heroSubAuto` ("1 kg from PKR 5,000 · 3 to 10 days"). */
  heroSub: string;
  /** Optional points under the headline, one per line: `Label | Value`; blank = no line. */
  stats: string;
  /** One plain line under the calculator; blank hides it. */
  promise: string;
  /** The rates board caption (home + /services), e.g. "Rates". */
  routesTitle: string;
  /** Second line of the caption; blank = `boardNote` ("1 kg parcel · documents up to 0.5 kg · pickup PKR 500 included"). */
  routesNote: string;
  /** Rendered nowhere since v3; hidden in the admin form. */
  stepsTitle: string;
  /** Rendered nowhere since v3; hidden in the admin form. */
  steps: string;
  /** Rendered nowhere since v3; hidden in the admin form. */
  servicesTitle: string;
  /** /services line under the h1. */
  servicesLede: string;
  /** One per line: `icon | Title | text` (the icon token is parsed and ignored). */
  services: string;
  /** Contact line heading on inner pages, when set. */
  ctaTitle: string;
  /** Contact line sentence on inner pages, when set. */
  ctaSub: string;
  /** /contact intro line. */
  contactLede: string;
  /** /faq intro line. */
  faqLede: string;
  /** One paragraph per line. */
  story: string;
  mission: string;
  vision: string;
  /** One per line: `Value | explanation`. */
  values: string;
  address: string;
  hours: string;
  mapUrl: string;
  phone2: string;
  /** One per line: `Question? | Answer`. */
  faq: string;
}

export interface ImportProfile {
  /** Header cells normalised and joined with `|`; identifies a sheet layout. */
  signature: string;
  /** File name the layout was first seen in. */
  name: string;
  /** Field key → column index, -1 = not in file. */
  map: Record<string, number>;
  savedAt: string;
  /** Prices in this layout are carrier costs; apply the margin below. */
  cost: boolean;
  margin: number;
  mround: number;
}

export interface SiteData extends RateCard {
  company: Company;
  content: Content;
  /** Sample rates are shown with a notice until the admin ticks "rates are live". */
  live: boolean;
  importProfiles: ImportProfile[];
}

export interface PublishedVersion extends SiteData {
  version: number;
  publishedAt: string;
}
