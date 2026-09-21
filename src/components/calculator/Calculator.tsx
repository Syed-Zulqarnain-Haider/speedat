"use client";

/**
 * The customer calculator: the quick price (three taps: country tile →
 * weight button → the price, then Book on WhatsApp) and the detailed price
 * (pieces, sizes, documents, ship date). All pricing happens here in the
 * browser with the published document handed in as props; the only network
 * call is a fire-and-forget log when someone taps Book.
 */
import { Fragment, useEffect, useMemo, useRef, useState, type ReactNode, type Ref } from "react";
import { CardIcons, UI } from "@/components/Icons";
import { useReducedMotion } from "@/lib/client/motion";
import { setSession, useMounted, useSession } from "@/lib/client/session";
import { addonsList, gToKg, gridWeights, isGrid, lines, priceAll, toNumLoose } from "@/lib/pricing/engine";
import { estimateDelivery, parseDaysRange, type DeliveryEstimate } from "@/lib/pricing/dates";
import { fmtDay, fmtHour, fmtMoney, fmtNum, fmtRange, inputDate, localDateFromInput, quoteId, quoteIdFor } from "@/lib/pricing/format";
import { IN, LB, cargoText, piecesText, quoteText, waLink, weightSentence, type Quote, type Units } from "@/lib/pricing/quote";
import type { Addon, PieceInput, PriceResult, ServicePrice, ShipmentType } from "@/lib/pricing/types";
import type { PublishedVersion } from "@/lib/site/types";
import { flagCode } from "@/lib/site/countries";
import { originCities } from "@/lib/site/text";
import { Flag } from "@/components/site/Flag";
import { PriceReadout } from "./PriceReadout";
import { Toast, useToast } from "./Toast";
import ClickSpark from "@/components/bits/ClickSpark";
import { Pull } from "@/components/site/fx/Pull";

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

/**
 * Once a country and a weight are in, the price card and the buttons under
 * it must be on screen: on a phone they sit some 700px below the weight row,
 * and a visitor who reads nothing has no other way to learn the price has
 * arrived. Scrolls the page just far enough for the buttons to show, never
 * so far that the card's top slips under the (sticky) header, and not at all
 * when everything is already in view — the 1366×768 fold stays put.
 */
function revealPrice(total: HTMLElement | null, ctas: HTMLElement | null, smooth: boolean): void {
  if (!total || !ctas) return;
  const t = total.getBoundingClientRect();
  const c = ctas.getBoundingClientRect();
  // Zero height: the quick view is hidden behind the detailed one.
  if (!t.height || !c.height) return;
  const head = document.querySelector<HTMLElement>(".site-head");
  const headBottom = head && getComputedStyle(head).position === "sticky" ? head.getBoundingClientRect().bottom : 0;
  const margin = 16;
  let delta = c.bottom + margin - window.innerHeight;
  delta = Math.min(delta, t.top - headBottom - margin);
  if (delta < 1) return;
  window.scrollBy({ top: delta, behavior: smooth ? "smooth" : "auto" });
}

/** How long the nudged step stays highlighted (two 600ms rings when motion is allowed, a still colour when not). */
const NUDGE_MS = 1200;
const nudgeTimers = new WeakMap<HTMLElement, number>();

/**
 * An early tap on the grey Book button: there is nothing to book yet, so the
 * step that is still waiting gets the attention instead — scrolled under the
 * header when it is off screen, then its head pulses (calculator.css
 * `.nudge`; a still orange highlight when motion is reduced). The class comes
 * off again after the pulse, so a second tap replays it.
 */
function nudge(el: HTMLElement | null, smooth: boolean): void {
  if (!el) return;
  const r = el.getBoundingClientRect();
  const head = document.querySelector<HTMLElement>(".site-head");
  const headBottom = head && getComputedStyle(head).position === "sticky" ? head.getBoundingClientRect().bottom : 0;
  const margin = 16;
  if (r.top < headBottom + margin || r.bottom > window.innerHeight - margin) {
    window.scrollBy({ top: r.top - headBottom - margin, behavior: smooth ? "smooth" : "auto" });
  }
  el.classList.remove("nudge");
  void el.offsetWidth; // flushes style between the removal and the re-add, so a second tap restarts the ring
  el.classList.add("nudge");
  const prev = nudgeTimers.get(el);
  if (prev) clearTimeout(prev);
  nudgeTimers.set(
    el,
    window.setTimeout(() => el.classList.remove("nudge"), NUDGE_MS),
  );
}

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

