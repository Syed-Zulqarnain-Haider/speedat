"use client";

/**
 * The customer calculator: "Quick rate" (country + weight) and "Detailed
 * quote" (pieces, sizes, documents, ship date). All pricing happens here in
 * the browser with the published document handed in as props; the only
 * network call is a fire-and-forget log when someone taps Book.
 */
import { Fragment, useMemo, useState, type ReactNode } from "react";
import { UI } from "@/components/Icons";
import { setSession, useMounted, useSession } from "@/lib/client/session";
import { addonsList, gToKg, kgToG, lines, priceAll, toNumLoose } from "@/lib/pricing/engine";
import { estimateDelivery, parseDaysRange, type DeliveryEstimate } from "@/lib/pricing/dates";
import { fmtDay, fmtHour, fmtMoney, fmtNum, fmtRange, inputDate, localDateFromInput, quoteId, quoteIdFor } from "@/lib/pricing/format";
import { IN, LB, cargoText, piecesText, quoteText, waLink, weightSentence, type Quote, type Units } from "@/lib/pricing/quote";
import type { Addon, PieceInput, PriceResult, ServicePrice, ShipmentType } from "@/lib/pricing/types";
import type { PublishedVersion } from "@/lib/site/types";
import { originCities } from "@/lib/site/text";
import { Toast, useToast } from "./Toast";

type Mode = "quick" | "detail";

interface PieceRow {
  key: number;
  kg: string;
  qty: string;
  L: string;
  W: string;
  H: string;
}

const newRow = (key: number, kg = ""): PieceRow => ({ key, kg, qty: "1", L: "", W: "", H: "" });

function weightOptions(maxKg: number): number[] {
  const out: number[] = [];
  for (let k = 0.5; k <= 10; k += 0.5) out.push(k);
  for (let k = 11; k <= 30; k += 1) out.push(k);
  const top = maxKg > 0 ? Math.min(maxKg, 200) : 100;
  for (let k = 35; k <= top; k += 5) out.push(k);
  return out.filter((x) => !(maxKg > 0) || x <= maxKg);
}

function logQuote(q: Quote, extra: Record<string, unknown>): void {
  try {
    void fetch("/api/quotes", {
      method: "POST",
      keepalive: true,
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ ...q, ...extra }),
    });
  } catch {
    /* logging never blocks a booking */
  }
}

interface Remembered {
  destId?: string;
  kg?: string;
}

function readRemembered(raw: string | null): Remembered {
  if (!raw) return {};
  try {
    const v = JSON.parse(raw) as Remembered;
    return typeof v === "object" && v ? v : {};
  } catch {
    return {};
  }
}

