"use client";

/**
 * What the quote page shows while prices are on hold: the admin's message
 * and a short form that turns into a WhatsApp message, so the customer can
 * still ask for a rate by hand. This component never receives a price —
 * the page that renders it does not pass the rate document at all.
 */
import { useState } from "react";
import { UI } from "@/components/Icons";
import { waLink } from "@/lib/pricing/quote";

export interface HoldDestination {
  id: string;
  name: string;
}

interface Props {
  companyName: string;
  whatsapp: string;
  message: string;
  destinations: HoldDestination[];
  maxKg: number;
}

function holdText(companyName: string, dest: string, kg: string, contents: string): string {
  const out = [`Hi ${companyName}, please price a parcel for me.`];
  if (dest) out.push(`To: ${dest}`);
  if (kg) out.push(`Weight: about ${kg} kg`);
  if (contents.trim()) out.push(`Contents: ${contents.trim()}`);
  return out.join("\n");
}

export function HoldPanel({ companyName, whatsapp, message, destinations, maxKg }: Props) {
  const [destId, setDestId] = useState("");
  const [kg, setKg] = useState("");
  const [contents, setContents] = useState("");
  const dest = destinations.find((d) => d.id === destId);
  const kgNum = Number(kg);
  const kgOk = kg === "" || (Number.isFinite(kgNum) && kgNum > 0 && kgNum <= 10_000);
  const ready = !!dest && kg !== "" && kgOk;
  const href = waLink(whatsapp, holdText(companyName, dest?.name ?? "", kgOk && kg ? String(kgNum) : "", contents));

  return (
    <div className="panel quick hold-panel">
      <h2 className="step">
        <UI.clock />
        Prices are being updated
      </h2>
      <p className="hold-msg">{message}</p>
      <label className="field">
        <span className="lab">
          <UI.pin />
          Destination country
        </span>
        <select value={destId} onChange={(e) => setDestId(e.target.value)}>
          <option value="" disabled>
            Choose a country
          </option>
          {destinations.map((d) => (
            <option key={d.id} value={d.id}>
              {d.name}
            </option>
          ))}
        </select>
      </label>
      <label className="field">
        <span className="lab">
          <UI.scale />
          Parcel weight (kg)
        </span>
        <input type="number" inputMode="decimal" min={0.1} step={0.1} max={10_000} placeholder={maxKg > 0 ? `up to ${maxKg} kg, or more for cargo` : "e.g. 2.5"} value={kg} onChange={(e) => setKg(e.target.value)} />
        {!kgOk ? <span className="hint">Enter a weight above 0.</span> : null}
      </label>
      <label className="field">
        <span className="lab">What is inside (optional)</span>
        <input type="text" maxLength={120} placeholder="Clothes, documents, gifts…" value={contents} onChange={(e) => setContents(e.target.value.slice(0, 120))} />
      </label>
      <a className={`btn wa big${ready ? "" : " soft"}`} href={href} target="_blank" rel="noopener">
        Ask for today&apos;s rate on WhatsApp
      </a>
      <p className="hint">We reply with a price by hand{ready ? "" : " — add the country and weight so we can quote straight away"}.</p>
    </div>
  );
}
