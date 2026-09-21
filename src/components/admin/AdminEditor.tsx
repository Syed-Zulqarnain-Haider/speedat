"use client";

/**
 * The rates admin. Holds the draft in memory, autosaves it to the server
 * (debounced), shows the diff against the live version, and publishes
 * through a server action that re-validates everything.
 */
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { discardDraftAction, publishAction, restoreVersionAction, saveDraftAction } from "@/app/admin/actions";
import { Toast, useToast } from "@/components/calculator/Toast";
import type { AdminUser } from "@/lib/auth/session";
import type { ImportSummary, IntakeSettings } from "@/lib/import/intake";
import { fmtDateTime } from "@/lib/pricing/format";
import { diffSite, validateSite, warnSite, type Diff } from "@/lib/site/diff";
import type { Hold } from "@/lib/site/hold-shared";
import type { Draft, VersionMeta } from "@/lib/site/repo";
import type { PublishedVersion, SiteData } from "@/lib/site/types";
import { BulkAdjust } from "./BulkAdjust";
import { ContentForm } from "./ContentForm";
import { HoldBar } from "./HoldBar";
import { ImportPanel } from "./ImportPanel";
import { RatesTable } from "./RatesTable";
import { SECTIONS, sectionNo } from "./sections";
import { SettingsForm } from "./SettingsForm";
import { TestPrice } from "./TestPrice";

interface Props {
  live: PublishedVersion;
  draft: Draft;
  versions: VersionMeta[];
  user: AdminUser;
  imports: ImportSummary[];
  intake: IntakeSettings;
  hold: Hold;
  /** Leads with status "new" — the Inbox tile. */
  newLeads?: number;
  /** Emailed or uploaded sheets still waiting for a column map — the Import tile. */
  sheetsWaiting?: number;
}

type SaveState = "saved" | "dirty" | "saving" | "error";

function daysAgo(iso: string): number {
  return Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
}