export function Calculator({ site }: { site: PublishedVersion }) {
  const sets = site.settings;
  const cur = sets.currency;
  const addonDefs = useMemo(() => addonsList(sets), [sets]);
  const cities = useMemo(() => originCities(site.company), [site.company]);
  const activeIds = useMemo(() => new Set(site.destinations.filter((x) => x.active).map((x) => x.id)), [site.destinations]);

  const storedMode = useSession("sp-mode");
  const mode: Mode = storedMode === "detail" ? "detail" : "quick";
  const remembered = readRemembered(useSession("sp-last"));
  const destId = remembered.destId && activeIds.has(remembered.destId) ? remembered.destId : "";
  const setDestId = (id: string) => setSession("sp-last", JSON.stringify({ ...remembered, destId: id }));
  const rememberKg = (kg: string) => setSession("sp-last", JSON.stringify({ ...remembered, kg }));

  const [addons, setAddons] = useState<Record<string, boolean>>(() => Object.fromEntries(addonDefs.map((a) => [a.id, a.on])));
  const [toast, showToast] = useToast();
  const selectedAddons = addonDefs.filter((a) => addons[a.id]);
  const addonsTotal = selectedAddons.reduce((t, a) => t + a.amount, 0);

  const switchMode = (m: Mode) => setSession("sp-mode", m);

  const addonsBox = addonDefs.length ? (
    <div className="addons">
      {addonDefs.map((a) => (
        <label key={a.id}>
          <input type="checkbox" checked={!!addons[a.id]} onChange={(e) => setAddons((s) => ({ ...s, [a.id]: e.target.checked }))} /> +
          {fmtMoney(a.amount, cur)} {a.label}
        </label>
      ))}
    </div>
  ) : null;

  return (
    <>
      <div className="modes" role="tablist" aria-label="Quote type">
        <button type="button" role="tab" aria-selected={mode === "quick"} onClick={() => switchMode("quick")}>
          Quick rate
        </button>
        <button type="button" role="tab" aria-selected={mode === "detail"} onClick={() => switchMode("detail")}>
          Detailed quote
        </button>
      </div>
      <div hidden={mode !== "quick"}>
        <QuickRate
          site={site}
          destId={destId}
          setDestId={setDestId}
          selectedAddons={selectedAddons}
          addonsTotal={addonsTotal}
          addonsBox={addonsBox}
          onExact={() => switchMode("detail")}
        />
      </div>
      <div hidden={mode !== "detail"}>
        <DetailedQuote
          site={site}
          cities={cities}
          destId={destId}
          setDestId={setDestId}
          initialKg={remembered.kg ?? ""}
          rememberKg={rememberKg}
          selectedAddons={selectedAddons}
          addonsTotal={addonsTotal}
          addonsBox={addonsBox}
          showToast={showToast}
        />
      </div>
      {site.company.includes ? <p className="includes">{site.company.includes}</p> : null}
      {sets.disclaimer ? <p className="disclaimer">{sets.disclaimer}</p> : null}
      {lines(site.company.notes).length ? (
        <details className="how notes">
          <summary>Good to know before you book</summary>
          <ul>
            {lines(site.company.notes).map((x, i) => (
              <li key={i}>{x}</li>
            ))}
          </ul>
        </details>
      ) : null}
      <Toast message={toast} />
    </>
  );
}

/* ---------------- destination select ---------------- */

function DestOptions({ site }: { site: PublishedVersion }) {
  const act = site.destinations.filter((x) => x.active);
  const alpha = act.slice().sort((a, b) => a.name.localeCompare(b.name));
  const opt = (x: { id: string; name: string }) => (
    <option key={x.id} value={x.id}>
      {x.name}
    </option>
  );
  const placeholder = (
    <option value="" disabled>
      Choose a country
    </option>
  );
  if (act.length <= 8)
    return (
      <>
        {placeholder}
        {alpha.map(opt)}
      </>
    );
  return (
    <>
      {placeholder}
      <optgroup label="Popular">{act.slice(0, 5).map(opt)}</optgroup>
      <optgroup label="All destinations">{alpha.map(opt)}</optgroup>
    </>
  );
}

/* ---------------- quick rate ---------------- */

interface QuickProps {
  site: PublishedVersion;
  destId: string;
  setDestId: (id: string) => void;
  selectedAddons: Addon[];
  addonsTotal: number;
  addonsBox: ReactNode;
  onExact: () => void;
}

function etaQuick(p: ServicePrice, sets: PublishedVersion["settings"]): string {
  const est = sets.showEta && p.days ? estimateDelivery(p.days, sets, null, new Date()) : null;
  if (est) return `${p.days ? `${p.days} days · ` : ""}delivered ${fmtRange(est)}`;
  return p.days ? `${p.days} days working` : "";
}

