/**
 * Zod schema for a site document as sent by the admin UI. Shapes and sizes
 * are checked here; business rules (positive prices, one visible destination)
 * live in `validateSite` because the draft may legitimately be mid-edit.
 */
import { z } from "zod";
import type { SiteData } from "./types";

const num = z.number().finite();
const money = num.min(0).max(100_000_000).nullable().optional();
const text = (max: number) => z.string().max(max);

export const RateSchema = z.object({
  first: money,
  addl: money,
  days: text(40).optional(),
  doc: money,
});

export const DestinationSchema = z.object({
  id: text(60).regex(/^[a-z0-9-]+$/),
  name: text(120),
  active: z.boolean(),
  rates: z.record(text(40), RateSchema.optional()),
});

export const SettingsSchema = z.object({
  currency: text(8),
  volumetricDivisor: num,
  firstKg: num,
  stepKg: num,
  docMaxKg: num,
  taxPct: num,
  roundTo: num,
  maxKg: num,
  showEta: z.boolean(),
  cutoffHour: num.nullable(),
  workingDays: text(120),
  addons: text(2000),
  disclaimer: text(2000),
});

export const CompanySchema = z.object({
  name: text(120),
  tagline: text(120),
  origin: text(80),
  whatsapp: text(20),
  phone: text(40),
  email: text(120),
  includes: text(300),
  originCities: text(300),
  notes: text(3000),
});

export const ContentSchema = z.object({
  heroTitle: text(200),
  heroSub: text(400),
  stats: text(1000),
  services: text(6000),
  story: text(6000),
  mission: text(600),
  vision: text(600),
  values: text(3000),
  address: text(300),
  hours: text(120),
  mapUrl: text(500),
  phone2: text(40),
  faq: text(12000),
});

export const ImportProfileSchema = z.object({
  signature: text(4000),
  name: text(200),
  map: z.record(text(60), z.number().int()),
  savedAt: text(40),
  cost: z.boolean(),
  margin: num,
  mround: num,
});

export const SiteDataSchema: z.ZodType<SiteData> = z.object({
  live: z.boolean(),
  company: CompanySchema,
  settings: SettingsSchema,
  services: z.array(z.object({ id: text(40).regex(/^[a-z0-9-]+$/), name: text(60), note: text(200) })).max(6),
  destinations: z.array(DestinationSchema).max(500),
  content: ContentSchema,
  importProfiles: z.array(ImportProfileSchema).max(20),
});