export function AdminEditor({ live, draft: initial, versions, user, imports, intake, hold: initialHold, newLeads = 0, sheetsWaiting = 0 }: Props) {
  const router = useRouter();
  const liveData: SiteData = useMemo(() => {
    const { version: _v, publishedAt: _p, ...rest } = live;
    void _v;
    void _p;
    return rest;
  }, [live]);
  const [draft, setDraft] = useState<SiteData>(initial.data);
  const [epoch, setEpoch] = useState(0);
  const [save, setSave] = useState<SaveState>("saved");
  const [savedAt, setSavedAt] = useState(initial.updatedAt);
  const [toast, showToast] = useToast();
  const [review, setReview] = useState<{ errors: string[]; diff: Diff; warnings: string[] } | null>(null);
  const [goLive, setGoLive] = useState(true);
  const [hold, setHold] = useState<Hold>(initialHold);
  const [resume, setResume] = useState(true);
  const [pubMsg, setPubMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const readOnly = false;
  const canPublish = user.role === "owner";

  const changes = useMemo(() => diffSite(liveData, draft), [liveData, draft]);
  const age = daysAgo(live.publishedAt);
  const ageText = age === 0 ? "today" : age === 1 ? "yesterday" : `${age} days ago`;
  const liveBy = versions.find((v) => v.version === live.version)?.publishedBy;

  const update = (fn: (d: SiteData) => void) => {
    setDraft((d) => {
      const n = structuredClone(d);
      fn(n);
      return n;
    });
    setSave("dirty");
    setReview(null);
  };

  /** Replace the whole draft (discard / restore / import) and remount the uncontrolled fields. */
  const replace = (data: SiteData, state: SaveState = "saved") => {
    setDraft(data);
    setEpoch((e) => e + 1);
    setSave(state);
    setReview(null);
  };

  // Debounced autosave: nothing runs until an edit marks the draft dirty. Each
  // edit re-arms the timer, so the callback always closes over the latest draft.
  useEffect(() => {
    if (save !== "dirty") return;
    const t = setTimeout(async () => {
      setSave("saving");
      const res = await saveDraftAction(draft);
      if (res.ok) {
        setSavedAt(res.updatedAt);
        // Edits made while saving keep the draft dirty.
        setSave((s) => (s === "saving" ? "saved" : s));
      } else {
        setSave("error");
        showToast(res.message);
      }
    }, 800);
    return () => clearTimeout(t);
  }, [save, draft, showToast]);

  useEffect(() => {
    const warn = (e: BeforeUnloadEvent) => {
      if (save === "dirty" || save === "saving" || save === "error") e.preventDefault();
    };
    window.addEventListener("beforeunload", warn);
    return () => window.removeEventListener("beforeunload", warn);
  }, [save]);

  const showReview = () => {
    setPubMsg(null);
    setReview({ errors: validateSite(draft), diff: diffSite(liveData, draft), warnings: warnSite(draft) });
    setTimeout(() => document.getElementById("reviewbox")?.scrollIntoView({ behavior: "smooth", block: "nearest" }), 0);
  };

  const publish = async () => {
    setBusy(true);
    setPubMsg("Publishing…");
    const res = await publishAction({ data: draft, expectedBase: live.version, goLive: live.live || goLive, resume: hold.on && resume });
    setBusy(false);
    if (!res.ok) {
      setPubMsg(res.message);
      if (res.errors?.length) setReview((r) => (r ? { ...r, errors: res.errors ?? [] } : r));
      return;
    }
    // What we sent is now the live document; adopt it (with the live flag the server applied).
    replace({ ...draft, live: live.live || goLive });
    if (res.resumed) setHold((h) => ({ ...h, on: false, by: user.email, since: new Date().toISOString() }));
    const held = hold.on && !res.resumed;
    setPubMsg(held ? `Published version ${res.version}. Prices stay on hold until you resume them.` : `Published version ${res.version}. Customers see the new prices now.`);
    showToast(`Version ${res.version} is live`);
    router.refresh();
  };

  const discard = async () => {
    setBusy(true);
    const res = await discardDraftAction();
    setBusy(false);
    if (!res.ok) return showToast(res.message);
    replace(res.data);
    showToast("Changes discarded");
  };

  const restore = async (version: number) => {
    setBusy(true);
    const res = await restoreVersionAction(version);
    setBusy(false);
    if (!res.ok) return showToast(res.message);
    replace(res.data);
    showToast(`Version ${version} loaded into the editor — publish to make it live`);
    document.getElementById("sec-rates")?.scrollIntoView({ behavior: "smooth", block: "start" });
  };

  const pricesState = hold.on ? "On hold" : live.live ? "Live" : "Sample";

  return (
    <section className="admin">
      <div className="topbar">
        <div>
          <p className="eyebrow">01 — Rates</p>
          <h1>Rates</h1>
        </div>
        <div className="topbar-right">
          {hold.on ? <span className="pill hold">on hold</span> : live.live ? <span className="pill live">live</span> : <span className="pill sample">sample rates</span>}
          {changes.count ? <span className="pill draft">draft edited</span> : null}
          <span className={`meta${age >= 7 ? " warn" : ""}`}>
            Version {live.version} · published {fmtDateTime(live.publishedAt)}
            {liveBy ? ` · by ${liveBy}` : ""}
          </span>
        </div>
        <HoldBar hold={hold} isOwner={canPublish} onChange={setHold} toast={showToast} />
      </div>

      <div className="dash">
        <div className={`tile ${age >= 7 ? "tone-warn" : "tone-ok"}`}>
          <span className="eyebrow">Live version</span>
          <span className="val">{live.version}</span>
          <span className="note">published {ageText}</span>
        </div>
        <div className={`tile ${hold.on || !live.live ? "tone-warn" : "tone-ok"}`}>
          <span className="eyebrow">Prices</span>
          <span className="val">{pricesState}</span>
          <span className="note">{hold.on ? "customers see no prices" : `customers see version ${live.version}`}</span>
        </div>
        <Link className={`tile${newLeads > 0 ? " tone-hot" : ""}`} href="/admin/inbox">
          <span className="eyebrow">New leads</span>
          <span className="val">{newLeads}</span>
          <span className="note">in the inbox</span>
        </Link>
        <a className={`tile${sheetsWaiting > 0 ? " tone-hot" : ""}`} href="#sec-import">
          <span className="eyebrow">Sheets waiting</span>
          <span className="val">{sheetsWaiting}</span>
          <span className="note">need a column map</span>
        </a>
      </div>

      <nav className="subnav sections" aria-label="Sections">
        {SECTIONS.map(([id, label]) => (
          <a
            key={id}
            href={`#sec-${id}`}
            onClick={(e) => {
              e.preventDefault();
              document.getElementById(`sec-${id}`)?.scrollIntoView({ behavior: "smooth", block: "start" });
            }}
          >
            {label}
          </a>
        ))}
      </nav>
      {!canPublish ? <div className="notice info">You can edit and stage changes; an owner publishes them.</div> : null}
      {!live.live ? (
        <div className="notice warn">The website is still showing sample rates. Replace them and publish; the sample notice disappears when you tick “Rates are live”.</div>
      ) : null}
      {age >= 7 && live.live ? <div className="notice warn">Rates were last published {age} days ago. If your carrier sent new rates since, import them below.</div> : null}
      {initial.updatedBy !== "system" && initial.updatedBy !== user.email && changes.count ? (
        <div className="notice info">
          This draft was last edited by {initial.updatedBy} on {fmtDateTime(initial.updatedAt)}.
        </div>
      ) : null}

      <RatesTable draft={draft} live={liveData} changed={changes.byDest} readOnly={readOnly} update={update} epoch={epoch} toast={showToast} />

      <ImportPanel
        draft={draft}
        imports={imports}
        intake={intake}
        isOwner={canPublish}
        adopt={(data) => replace(data, "saved")}
        adoptLocal={(data) => replace(data, "dirty")}
        toast={showToast}
        refresh={() => router.refresh()}
      />

      <BulkAdjust draft={draft} readOnly={readOnly} update={update} toast={showToast} />
      <TestPrice draft={draft} />
      <SettingsForm draft={draft} readOnly={readOnly} update={update} epoch={epoch} />
      <ContentForm draft={draft} readOnly={readOnly} update={update} epoch={epoch} />

      <section className="block" id="sec-history">
        <p className="eyebrow">{sectionNo("history")} — History</p>
        <h2>Version history</h2>
        <p className="desc">Every publish is kept. Restore an older version into the editor, review the differences, and publish it again.</p>
        {versions.length ? (
          <ul className="hist versions">
            {versions.map((v) => (
              <li key={v.version} className={v.version === live.version ? "is-live" : ""}>
                <strong className="ver">v{v.version}</strong>
                <span className="meta">{fmtDateTime(v.publishedAt)}</span>
                <span className="meta">
                  {v.summary} · {v.publishedBy}
                </span>
                {v.version !== live.version ? (
                  <button className="btn small outline" type="button" disabled={busy} onClick={() => restore(v.version)}>
                    Restore into editor
                  </button>
                ) : (
                  <span className="tag">live</span>
                )}
              </li>
            ))}
          </ul>
        ) : (
          <p className="hint">No earlier versions yet.</p>
        )}
      </section>

      <div id="reviewbox">
        {review && review.errors.length ? (
          <div className="notice err">
            <strong>Fix these before publishing</strong>
            <ul>
              {review.errors.map((e, i) => (
                <li key={i}>{e}</li>
              ))}
            </ul>
          </div>
        ) : review ? (
          <div className="card review">
            <p className="eyebrow">Review</p>
            <h2>Publishing version {live.version + 1}</h2>
            <p className="desc">
              {review.diff.count} change{review.diff.count === 1 ? "" : "s"}.{" "}
              {hold.on ? "Prices are on hold; choose below whether this publish shows them again." : "Customers see the new prices as soon as it is published."}
            </p>
            <ul className="diff">
              {review.diff.lines.map((l, i) => (
                <li key={i}>
                  {l.label}
                  {l.old != null || l.new != null ? ": " : ""}
                  {l.old != null ? <span className="old">{l.old}</span> : null}
                  {l.new != null ? <span className="new">{l.new}</span> : null}
                  {l.flag ? <span className="flag"> {l.flag}</span> : null}
                </li>
              ))}
            </ul>
            {review.warnings.length ? (
              <div className="notice warn">
                <strong>Worth a second look (publishing is still allowed)</strong>
                <ul>
                  {review.warnings.slice(0, 15).map((w, i) => (
                    <li key={i}>{w}</li>
                  ))}
                  {review.warnings.length > 15 ? <li>…and {review.warnings.length - 15} more</li> : null}
                </ul>
              </div>
            ) : null}
            {!live.live ? (
              <label className="chk">
                <input type="checkbox" checked={goLive} onChange={(e) => setGoLive(e.target.checked)} /> Rates are live — remove the sample notice from the website
              </label>
            ) : null}
            {hold.on ? (
              <label className="chk">
                <input type="checkbox" checked={resume} onChange={(e) => setResume(e.target.checked)} /> Show prices again after publishing (lifts the hold)
              </label>
            ) : null}
            <div className="review-actions">
              <button className="btn book" type="button" disabled={busy || !canPublish || save === "saving"} onClick={publish}>
                Publish version {live.version + 1}
              </button>
              <button className="btn outline" type="button" onClick={() => setReview(null)}>
                Cancel
              </button>
            </div>
            {pubMsg ? (
              <div className="meta review-msg" aria-live="polite">
                {pubMsg}
              </div>
            ) : null}
          </div>
        ) : pubMsg ? (
          <div className="notice ok">{pubMsg}</div>
        ) : null}
      </div>

      <div className="pubbar">
        <div className="inner">
          <div className="pub-state">
            <strong>{changes.count ? `${changes.count} unpublished change${changes.count === 1 ? "" : "s"}` : "No unpublished changes"}</strong>
            <span className="meta">
              {changes.count ? (hold.on ? "Customers see no prices (on hold). " : `Customers still see version ${live.version}. `) : ""}
              {save === "saving" ? "Saving…" : save === "dirty" ? "Unsaved edits…" : save === "error" ? "Save failed — retrying on next edit" : `Draft saved ${fmtDateTime(savedAt)}`}
            </span>
          </div>
          <div className="pub-actions">
            <button className="btn outline" type="button" disabled={!changes.count || busy} onClick={discard}>
              Discard
            </button>
            <button className="btn book" type="button" disabled={!changes.count || busy} onClick={showReview}>
              Review and publish
            </button>
          </div>
        </div>
      </div>
      <Toast message={toast} />
    </section>
  );
}