function QuickRate({ site, destId, setDestId, selectedAddons, addonsTotal, addonsBox, onExact }: QuickProps) {
  const sets = site.settings;
  const cur = sets.currency;
  const [kgv, setKgv] = useState("");
  const [svc, setSvc] = useState("");
  // Quote ids are derived from a per-tab salt plus the inputs, so a combination keeps its id while it is on screen.
  const [salt] = useState(() => Math.random().toString(36).slice(2));

  const dest = site.destinations.find((x) => x.id === destId && x.active);
  const res: PriceResult | null =
    destId && kgv && kgv !== "more" && kgv !== "exact" ? priceAll(site, { destId, type: "pkg", rows: [{ kg: Number(kgv), qty: 1 }] }) : null;
  const available = res?.ok ? site.services.filter((s) => res.prices[s.id]).map((s) => s.id) : [];
  const chosen = available.includes(svc) ? svc : (available[0] ?? "");

  let cheapest = "";
  let fastest = "";
  if (res?.ok && available.length > 1) {
    cheapest = available.reduce((b, id) => (res.prices[id]!.total < res.prices[b]!.total ? id : b));
    fastest = available.reduce((b, id) => {
      const ra = parseDaysRange(res.prices[id]!.days);
      const rb = parseDaysRange(res.prices[b]!.days);
      return ra && (!rb || ra[0] < rb[0]) ? id : b;
    });
    if (!parseDaysRange(res.prices[fastest]!.days)) fastest = "";
  }

  const p = res?.ok && chosen ? res.prices[chosen]! : null;
  const sum = p ? p.total + addonsTotal : 0;

  let note: string;
  let idle = true;
  if (!destId) note = "Select destination and weight to calculate";
  else if (!kgv || kgv === "exact") note = "Select the parcel weight to calculate";
  else if (kgv === "more") note = `Over ${sets.maxKg} kg is priced as cargo — tap Live chat and we quote within the hour`;
  else if (!res?.ok) note = "This weight needs a cargo rate — tap Live chat";
  else if (!available.length) note = "No service available for this destination yet";
  else {
    idle = false;
    const sv = site.services.find((s) => s.id === chosen)!;
    const est = sets.showEta && p?.days ? estimateDelivery(p.days, sets, null, new Date()) : null;
    note = `${sv.name} · ${kgv} kg to ${dest?.name}${est ? ` · delivered ${fmtRange(est)}` : p?.days ? ` · ${p.days} working days` : ""}`;
  }

  let bookHref: string | null = null;
  let bookLabel: ReactNode = (
    <>
      <UI.box />
      Book now
    </>
  );
  let quote: Quote | null = null;
  if (kgv === "more" && dest) {
    const w = { billableG: kgToG(sets.maxKg), actualG: kgToG(sets.maxKg), volG: 0, chargeG: 0, pieces: 0, volumetricWins: false, lines: [] };
    bookHref = waLink(site.company.whatsapp, cargoText(site.company.name, "", dest.name, w, "metric"));
    bookLabel = "Ask for a cargo rate";
  } else if (p && res?.ok && dest) {
    const sv = site.services.find((s) => s.id === chosen)!;
    const est = sets.showEta && p.days ? estimateDelivery(p.days, sets, null, new Date()) : null;
    quote = {
      id: quoteIdFor(`${salt}|${dest.id}|${kgv}|${chosen}`),
      from: "",
      dest: dest.name,
      destId: dest.id,
      type: "pkg",
      service: sv.name,
      serviceId: chosen,
      days: p.days,
      eta: est ? fmtRange(est) : "",
      pickup: est ? fmtDay(est.pickup) : "",
      pieces: 1,
      piecesText: `1 × ${kgv} kg`,
      billableG: res.weights.billableG,
      total: p.total,
      docRate: false,
      version: site.version,
    };
    bookHref = waLink(site.company.whatsapp, quoteText(quote, { companyName: site.company.name, currency: cur, addons: selectedAddons }));
  }

  const onKg = (v: string) => {
    if (v === "exact") {
      setKgv("");
      onExact();
      return;
    }
    setKgv(v);
  };

  return (
    <div className="panel quick">
      <h2 className="step">
        <UI.calc />
        Check your rate
      </h2>
      <label className="field">
        <span className="lab">
          <UI.pin />
          Destination country
        </span>
        <select value={destId} onChange={(e) => setDestId(e.target.value)}>
          <DestOptions site={site} />
        </select>
      </label>
      <label className="field">
        <span className="lab">
          <UI.scale />
          Parcel weight (kg)
        </span>
        <select value={kgv} onChange={(e) => onKg(e.target.value)}>
          <option value="" disabled>
            Choose weight
          </option>
          {weightOptions(sets.maxKg).map((k) => (
            <option key={k} value={String(k)}>
              {k} kg
            </option>
          ))}
          {sets.maxKg > 0 ? <option value="more">More than {sets.maxKg} kg (cargo rate)</option> : null}
          <option value="exact">Exact weight, box size or documents…</option>
        </select>
      </label>
      <div className="chips" role="group" aria-label="Common weights">
        {[1, 2, 5, 10, 20]
          .filter((k) => !(sets.maxKg > 0) || k <= sets.maxKg)
          .map((k) => (
            <button key={k} type="button" className="chip" aria-pressed={kgv === String(k)} onClick={() => setKgv(String(k))}>
              {k} kg
            </button>
          ))}
      </div>
      <fieldset className="opts">
        <legend className="lab">
          <UI.clock />
          Delivery option
        </legend>
        <div>
          {site.services.map((sv) => {
            const sp = res?.ok ? res.prices[sv.id] : null;
            const on = !!sp;
            return (
              <label key={sv.id} className={`opt${on ? "" : " dim"}`}>
                <input type="radio" name="q-svc" value={sv.id} disabled={!on} checked={on && chosen === sv.id} onChange={() => setSvc(sv.id)} />
                <span className="opt-name">
                  {sv.name}
                  {on && sv.id === fastest ? <span className="opt-badge">Fastest</span> : null}
                  {on && sv.id === cheapest && sv.id !== fastest ? <span className="opt-badge price">Best price</span> : null}
                </span>
                <span className="opt-eta">{sp ? etaQuick(sp, sets) : sv.note}</span>
                <span className="opt-price">{sp ? fmtMoney(sp.total, cur) : "—"}</span>
              </label>
            );
          })}
        </div>
      </fieldset>
      {addonsBox}
      {/* Keyed on the amount so the pop animation replays whenever the total changes. */}
      <div key={idle ? "idle" : String(sum)} className={`total${idle ? " idle" : " pop"}`} aria-live="polite">
        <span className="tlabel">Total charges</span>
        <span className="tval">{idle ? `${cur} 0` : fmtMoney(sum, cur)}</span>
        {!idle && addonsTotal && p ? (
          <div className="tlines">
            <div>
              <span>{site.services.find((s) => s.id === chosen)?.name} shipping</span>
              <span>{fmtMoney(p.total, cur)}</span>
            </div>
            {selectedAddons.map((a) => (
              <div key={a.id}>
                <span>{a.label}</span>
                <span>{fmtMoney(a.amount, cur)}</span>
              </div>
            ))}
            <div className="sum">
              <span>Total</span>
              <span>{fmtMoney(sum, cur)}</span>
            </div>
          </div>
        ) : null}
        <span className="tnote">{note}</span>
      </div>
      <div className="ctas">
        <a className="btn chat" href={`https://wa.me/${site.company.whatsapp}`} target="_blank" rel="noopener">
          <UI.chat />
          Live chat
        </a>
        <a
          className="btn book"
          href={bookHref ?? undefined}
          aria-disabled={bookHref ? undefined : "true"}
          target="_blank"
          rel="noopener"
          onClick={() => {
            if (quote) logQuote(quote, { booked: true, mode: "quick", addons: selectedAddons.map((a) => a.label) });
          }}
        >
          {bookLabel}
        </a>
      </div>
    </div>
  );
}

