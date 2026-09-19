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

export interface Content {
  heroTitle: string;
  heroSub: string;
  /** Optional numbers strip, one per line: `Label | Value`; blank = automatic. */
  stats: string;
  /** One per line: `icon | Title | Description`. */
  services: string;
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