/** "1 Country" / "2 Weight" / "3 Your price": the numbered, iconed head of a step. The ref lets an early Book tap nudge it. */
function StepHead({ n, icon, name, children, ref }: { n: number; icon: ReactNode; name: string; children?: ReactNode; ref?: Ref<HTMLDivElement> }) {
  return (
    <div ref={ref} className="step-head">
      <span className="n" aria-hidden="true">
        {n}
      </span>
      {icon}
      <span className="step-name">{name}</span>
      {children}
    </div>
  );
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

  // The fine print under the calculator — what is included, the owner's notes, the disclaimer — lives behind one
  // disclosure row, so the panel that should have nothing to read shows nothing to read.
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
      </div>
      {includes || disclaimer || notes.length ? (
        <details className="how notes">
          <summary>Good to know before you book</summary>
          {includes ? <p className="includes">{includes}</p> : null}
          {notes.length ? (
            <ul>
              {notes.map((x, i) => (
                <li key={i}>{x}</li>
              ))}
            </ul>
          ) : null}
          {disclaimer ? <p className="disclaimer">{disclaimer}</p> : null}
        </details>
      ) : null}
      {/* The second way into the detailed view (the first is the "Other" weight button). It sits under the notes,
          outside the panel, so nothing competes with the one green button; hidden with the quick view. */}
      <p className="switch" hidden={mode !== "quick"}>
        Documents or big boxes?{" "}
        <button type="button" className="link" onClick={() => switchMode("detail")}>
          Get a detailed price
        </button>
      </p>
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
 * the one figure the visitor compares between the two cards. The arrival
 * dates belong to the chosen service and sit in the price card, so they
 * appear once.
 */
function etaQuick(p: ServicePrice): string {
  return p.days ? `${p.days} days` : "";
}

/** "Pickup and service charges" → "pickup and service charges", so an add-on's label reads on inside "Includes PKR 500 …"; an acronym keeps its case. */
const midSentence = (s: string): string => s.replace(/^[A-Z](?=[a-z])/, (c) => c.toLowerCase());

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

  // The readout's one sentence, in plain words: what to do next, or what the price is for. Live, a second short
  // line says when the parcel arrives (the only place the dates are printed).
  let note: string;
  let when = "";
  let state: ReadoutState;
  let idle = true;
  if (!destId) {
    state = "country";
    note = "Tap a country above";
  } else if (!kgv || kgv === "exact") {
    state = "weight";
    note = "Now tap a weight";
  } else if (kgv === "more" || !res?.ok) {
    state = "cargo";
    note = sets.maxKg > 0 ? `Over ${sets.maxKg} kg? We price it on WhatsApp` : "We price this on WhatsApp";
  } else if (!available.length) {
    state = "none";
    note = `No service to ${dest?.name} yet. Ask us on WhatsApp`;
  } else {
    state = "live";
    idle = false;
    const sv = site.services.find((s) => s.id === chosen)!;
    const est = sets.showEta && p?.days ? estimateDelivery(p.days, sets, null, new Date()) : null;
    note = `${sv.name} · ${kgv} kg · ${dest?.name}`;
    when = est ? `Arrives ${fmtRange(est)}` : "";
  }

  let bookHref: string | null = null;
  let bookLabel = "Book on WhatsApp";
  let quote: Quote | null = null;
  if (kgv === "more" && dest) {
    // "Over N kg" is all the visitor has said, so the message says exactly that: the country and the weight, in
    // the same shape as the detailed view's cargo message — but no piece list (there are no pieces to list) and
    // no "about N kg" (it is more than N).
    bookHref = waLink(site.company.whatsapp, `Hi ${site.company.name}, I need a cargo rate.\nTo: ${dest.name}\nWeight: over ${sets.maxKg} kg`);
    bookLabel = "Ask for a cargo rate";
  } else if (state === "none") {
    // No service prices this country at this weight: the one button still opens WhatsApp (the plain chat link).
    bookHref = `https://wa.me/${site.company.whatsapp}`;
    bookLabel = "Ask on WhatsApp";
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

  const act = site.destinations.filter((x) => x.active);
  // Every destination gets a tile while they fit the grid; beyond that the last cell is the "More countries"
  // picker, which lists them all (the admin's order first, then A–Z) and shows the one it chose in place of its label.
  const more = act.length > TILE_MAX;
  const tiles = more ? act.slice(0, TILE_MAX - 1) : act;
  const pickedId = destId && !tiles.some((d) => d.id === destId) ? destId : "";
  const priced = weightOptions(sets);
  const kgs = KG_BUTTONS.filter((k) => priced.includes(k));
  const noteIcon = state === "country" ? <UI.pin /> : state === "weight" ? <UI.scale /> : state === "live" ? null : <UI.wa />;

  const totalRef = useRef<HTMLDivElement>(null);
  const ctasRef = useRef<HTMLDivElement>(null);
  const step1Ref = useRef<HTMLDivElement>(null);
  const step2Ref = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();
  // The grey Book button answers an early tap by pointing at whatever is still waiting: step 1 or step 2.
  const earlyTap = () => nudge(state === "weight" ? step2Ref.current : step1Ref.current, !reduced);
  // The key changes on every tap that produces a price (or the note that stands in for one): scroll the result
  // into view then, and only then — never on a service or add-on change, never while a step is still waiting.
  const revealKey = state === "live" || state === "cargo" || state === "none" ? `${destId}|${kgv}` : "";
  useEffect(() => {
    if (!revealKey) return;
    revealPrice(totalRef.current, ctasRef.current, !reduced);
  }, [revealKey, reduced]);

  return (
    <div className="panel quick">
      <h2 className="sr">Get your price</h2>

      <StepHead n={1} icon={<UI.pin />} name="Country" ref={step1Ref}>
        {dest ? (
          <span className="step-done" key={dest.id}>
            <Flag code={flagCode(dest.name, dest.id)} name={dest.name} size={24} />
            {dest.name}
            <UI.check />
          </span>
        ) : null}
      </StepHead>
      <div className="ctiles" role="radiogroup" aria-label="Country">
        {tiles.map((d) => (
          <button key={d.id} type="button" role="radio" className="ctile" aria-checked={destId === d.id} onClick={() => setDestId(d.id)}>
            <Flag code={flagCode(d.name, d.id)} name={d.name} size={32} />
            <span>{d.name}</span>
          </button>
        ))}
        {more ? (
          <select className="ctile-more" aria-label="More countries" data-chosen={pickedId ? "true" : undefined} value={pickedId} onChange={(e) => setDestId(e.target.value)}>
            <DestOptions site={site} placeholder="More countries" />
          </select>
        ) : null}
      </div>

      <StepHead n={2} icon={<UI.scale />} name="Weight" ref={step2Ref}>
        {kgv && kgv !== "more" ? (
          <span className="step-done" key={kgv}>
            {kgv} kg
            <UI.check />
          </span>
        ) : null}
      </StepHead>
      <div className="kgs" role="group" aria-label="Weight">
        {kgs.map((k) => (
          <button key={k} type="button" className="kg" aria-pressed={kgv === String(k)} onClick={() => onKg(String(k))}>
            <strong>{k}</strong>
            <span>kg</span>
          </button>
        ))}
        {sets.maxKg > 0 ? (
          <button type="button" className="kg more" aria-pressed={kgv === "more"} onClick={() => onKg("more")}>
            <UI.box />
            Over {sets.maxKg} kg
          </button>
        ) : null}
        {/* One word and the document glyph: the detailed view it opens explains itself (documents, box sizes, many
            boxes); a three-noun label here was one more thing to read on the row that should need none. */}
        <button type="button" className="kg other" onClick={() => onKg("exact")}>
          <CardIcons.doc />
          Other
        </button>
      </div>

      <StepHead n={3} icon={<UI.tag />} name="Your price" />
      {/* Step 3 stacks: the two services, the price, the one green button. On a short desktop screen (the 1366×768
          tier) it splits in two columns — the choice on the left, the price and the green button on the right — so
          both sit inside the first screen. */}
      <div className="step3">
        <div className="step3-pick">
          <fieldset className="opts" data-state={res?.ok ? "live" : "wait"}>
            <legend className="sr">Service</legend>
            <div>
              {site.services.map((sv) => {
                const sp = res?.ok ? res.prices[sv.id] : null;
                const on = !!sp;
                // The tag sits on the days row, not beside the name, so "Normal" + "Cheapest" never folds under the
                // name while "Express" + "Fastest" holds one line: the pair the visitor compares stays level.
                const badge = on && sv.id === fastest ? <span className="opt-badge">Fastest</span> : on && sv.id === cheapest ? <span className="opt-badge price">Cheapest</span> : null;
                const eta = sp ? etaQuick(sp) : sv.note;
                return (
                  <label key={sv.id} className={`opt${on ? "" : " dim"}`}>
                    <input type="radio" name="q-svc" value={sv.id} disabled={!on} checked={on && chosen === sv.id} onChange={() => setSvc(sv.id)} />
                    <span className="opt-name">{sv.name}</span>
                    {badge || eta ? (
                      <span className="opt-meta">
                        {badge}
                        <span className="opt-eta">{eta}</span>
                      </span>
                    ) : null}
                    {/* The card's figure is the visitor's figure: add-ons that are on are inside it, exactly as they are
                        inside the price card below, so the two numbers he sees for one service are the same number. */}
                    <span className="opt-price">{sp ? fmtMoney(sp.total + addonsTotal, cur) : null}</span>
                  </label>
                );
              })}
            </div>
          </fieldset>
        </div>
        <div className="step3-price">
          {/* The readout keeps its element across values so the count carries on; the keyed sweep replays once per new sum.
              Under the figure: what it is for, when the parcel arrives, and what is included — three short lines, no
              decision to make (the detailed view keeps the itemised quote and the add-on opt-out). */}
          <div ref={totalRef} className={`total${idle ? " idle" : ""}`} data-state={state} data-sum={idle ? "" : String(sum)} aria-live="polite">
            <span className="tlabel">Your price</span>
            <PriceReadout value={idle ? 0 : sum} currency={cur} idle={idle} />
            {!idle ? <i className="sweep" key={sum} aria-hidden="true" /> : null}
            <span className="tnote">
              {noteIcon}
              {note}
            </span>
            {when ? (
              <span className="twhen">
                <UI.clock />
                {when}
              </span>
            ) : null}
            {!idle && selectedAddons.length ? (
              <span className="tlines">
                {selectedAddons.map((a) => (
                  <span key={a.id} className="tline">
                    <UI.check />
                    Includes {fmtMoney(a.amount, cur)} {midSentence(a.label)}
                  </span>
                ))}
              </span>
            ) : null}
          </div>
          <ClickSpark sparkColor="#ea580c" sparkSize={10} sparkRadius={22} sparkCount={10} duration={450}>
            <div ref={ctasRef} className="ctas" data-live={!idle}>
              <Pull>
                {/* Grey until there is a price (a button, still focusable, that answers a tap by nudging the waiting step);
                    green with the WhatsApp link once there is — the only button under the price. Call and a plain
                    WhatsApp chat live in the header and the band at the foot of the page. */}
                <a
                  className="btn book giant"
                  href={bookHref ?? undefined}
                  role={bookHref ? undefined : "button"}
                  tabIndex={bookHref ? undefined : 0}
                  aria-disabled={bookHref ? undefined : "true"}
                  target="_blank"
                  rel="noopener"
                  onClick={(e) => {
                    if (!bookHref) {
                      e.preventDefault();
                      earlyTap();
                      return;
                    }
                    if (quote) logQuote(quote, { booked: true, mode: "quick", addons: selectedAddons.map((a) => a.label) });
                  }}
                  onKeyDown={(e) => {
                    if (bookHref || (e.key !== "Enter" && e.key !== " ")) return;
                    e.preventDefault();
                    earlyTap();
                  }}
                >
                  <UI.wa />
                  {bookLabel}
                </a>
              </Pull>
            </div>
          </ClickSpark>
        </div>
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
        () => showToast("Copy failed — select the text instead"),
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
        <h2 className="step step-head">
          <span className="n" aria-hidden="true">
            1
          </span>
          Where to?
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
            <span className="lab">
              To
              {destSel ? (
                <span className="step-done" key={destSel.id}>
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
        <h2 className="step step-head">
          <span className="n" aria-hidden="true">
            2
          </span>
          What are you sending?
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
        <h2 className="step step-head">
          <span className="n" aria-hidden="true">
            3
          </span>
          Choose a service
        </h2>
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
          <ClickSpark sparkColor="#ea580c" sparkSize={10} sparkRadius={22} sparkCount={10} duration={450}>
            <div className="actions">
              <Pull>
                <a
                  className="btn book giant"
                  target="_blank"
                  rel="noopener"
                  href={waLink(site.company.whatsapp, text)}
                  onClick={() => logQuote(quote, { booked: true, mode: "detail", addons: selectedAddons.map((a) => a.label), contents })}
                >
                  <UI.wa />
                  Book on WhatsApp
                </a>
              </Pull>
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
          </ClickSpark>
        </div>
      ) : null}
    </>
  );
}