/* ---------------- detailed quote ---------------- */

interface DetailProps {
  site: PublishedVersion;
  cities: string[];
  destId: string;
  setDestId: (id: string) => void;
  initialKg: string;
  rememberKg: (kg: string) => void;
  selectedAddons: Addon[];
  addonsTotal: number;
  addonsBox: ReactNode;
  showToast: (m: string) => void;
}

function toRows(pieces: PieceRow[], type: ShipmentType, units: Units): PieceInput[] {
  const imp = units === "imperial";
  const n = (s: string): number | null => {
    const v = toNumLoose(s);
    return v == null || Number.isNaN(v) ? null : v;
  };
  const m = (v: number | null | undefined, k: number) => (v != null && v > 0 ? v * k : (v ?? null));
  return pieces.map((pc) => {
    let r: PieceInput = { kg: n(pc.kg), qty: n(pc.qty) ?? 1, L: n(pc.L), W: n(pc.W), H: n(pc.H) };
    if (type === "doc") r = { ...r, L: null, W: null, H: null };
    if (imp) r = { kg: m(r.kg, LB), qty: r.qty, L: m(r.L, IN), W: m(r.W, IN), H: m(r.H, IN) };
    return r;
  });
}

interface Chosen {
  serviceId: string;
  /** Snapshot of the inputs the choice was made for; any edit withdraws it. */
  sig: string;
  quoteId: string;
}

