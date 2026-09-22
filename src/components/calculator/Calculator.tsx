"use client";

/**
 * The customer calculator: the quick price (three taps: country tile →
 * weight button → the price, then Book on WhatsApp) and the detailed price
 * (pieces, sizes, documents, ship date). All pricing happens here in the
 * browser with the published document handed in as props; the only network
 * call is a fire-and-forget log when someone taps Book.
 *
 * v3 (brief §4): the page never scrolls itself, the price is printed at
 * once, nothing on screen moves when it lands, and no control ever looks
 * dead — the Book button exists only once there is somewhere to go.
 */
import { Fragment, useMemo, useState, type ReactNode } from "react";
import { UI } from "@/components/Icons";
import { setSession, useMounted, useSession } from "@/lib/client/session";
import { addonsList, gToKg, gridWeights, isGrid, lines, priceAll, toNumLoose } from "@/lib/pricing/engine";
import { estimateDelivery, type DeliveryEstimate } from "@/lib/pricing/dates";
import { fmtDay, fmtHour, fmtMoney, fmtNum, fmtRange, inputDate, localDateFromInput, quoteId, quoteIdFor } from "@/lib/pricing/format";
import { IN, LB, bookedTotal, cargoText, midSentence, piecesText, quoteText, waLink, weightSentence, type Quote, type Units } from "@/lib/pricing/quote";
import type { Addon, PieceInput, PriceResult, ServicePrice, ShipmentType } from "@/lib/pricing/types";
import type { PublishedVersion } from "@/lib/site/types";
import { fromPrice } from "@/lib/site/copy";
import { flagCode } from "@/lib/site/countries";
import { originCities } from "@/lib/site/text";
import { Flag } from "@/components/site/Flag";
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

/** The weight buttons on the quick view, in kilograms; only those the rate card can price are shown. */
const KG_BUTTONS = [1, 2, 3, 5, 10, 15, 20, 25];

/**
 * How many cells the country grid has. Up to this many destinations are all
 * tiles; with more, the last cell becomes the "More countries" picker, so the
 * grid keeps the same height (two rows beside the hero) whatever the count.
 */
const TILE_MAX = 8;

function weightOptions(sets: PublishedVersion["settings"]): number[] {
  const maxKg = sets.maxKg;
  // Grid pricing: exactly the whole kilograms that have a price box.
  if (isGrid(sets)) return gridWeights(sets);
  const out: number[] = [];
  for (let k = 0.5; k <= 10; k += 0.5) out.push(k);
  for (let k = 11; k <= 30; k += 1) out.push(k);
  const top = maxKg > 0 ? Math.min(maxKg, 200) : 100;
  for (let k = 35; k <= top; k += 5) out.push(k);
  return out.filter((x) => !(maxKg > 0) || x <= maxKg);
}

/** `total` is the number the customer saw — shipping plus the add-ons that were on; the server recomputes both from the live document. */
function logQuote(q: Quote, addons: Addon[], extra: Record<string, unknown>): void {
  try {
    void fetch("/api/quotes", {
      method: "POST",
      keepalive: true,
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ ...q, total: bookedTotal(q.total, addons), addons: addons.map((a) => a.label), ...extra }),
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

  // The fine print — what is included, the owner's notes, the disclaimer — is plain text at the end of the detailed
  // view. The quick view carries none of it: page.tsx prints the one-line promise under the calculator.
  const includes = site.company.includes.trim();
  const disclaimer = sets.disclaimer.trim();
  const notes = lines(site.company.notes);

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
      <div hidden={mode !== "quick"}>
        {/* The quick view carries no add-on checkbox: the add-ons that are on (all of them, by default) are inside its
            one number and named under it; the opt-out lives in the detailed view, which itemises. */}
        <QuickRate site={site} destId={destId} setDestId={setDestId} selectedAddons={selectedAddons} addonsTotal={addonsTotal} onExact={() => switchMode("detail")} />
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
          onBack={() => switchMode("quick")}
        />
        {includes ? <p className="includes">{includes}</p> : null}
        {notes.length ? (
          <ul className="notes">
            {notes.map((x, i) => (
              <li key={i}>{x}</li>
            ))}
          </ul>
        ) : null}
        {disclaimer ? <p className="disclaimer">{disclaimer}</p> : null}
      </div>
      <Toast message={toast} />
    </>
  );
}

