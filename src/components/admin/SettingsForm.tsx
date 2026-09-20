"use client";

import type { SiteData } from "@/lib/site/types";
import { Area, Fld, numOrNull } from "./fields";

interface Props {
  draft: SiteData;
  readOnly: boolean;
  update: (fn: (d: SiteData) => void) => void;
  epoch: number;
}

export function SettingsForm({ draft, readOnly, update, epoch }: Props) {
  const c = draft.company;
  const st = draft.settings;
  const setCompany = (k: keyof typeof c) => (raw: string) =>
    update((d) => {
      d.company[k] = k === "whatsapp" ? raw.replace(/[^0-9]/g, "") : raw;
    });
  const setNum = (k: "volumetricDivisor" | "firstKg" | "stepKg" | "taxPct" | "roundTo" | "maxKg" | "docMaxKg") => (raw: string) => {
    const v = numOrNull(raw);
    if (v === undefined) return;
    update((d) => {
      d.settings[k] = v ?? 0;
    });
  };
  return (
    <section className="block" id="sec-settings" key={epoch}>
      <h2>Settings</h2>
      <p className="desc">Changes here apply to every destination.</p>
      <div className="grid2">
        <Fld label="Company name" value={c.name} onChange={setCompany("name")} disabled={readOnly} />
        <Fld label="Tagline" value={c.tagline} onChange={setCompany("tagline")} disabled={readOnly} />
        <Fld label="WhatsApp number (country code, digits only)" type="tel" value={c.whatsapp} onChange={setCompany("whatsapp")} placeholder="923001234567" disabled={readOnly} />
        <Fld label="Phone shown on site" value={c.phone} onChange={setCompany("phone")} disabled={readOnly} />
        <Fld label="Email shown on site" type="email" value={c.email} onChange={setCompany("email")} disabled={readOnly} />
        <Fld label="Shipping from" value={c.origin} onChange={setCompany("origin")} disabled={readOnly} />
        <Fld label="What is included (shown under prices)" value={c.includes} onChange={setCompany("includes")} placeholder="Door-to-door · Pickup · Tracking" disabled={readOnly} />
        <Fld label="Pickup cities, comma-separated (shown as “From”)" value={c.originCities} onChange={setCompany("originCities")} placeholder="Lahore, Faisalabad" disabled={readOnly} />
        <label className="field">
          <span>Pricing model</span>
          <select
            defaultValue={st.pricingMode}
            disabled={readOnly}
            onChange={(e) =>
              update((d) => {
                d.settings.pricingMode = e.target.value === "grid" ? "grid" : "slab";
              })
            }
          >
            <option value="grid">Price per kilogram (1 kg … cargo threshold)</option>
            <option value="slab">First slab + each additional step</option>
          </select>
          <span className="hint">Per-kilogram: every country gets a price box for each whole kg; parcels are charged at the next whole kg up.</span>
        </label>
        <Fld
          label="Currency shown with prices (e.g. PKR or Rs.)"
          value={st.currency}
          onChange={(raw) =>
            update((d) => {
              d.settings.currency = raw.trim();
            })
          }
          disabled={readOnly}
        />
        <Fld label="Volumetric divisor (L×W×H ÷ this = kg)" type="number" value={st.volumetricDivisor} onChange={setNum("volumetricDivisor")} disabled={readOnly} />
        <Fld label="First weight slab (kg)" type="number" step="0.1" value={st.firstKg} onChange={setNum("firstKg")} disabled={readOnly} />
        <Fld label="Additional weight step (kg)" type="number" step="0.1" value={st.stepKg} onChange={setNum("stepKg")} disabled={readOnly} />
        <Fld label="Tax added on top (%)" type="number" step="0.5" value={st.taxPct} onChange={setNum("taxPct")} disabled={readOnly} />
        <Fld label="Round final price to nearest" type="number" step="1" value={st.roundTo} onChange={setNum("roundTo")} disabled={readOnly} />
        <Fld label="Cargo threshold (kg) — above this, customers are asked to message you (0 = no limit)" type="number" step="1" value={st.maxKg} onChange={setNum("maxKg")} disabled={readOnly} />
        <Fld label="Document rate applies up to (kg)" type="number" step="0.1" value={st.docMaxKg} onChange={setNum("docMaxKg")} disabled={readOnly} />
        <Fld
          label="Same-day pickup cutoff hour, 0–23 (blank = none)"
          type="number"
          step="1"
          value={st.cutoffHour}
          placeholder="15"
          onChange={(raw) => {
            const v = numOrNull(raw);
            if (v === undefined) return;
            update((d) => {
              d.settings.cutoffHour = v;
            });
          }}
          disabled={readOnly}
        />
        <Fld
          label="Working days counted for delivery estimates"
          value={st.workingDays}
          placeholder="Mon, Tue, Wed, Thu, Fri, Sat"
          onChange={(raw) =>
            update((d) => {
              d.settings.workingDays = raw;
            })
          }
          disabled={readOnly}
        />
      </div>
      <Area
        label="Holidays skipped by delivery estimates (one per line: YYYY-MM-DD | name)"
        value={st.holidays}
        placeholder={["2026-12-25 | Quaid-e-Azam Day", "2027-03-23 | Pakistan Day"].join("\n")}
        onChange={(raw) =>
          update((d) => {
            d.settings.holidays = raw;
          })
        }
        disabled={readOnly}
      />
      <Area
        label="Optional charges customers can tick (one per line: Label | Amount | on or off = ticked by default)"
        value={st.addons}
        placeholder="Pickup and service charges | 500 | on"
        onChange={(raw) =>
          update((d) => {
            d.settings.addons = raw;
          })
        }
        disabled={readOnly}
      />
      <label className="chk" style={{ margin: "4px 0 12px" }}>
        <input
          type="checkbox"
          defaultChecked={st.showEta}
          disabled={readOnly}
          onChange={(e) =>
            update((d) => {
              d.settings.showEta = e.target.checked;
            })
          }
        />{" "}
        Show estimated delivery dates on quotes (transit days counted on the working days above, from the pickup date)
      </label>
      <Area
        label="Small print under the prices"
        value={st.disclaimer}
        onChange={(raw) =>
          update((d) => {
            d.settings.disclaimer = raw;
          })
        }
        disabled={readOnly}
      />
      <Area label="Good to know before you book (one point per line, shown under the prices)" value={c.notes} onChange={setCompany("notes")} disabled={readOnly} />
    </section>
  );
}