function DetailedQuote({ site, cities, destId, setDestId, initialKg, rememberKg, selectedAddons, addonsTotal, addonsBox, showToast }: DetailProps) {
  const sets = site.settings;
  const cur = sets.currency;
  const mounted = useMounted();
  const [from, setFrom] = useState(cities[0] ?? "");
  const [type, setType] = useState<ShipmentType>("pkg");
  const [units, setUnits] = useState<Units>("metric");
  const [pieces, setPieces] = useState<PieceRow[]>(() => [newRow(1, initialKg)]);
  const [nextKey, setNextKey] = useState(2);
  const [shipDateInput, setShipDateInput] = useState("");
  const [chosen, setChosen] = useState<Chosen | null>(null);
  const [contents, setContents] = useState("");

  const today = mounted ? inputDate(new Date()) : "";
  const shipDate = shipDateInput || today;
  const shipDateObj = localDateFromInput(shipDate);

  const rows = useMemo(() => toRows(pieces, type, units), [pieces, type, units]);
  const res = priceAll(site, { destId, type, rows });
  const inputSig = JSON.stringify({ destId, type, units, pieces, shipDate, from });
  const active = chosen && chosen.sig === inputSig ? chosen : null;

  const updatePiece = (key: number, field: keyof Omit<PieceRow, "key">, value: string) => {
    setPieces((ps) => ps.map((p) => (p.key === key ? { ...p, [field]: value } : p)));
    if (field === "kg" && pieces[0]?.key === key) rememberKg(value);
  };

  const switchUnits = (u: Units) => {
    if (u === units) return;
    const toImp = u === "imperial";
    const conv = (s: string, k: number) => {
      const v = toNumLoose(s);
      return v != null && !Number.isNaN(v) && v > 0 ? String(Math.round(v * k * 100) / 100) : s;
    };
    setPieces((ps) =>
      ps.map((p) => ({
        ...p,
        kg: conv(p.kg, toImp ? 1 / LB : LB),
        L: conv(p.L, toImp ? 1 / IN : IN),
        W: conv(p.W, toImp ? 1 / IN : IN),
        H: conv(p.H, toImp ? 1 / IN : IN),
      })),
    );
    setUnits(u);
  };

  const imp = units === "imperial";
  const word = type === "doc" ? "Envelope" : "Package";

  /* ----- status line ----- */
  let statusClass = "status";
  let status: ReactNode;
  if (!res.ok) {
    if (res.reason === "overmax") {
      statusClass = "status warn";
      status = (
        <>
          Shipments over {sets.maxKg} kg get a cargo rate. {weightSentence(res.weights, sets)}{" "}
          <a href={waLink(site.company.whatsapp, cargoText(site.company.name, from, res.dest.name, res.weights, units))} target="_blank" rel="noopener">
            Message us on WhatsApp
          </a>{" "}
          and we will price it within the hour.
        </>
      );
    } else if (res.reason === "destination") status = "Choose a destination to see prices and delivery dates.";
    else status = type === "doc" ? "Enter the weight of your documents." : "Enter the weight of your package.";
  } else {
    const bits = [weightSentence(res.weights, sets)];
    if (site.services.some((sv) => res.prices[sv.id]?.docRate)) bits.push(`Document rate applied (up to ${sets.docMaxKg} kg).`);
    if (sets.showEta && mounted) {
      const first = site.services.map((sv) => res.prices[sv.id]).find((p) => p?.days);
      const est = first ? estimateDelivery(first.days, sets, shipDateObj, new Date()) : null;
      if (est?.moved) {
        const now = new Date();
        const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
        const afterCutoff = sets.cutoffHour != null && now.getHours() >= sets.cutoffHour && shipDateObj != null && shipDateObj.getTime() <= startOfToday;
        bits.push(`${afterCutoff ? `Booked after the ${fmtHour(sets.cutoffHour)} cutoff, so pickup is ` : "Pickup is "}${fmtDay(est.pickup)}.`);
      }
    }
    if (res.weights.volumetricWins) statusClass = "status warn";
    status = `${bits.join(" ")} Choose a service to get your quote.`;
  }

  /* ----- quote card ----- */
  let quote: Quote | null = null;
  let est: DeliveryEstimate | null = null;
  let price: ServicePrice | null = null;
  if (res.ok && active && res.prices[active.serviceId]) {
    price = res.prices[active.serviceId]!;
    const sv = site.services.find((s) => s.id === active.serviceId)!;
    est = sets.showEta && price.days ? estimateDelivery(price.days, sets, shipDateObj, new Date()) : null;
    quote = {
      id: active.quoteId,
      from,
      dest: res.dest.name,
      destId: res.dest.id,
      type,
      service: sv.name,
      serviceId: active.serviceId,
      days: price.days,
      eta: est ? fmtRange(est) : "",
      pickup: est ? fmtDay(est.pickup) : shipDateObj ? fmtDay(shipDateObj) : "",
      pieces: res.weights.pieces,
      piecesText: piecesText(res.weights, units),
      billableG: res.weights.billableG,
      total: price.total,
      docRate: price.docRate,
      version: site.version,
    };
  }
  const text = quote ? quoteText(quote, { companyName: site.company.name, currency: cur, addons: selectedAddons, contents }) : "";

  const how: string[] = [];
  if (quote && res.ok && price) {
    for (const l of res.weights.lines) {
      how.push(
        `${l.qty} × ${gToKg(l.actualG)} kg actual` +
          (l.volG
            ? `; volumetric ${[l.L, l.W, l.H].map((x) => Math.round(x * 10) / 10).join("×")} cm ÷ ${sets.volumetricDivisor} = ${gToKg(l.volG)} kg${l.volumetricWins ? " (volumetric applies)" : ""}`
            : "") +
          (l.qty > 1 ? ", each" : ""),
      );
    }
    how.push(`Chargeable weight ${gToKg(res.weights.chargeG)} kg, billed as ${gToKg(res.weights.billableG)} kg (${sets.stepKg} kg steps, minimum ${sets.firstKg} kg)`);
    if (price.docRate) how.push(`Document rate for up to ${sets.docMaxKg} kg: ${fmtMoney(price.base, cur)}`);
    else
      how.push(
        `First ${sets.firstKg} kg ${fmtMoney(price.first ?? 0, cur)}${price.steps ? ` + ${price.steps} × ${sets.stepKg} kg at ${fmtMoney(price.addl ?? 0, cur)}` : ""} = ${fmtMoney(price.base, cur)}`,
      );
    if (price.tax) how.push(`Tax ${sets.taxPct}% = ${fmtMoney(price.tax, cur)}`);
    if (Number(sets.roundTo) > 1) how.push(`Rounded to the nearest ${fmtNum(sets.roundTo)}`);
    if (est)
      how.push(
        `Delivery estimate counts ${price.days} working days (${String(sets.workingDays || "Mon–Fri").replace(/\s+/g, " ")}${sets.holidays.trim() ? ", holidays skipped" : ""}) from pickup on ${fmtDay(est.pickup)}`,
      );
  }

  const copyQuote = () => {
    if (navigator.clipboard?.writeText)
      navigator.clipboard.writeText(text).then(
        () => showToast("Quote copied"),
        () => showToast("Copy failed — select the text instead"),
      );
    else showToast("Copy is not supported here");
  };
  const canShare = mounted && typeof navigator.share === "function";

  return (
    <>
      <div className="panel">
        <h2 className="step">
          <span className="n">1</span>Where is it going?
        </h2>
        <div className="row">
          {cities.length > 1 ? (
            <label className="field">
              <span>From</span>
              <select value={from} onChange={(e) => setFrom(e.target.value)}>
                {cities.map((c) => (
                  <option key={c} value={c}>
                    {c}, {site.company.origin}
                  </option>
                ))}
              </select>
            </label>
          ) : (
            <div className="field">
              <span>From</span>
              <div className="static">
                {cities[0] ? `${cities[0]}, ` : ""}
                {site.company.origin}
              </div>
            </div>
          )}
          <label className="field">
            <span>To</span>
            <select value={destId} onChange={(e) => setDestId(e.target.value)}>
              <DestOptions site={site} />
            </select>
          </label>
        </div>
      </div>

      <div className="panel">
        <h2 className="step">
          <span className="n">2</span>What are you sending?
        </h2>
        <div className="seg" role="radiogroup" aria-label="Shipment type">
          <button type="button" className="segbtn" role="radio" aria-checked={type === "pkg"} onClick={() => setType("pkg")}>
            Packages
          </button>
          <button type="button" className="segbtn" role="radio" aria-checked={type === "doc"} onClick={() => setType("doc")}>
            Documents
          </button>
        </div>
        <p className="hint">
          {type === "doc"
            ? `Paperwork only: contracts, certificates, passports, letters. Up to ${sets.docMaxKg} kg gets the document rate.`
            : "Boxes, gifts, clothes, samples — anything that is not paperwork."}
        </p>
        <div className="units">
          <span className="hint">Units</span>
          <button type="button" className="ubtn" aria-pressed={units === "metric"} onClick={() => switchUnits("metric")}>
            kg · cm
          </button>
          <button type="button" className="ubtn" aria-pressed={units === "imperial"} onClick={() => switchUnits("imperial")}>
            lb · in
          </button>
        </div>
        <div className={`pieces${type === "doc" ? " docs" : ""}`}>
          {pieces.map((pc, i) => (
            <div className="piece" key={pc.key}>
              <div className="ptitle" hidden={pieces.length === 1}>
                <span className="pnum">
                  {word} {i + 1}
                </span>
                <button className="btn p-remove" type="button" onClick={() => setPieces((ps) => (ps.length > 1 ? ps.filter((x) => x.key !== pc.key) : ps))}>
                  Remove
                </button>
              </div>
              <div className="row three">
                <label className="field">
                  <span>
                    {type === "doc" ? "Weight" : "Weight per piece"} ({imp ? "lb" : "kg"})
                  </span>
                  <input type="number" inputMode="decimal" min="0.01" step="any" placeholder="e.g. 2.5" value={pc.kg} onChange={(e) => updatePiece(pc.key, "kg", e.target.value)} />
                </label>
                <label className="field">
                  <span>Quantity</span>
                  <input type="number" inputMode="numeric" min="1" step="1" value={pc.qty} onChange={(e) => updatePiece(pc.key, "qty", e.target.value)} />
                </label>
                <div className="field dims-field">
                  <span>Size per piece in {imp ? "inches" : "cm"} (optional)</span>
                  <div className="dims">
                    <input type="number" inputMode="decimal" min="0.1" step="any" placeholder="Length" aria-label="Length" value={pc.L} onChange={(e) => updatePiece(pc.key, "L", e.target.value)} />
                    <span className="x">×</span>
                    <input type="number" inputMode="decimal" min="0.1" step="any" placeholder="Width" aria-label="Width" value={pc.W} onChange={(e) => updatePiece(pc.key, "W", e.target.value)} />
                    <span className="x">×</span>
                    <input type="number" inputMode="decimal" min="0.1" step="any" placeholder="Height" aria-label="Height" value={pc.H} onChange={(e) => updatePiece(pc.key, "H", e.target.value)} />
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
        <div className="addrow">
          <button
            className="btn small"
            type="button"
            onClick={() => {
              setPieces((ps) => [...ps, newRow(nextKey)]);
              setNextKey((k) => k + 1);
            }}
          >
            Add another {type === "doc" ? "envelope" : "package"}
          </button>
          <span className="hint">
            Large, light boxes are charged by volume (L×W×H{imp ? " in cm" : ""} ÷ {sets.volumetricDivisor}).
          </span>
        </div>
        <label className="field" style={{ maxWidth: 260, marginTop: 6 }}>
          <span>Ship date</span>
          <input type="date" min={today || undefined} value={shipDate} onChange={(e) => setShipDateInput(e.target.value)} />
        </label>
      </div>

      <div className="results">
        <div className="stripe" />
        <h2 className="step">
          <span className="n">3</span>Choose a service
        </h2>
        <div className="svcs">
          {site.services.map((sv) => {
            const sp = res.ok ? res.prices[sv.id] : null;
            let dimText = "";
            if (!res.ok) dimText = res.reason === "destination" ? "Choose destination" : res.reason === "overmax" ? "Cargo rate" : "Enter weight";
            else if (!sp) dimText = "Not available";
            const e = sp && sp.days && sets.showEta && mounted ? estimateDelivery(sp.days, sets, shipDateObj, new Date()) : null;
            const isChosen = active?.serviceId === sv.id;
            return (
              <button
                key={sv.id}
                type="button"
                className="svc"
                disabled={!sp}
                aria-pressed={isChosen}
                onClick={() => setChosen({ serviceId: sv.id, sig: inputSig, quoteId: quoteId() })}
              >
                <span className="svc-main">
                  <span className="svc-name">{sv.name}</span>
                  <span className="svc-eta">
                    {sp ? (
                      e ? (
                        <>
                          Delivered <strong>{fmtRange(e)}</strong>
                        </>
                      ) : sp.days ? (
                        `${sp.days} working days`
                      ) : (
                        sv.note
                      )
                    ) : (
                      sv.note
                    )}
                  </span>
                </span>
                {sp ? (
                  <span className="svc-price">
                    <span className="cur">{cur}</span>
                    {fmtNum(sp.total)}
                  </span>
                ) : (
                  <span className="svc-price dim">{dimText}</span>
                )}
                {sp ? <span className="svc-cta">{isChosen ? "Chosen" : "Choose"}</span> : null}
              </button>
            );
          })}
        </div>
        {addonsBox ? <div className="addons-detail">{addonsBox}</div> : null}
        <div className={statusClass} aria-live="polite">
          {status}
        </div>
      </div>

      {quote && price ? (
        <div className="quote" id="quote">
          <h2>
            {quote.service} to {quote.dest}
            {quote.docRate ? <span className="tag">Document rate</span> : null}
          </h2>
          <dl>
            {selectedAddons.length ? (
              <>
                <dt>Shipping</dt>
                <dd>{fmtMoney(quote.total, cur)}</dd>
                {selectedAddons.map((a) => (
                  <Fragment key={a.id}>
                    <dt>{a.label}</dt>
                    <dd>{fmtMoney(a.amount, cur)}</dd>
                  </Fragment>
                ))}
                <dt>Total</dt>
                <dd className="display" style={{ fontSize: "1.6rem" }}>
                  {fmtMoney(quote.total + addonsTotal, cur)}
                </dd>
              </>
            ) : (
              <>
                <dt>Price</dt>
                <dd className="display" style={{ fontSize: "1.6rem" }}>
                  {fmtMoney(quote.total, cur)}
                </dd>
              </>
            )}
            {quote.eta ? (
              <>
                <dt>Delivered</dt>
                <dd>
                  {quote.eta} <span className="hint">estimated</span>
                </dd>
              </>
            ) : quote.days ? (
              <>
                <dt>Transit</dt>
                <dd>{quote.days} working days</dd>
              </>
            ) : null}
            {quote.pickup ? (
              <>
                <dt>Pickup</dt>
                <dd>{quote.pickup}</dd>
              </>
            ) : null}
            <dt>Route</dt>
            <dd>
              {quote.from ? `${quote.from}, ` : ""}
              {site.company.origin} → {quote.dest}
            </dd>
            <dt>{quote.type === "doc" ? "Documents" : "Packages"}</dt>
            <dd>{quote.piecesText}</dd>
            <dt>Charged on</dt>
            <dd>{gToKg(quote.billableG)} kg</dd>
            <dt>Quote</dt>
            <dd>
              <span className="qid">{quote.id}</span> <span className="hint">valid today</span>
            </dd>
          </dl>
          <details className="how">
            <summary>How this price is calculated</summary>
            <ul>
              {how.map((h, i) => (
                <li key={i}>{h}</li>
              ))}
            </ul>
          </details>
          <label className="field">
            <span>
              What is inside? <span className="hint">(optional, helps us book faster)</span>
            </span>
            <input type="text" maxLength={120} placeholder="e.g. clothes and a gift" value={contents} onChange={(e) => setContents(e.target.value)} />
          </label>
          <div className="actions">
            <a
              className="btn wa"
              target="_blank"
              rel="noopener"
              href={waLink(site.company.whatsapp, text)}
              onClick={() => logQuote(quote, { booked: true, mode: "detail", addons: selectedAddons.map((a) => a.label), contents })}
            >
              Book on WhatsApp
            </a>
            {canShare ? (
              <button className="btn" type="button" onClick={() => navigator.share({ title: `${site.company.name} quote ${quote.id}`, text }).catch(() => {})}>
                Share quote
              </button>
            ) : null}
            <button className="btn" type="button" onClick={copyQuote}>
              Copy quote
            </button>
          </div>
        </div>
      ) : null}
    </>
  );
}