/* ---------------- destination select ---------------- */

function DestOptions({ site, placeholder: placeholderText = "Choose a country" }: { site: PublishedVersion; placeholder?: string }) {
  const act = site.destinations.filter((x) => x.active);
  const alpha = act.slice().sort((a, b) => a.name.localeCompare(b.name));
  const opt = (x: { id: string; name: string }) => (
    <option key={x.id} value={x.id}>
      {x.name}
    </option>
  );
  const placeholder = (
    <option value="" disabled>
      {placeholderText}
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
  onExact: () => void;
}

/**
 * The line under a service's name on the quick view: how many days it takes,
 * the one figure the visitor compares between the two rows. The arrival
 * dates belong to the chosen service and sit under the rows, so they
 * appear once.
 */
function etaQuick(p: ServicePrice): string {
  return p.days ? `${p.days} days` : "";
}

type ReadoutState = "country" | "weight" | "live" | "cargo" | "none";

function QuickRate({ site, destId, setDestId, selectedAddons, addonsTotal, onExact }: QuickProps) {
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

  const p = res?.ok && chosen ? res.prices[chosen]! : null;
  const sum = p ? p.total + addonsTotal : 0;

  // The from-price on every tile ("from PKR 5,220 · 4–6 days"): the shared `fromPrice`, the same function the
  // headline and the rates board print, once per rate document.
  const froms = useMemo(() => new Map(site.destinations.map((d) => [d.id, fromPrice(site, d.id)])), [site]);

  // The one sentence, in plain words: what to do next, or what the price is for. Live, a second short line says
  // when the parcel arrives (the only place the dates are printed). The live note is "Express · 5 kg · Canada";
  // its country is a separate span, so on phones — where the step head already shows it and the bar's column is
  // 125px beside the green button — the CSS can drop it instead of ellipsizing the whole line.
  let note: string;
  let noteTo = "";
  let when = "";
  let state: ReadoutState;
  if (!destId) {
    state = "country";
    note = "Tap a country";
  } else if (!kgv || kgv === "exact") {
    state = "weight";
    note = "Now tap a weight";
  } else if (kgv === "more" || !res?.ok) {
    state = "cargo";
    note = sets.maxKg > 0 ? `Over ${sets.maxKg} kg? We price it on WhatsApp` : "We price this on WhatsApp";
  } else if (!available.length) {
    state = "none";
    // A country the tile and the rates board price (its grid has boxes, just not one at or above this weight)
    // is served: the sentence names the weight, so it never contradicts the "from PKR …" printed beside it.
    // "No service" is for a country priced at no weight at all. The green button beside the line says "Ask on
    // WhatsApp", so the line does not repeat it: with a long country name it would take a fourth line on a
    // phone and grow the bar.
    note = froms.get(destId) ? `No price for ${kgv} kg to ${dest?.name} yet` : `No service to ${dest?.name} yet. Ask us on WhatsApp`;
  } else {
    state = "live";
    const sv = site.services.find((s) => s.id === chosen)!;
    const est = sets.showEta && p?.days ? estimateDelivery(p.days, sets, null, new Date()) : null;
    note = `${sv.name} · ${kgv} kg`;
    noteTo = dest?.name ?? "";
    when = est ? `Arrives ${fmtRange(est)}` : "";
  }

  // The green button always has somewhere to go: the quote, the cargo message, the no-price-at-this-weight message,
  // or the plain chat.
  let bookHref = `https://wa.me/${site.company.whatsapp}`;
  let bookLabel = "Ask on WhatsApp";
  let quote: Quote | null = null;
  if (kgv === "more" && dest) {
    // "Over N kg" is all the visitor has said, so the message says exactly that: the country and the weight, in
    // the same shape as the detailed view's cargo message — but no piece list (there are no pieces to list) and
    // no "about N kg" (it is more than N).
    bookHref = waLink(site.company.whatsapp, `Hi ${site.company.name}, I need a cargo rate.\nTo: ${dest.name}\nWeight: over ${sets.maxKg} kg`);
    bookLabel = "Ask for a cargo rate";
  } else if (state === "none" && dest) {
    // No price at this weight: the visitor has named the country and the weight, so the chat opens with both
    // (the same shape as the cargo message) instead of blank.
    bookHref = waLink(site.company.whatsapp, `Hi ${site.company.name}, I need a rate.\nTo: ${dest.name}\nWeight: ${kgv} kg`);
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
    bookLabel = "Book on WhatsApp";
  }

  const onKg = (v: string) => {
    if (v === "exact") {
      setKgv("");
      onExact();
      return;
    }
    setKgv(v);
  };

  const act = site.destinations.filter((x) => x.active);
  // Every destination gets a tile while they fit the grid; beyond that the last cell is the "More countries"
  // picker, which lists them all (the admin's order first, then A–Z) and shows the one it chose in place of its label.
  const more = act.length > TILE_MAX;
  const tiles = more ? act.slice(0, TILE_MAX - 1) : act;
  const pickedId = destId && !tiles.some((d) => d.id === destId) ? destId : "";
  const priced = weightOptions(sets);
  const kgs = KG_BUTTONS.filter((k) => priced.includes(k));

  const waiting = state === "country" || state === "weight";

  return (
    <div className="panel quick">
      <h2 className="sr">Get your price</h2>

      <div className="step-head">
        <span className="step-name">1 Country</span>
        {dest ? (
          <span className="step-done">
            <Flag code={flagCode(dest.name, dest.id)} name={dest.name} size={24} />
            {dest.name}
          </span>
        ) : null}
      </div>
      <div className="ctiles" role="radiogroup" aria-label="Country">
        {tiles.map((d) => {
          const from = froms.get(d.id) ?? null;
          return (
            <button key={d.id} type="button" role="radio" className="ctile" aria-checked={destId === d.id} onClick={() => setDestId(d.id)}>
              <Flag code={flagCode(d.name, d.id)} name={d.name} size={32} />
              <span className="ctile-name">{d.name}</span>
              {from ? (
                <span className="ctile-from">
                  from {fmtMoney(from.total, cur)}
                  {from.days ? <span className="ctile-days"> · {from.days} days</span> : null}
                </span>
              ) : null}
            </button>
          );
        })}
        {more ? (
          <select className="ctile-more" aria-label="More countries" data-chosen={pickedId ? "true" : undefined} value={pickedId} onChange={(e) => setDestId(e.target.value)}>
            <DestOptions site={site} placeholder="More countries" />
          </select>
        ) : null}
      </div>

      <div className="step-head">
        <span className="step-name">2 Weight</span>
        {kgv && kgv !== "more" ? <span className="step-done">{kgv} kg</span> : null}
      </div>
      <div className="kgs" role="group" aria-label="Weight">
        {kgs.map((k) => (
          <button key={k} type="button" className="kg" aria-pressed={kgv === String(k)} onClick={() => onKg(String(k))}>
            <strong>{k}</strong>
            <span>kg</span>
          </button>
        ))}
        {sets.maxKg > 0 ? (
          <button type="button" className="kg more" aria-pressed={kgv === "more"} onClick={() => onKg("more")}>
            Over {sets.maxKg} kg
          </button>
        ) : null}
        {/* The way out to the detailed view (documents, box sizes, many boxes). */}
        <button type="button" className="kg other" onClick={() => onKg("exact")}>
          Documents · box size
        </button>
      </div>

      {/* The result block is always in the page and always the same height: the CSS reserves the room the rows, the
          lines and the bar take once live (per tier, and per how many services the document has, hence data-n),
          so the third tap paints the price into a slot that was already there and nothing under the calculator
          moves. Waiting, the block holds the one line that says what to do next, at its start, 12px under the
          weights (the reserved room is under the line, where it reads as page space, not as an empty frame between
          the controls and the prompt); cargo / no service, only the bar, at the block's end where it sits live. */}
      <div className="result" data-state={state} data-n={String(Math.min(site.services.length, 4))}>
        {state === "live" && res?.ok ? (
          <>
            {/* Only the services that price this parcel, in the document's order; the row's figure is the visitor's
                figure: add-ons that are on are inside it, exactly as they are inside the bar, so the two numbers he
                sees for one service are the same number. */}
            <fieldset className="opts">
              <legend className="sr">Service</legend>
              <div>
                {site.services.map((svx) => {
                  const sp = res.prices[svx.id];
                  if (!sp) return null;
                  return (
                    <label key={svx.id} className="opt">
                      <input type="radio" name="q-svc" value={svx.id} checked={chosen === svx.id} onChange={() => setSvc(svx.id)} />
                      <span className="opt-name">{svx.name}</span>
                      <span className="opt-eta">{etaQuick(sp)}</span>
                      <span className="opt-price">{fmtMoney(sp.total + addonsTotal, cur)}</span>
                    </label>
                  );
                })}
              </div>
            </fieldset>
            <p className="lines">
              {when ? <span className="twhen">{when}</span> : null}
              {selectedAddons.map((a) => (
                <span key={a.id} className="tline">
                  Includes {fmtMoney(a.amount, cur)} {midSentence(a.label)}
                </span>
              ))}
            </p>
          </>
        ) : null}

        {/* The price bar: the figure and the one green button. On phones it sticks to the bottom of the screen while
            the calculator is in view; it is the block's last child, so it comes to rest at the panel's end and never
            covers what follows. Rendered only once there is something to say and somewhere to go. */}
        {!waiting ? (
          <div className="pricebar" data-state={state}>
            <div className="pricebar-sum" aria-live="polite">
              {state === "live" ? <span className="tval">{fmtMoney(sum, cur)}</span> : null}
              <span className="tnote">
                {note}
                {noteTo ? <span className="tnote-to"> · {noteTo}</span> : null}
              </span>
            </div>
            <a
              className="btn book giant"
              href={bookHref}
              target="_blank"
              rel="noopener"
              onClick={() => {
                if (quote) logQuote(quote, selectedAddons, { booked: true, mode: "quick" });
              }}
            >
              <UI.wa />
              {bookLabel}
            </a>
          </div>
        ) : null}

        {/* The line that waits: "Tap a country" / "Now tap a weight", at the top of the slot until there is a bar
            (the CSS pins it there; the bar, when it comes, lands at the slot's end). */}
        {waiting ? (
          <p className="wait" aria-live="polite">
            {note}
          </p>
        ) : null}
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
  onBack: () => void;
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

function DetailedQuote({ site, cities, destId, setDestId, initialKg, rememberKg, selectedAddons, addonsTotal, addonsBox, showToast, onBack }: DetailProps) {
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
  const destSel = site.destinations.find((x) => x.id === destId && x.active);

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
    // No weight yet: the field invites one by itself, so the line carries the one number worth knowing first.
    else status = sets.maxKg > 0 ? `Up to ${sets.maxKg} kg per piece. Heavier is priced on WhatsApp.` : "";
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
    // The instruction goes once a service is chosen for these inputs and its quote card is showing under the line.
    const picked = !!active && !!res.prices[active.serviceId];
    status = picked ? bits.join(" ") : `${bits.join(" ")} Choose a service to get your quote.`;
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
    if (isGrid(sets)) how.push(`Chargeable weight ${gToKg(res.weights.chargeG)} kg, billed as ${gToKg(res.weights.billableG)} kg (rounded up to the next whole kilogram)`);
    else how.push(`Chargeable weight ${gToKg(res.weights.chargeG)} kg, billed as ${gToKg(res.weights.billableG)} kg (${sets.stepKg} kg steps, minimum ${sets.firstKg} kg)`);
    if (price.docRate) how.push(`Document rate for up to ${sets.docMaxKg} kg: ${fmtMoney(price.base, cur)}`);
    else if (isGrid(sets)) how.push(`${quote.service} price for ${price.gridKg ?? gToKg(res.weights.billableG)} kg: ${fmtMoney(price.base, cur)}`);
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
        () => showToast("Copy failed. Select the text instead"),
      );
    else showToast("Copy is not supported here");
  };
  const canShare = mounted && typeof navigator.share === "function";

  return (
    <>
      <button type="button" className="btn outline back" onClick={onBack}>
        <span aria-hidden="true">←</span> Back to quick price
      </button>

      <div className="panel">
        <h2 className="step-head step-title">1 Where to?</h2>
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
            <span className="lab">
              To
              {destSel ? (
                <span className="step-done">
                  <Flag code={flagCode(destSel.name, destSel.id)} name={destSel.name} size={24} />
                </span>
              ) : null}
            </span>
            <select value={destId} onChange={(e) => setDestId(e.target.value)}>
              <DestOptions site={site} />
            </select>
          </label>
        </div>
      </div>

      <div className="panel">
        <h2 className="step-head step-title">2 What are you sending?</h2>
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
            : "Boxes, gifts, clothes, samples."}
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
                    <input type="number" inputMode="decimal" min="0.1" step="any" placeholder="L" aria-label="Length" value={pc.L} onChange={(e) => updatePiece(pc.key, "L", e.target.value)} />
                    <span className="x">×</span>
                    <input type="number" inputMode="decimal" min="0.1" step="any" placeholder="W" aria-label="Width" value={pc.W} onChange={(e) => updatePiece(pc.key, "W", e.target.value)} />
                    <span className="x">×</span>
                    <input type="number" inputMode="decimal" min="0.1" step="any" placeholder="H" aria-label="Height" value={pc.H} onChange={(e) => updatePiece(pc.key, "H", e.target.value)} />
                  </div>
                </div>
              </div>
            </div>
          ))}
        </div>
        <div className="addrow">
          <button
            className="btn addpiece"
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
        <label className="field date">
          <span>Ship date</span>
          <input type="date" min={today || undefined} value={shipDate} onChange={(e) => setShipDateInput(e.target.value)} />
        </label>
      </div>

      <div className="results">
        <h2 className="step-head step-title">3 Choose a service</h2>
        <div className="svcs">
          {site.services.map((sv) => {
            const sp = res.ok ? res.prices[sv.id] : null;
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
                  <span className="svc-name">
                    {sv.name}
                    {sp ? <span className="svc-cta">{isChosen ? "Chosen" : "Choose"}</span> : null}
                  </span>
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
                <span className="svc-price">{sp ? fmtMoney(sp.total, cur) : null}</span>
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
                <dd className="display">{fmtMoney(quote.total + addonsTotal, cur)}</dd>
              </>
            ) : (
              <>
                <dt>Price</dt>
                <dd className="display">{fmtMoney(quote.total, cur)}</dd>
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
              className="btn book giant"
              target="_blank"
              rel="noopener"
              href={waLink(site.company.whatsapp, text)}
              onClick={() => logQuote(quote, selectedAddons, { booked: true, mode: "detail", contents })}
            >
              <UI.wa />
              Book on WhatsApp
            </a>
            <div className="actions-2">
              {canShare ? (
                <button className="btn outline" type="button" onClick={() => navigator.share({ title: `${site.company.name} quote ${quote.id}`, text }).catch(() => {})}>
                  Share
                </button>
              ) : null}
              <button className="btn outline" type="button" onClick={copyQuote}>
                Copy
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </>
  );
}
