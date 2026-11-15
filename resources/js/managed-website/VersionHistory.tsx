import React, { useId, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import "../../css/website-version-history.css";

type SavedVersion = { id: number; version_number: number; title: string; status: string; created_at: string; is_current: boolean; can_restore: boolean; restore_url: string };
type VersionGroup = { current_version_id: number; versions: SavedVersion[] };
type History = { page: VersionGroup; site: VersionGroup; limits: { page_drafts: number; site_drafts: number; published_shown?: number }; retention_applies?: boolean; can_restore: boolean };

export function VersionHistory({ url, csrf, beforeOpen, label = "History" }: { url: string; csrf: string; beforeOpen: () => Promise<boolean>; label?: string }) {
  const id = useId();
  const dialog = useRef<HTMLDialogElement>(null);
  const launcher = useRef<HTMLButtonElement>(null);
  const generation = useRef(0);
  const operation = useRef(false);
  const [data, setData] = useState<History | null>(null);
  const [tab, setTab] = useState<"page" | "site">("page");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const close = () => { if (operation.current) return; generation.current++; dialog.current?.close(); launcher.current?.focus(); };
  const open = async () => {
    if (operation.current || dialog.current?.open) return;
    const request = ++generation.current;
    setData(null); setError(""); setBusy(true); dialog.current?.showModal();
    try {
      if (!(await beforeOpen())) throw new Error("Save your latest changes before opening history.");
      const response = await fetch(url, { headers: { Accept: "application/json" }, signal: AbortSignal.timeout(30000) });
      if (!response.ok) throw new Error("Saved versions could not be loaded. Close and try again.");
      const result = await response.json() as History;
      if (!Array.isArray(result.page?.versions) || !Array.isArray(result.site?.versions) || !result.limits
        || typeof result.can_restore !== "boolean") throw new Error("Saved versions could not be confirmed. Close and try again.");
      if (request === generation.current) setData(result);
    } catch (failure) {
      if (request === generation.current) setError(failure instanceof Error ? failure.message : "Saved versions could not be loaded. Close and try again.");
    } finally { if (request === generation.current) setBusy(false); }
  };
  const restore = async (version: SavedVersion) => {
    if (!data?.can_restore || !version.can_restore || operation.current || busy) return;
    operation.current = true; setBusy(true); setError("");
    try {
      const target = new URL(version.restore_url, window.location.origin);
      if (target.origin !== window.location.origin || !target.pathname.startsWith("/website/")) throw new Error("The saved version could not be confirmed.");
      const response = await fetch(target.href, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": csrf },
        body: JSON.stringify({ current_version_id: data[tab].current_version_id }), signal: AbortSignal.timeout(30000) });
      if (!response.ok) throw new Error(response.status === 409 ? "A newer draft was saved. Close and reopen history before restoring." : response.status === 423 ? "Editing is paused. Your saved versions remain available." : "Restore was not confirmed. Close and check your draft before trying again.");
      const receipt = await response.json() as { status?: string; version_number?: number };
      if (receipt.status !== "draft_restored" || typeof receipt.version_number !== "number") throw new Error("Restore was not confirmed. Close and check your draft before trying again.");
      window.location.reload();
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "Restore was not confirmed. Check your draft before trying again.");
      operation.current = false; setBusy(false);
    }
  };
  return <>
    <button ref={launcher} className="eb-button eb-button--quiet" type="button" aria-label={label} onClick={() => void open()}>History</button>
    <dialog ref={dialog} className="eb-version-history" aria-labelledby={`${id}-title`} onCancel={(event) => { event.preventDefault(); close(); }}>
      <header><h2 id={`${id}-title`}>Saved versions</h2><button type="button" disabled={operation.current} onClick={close} aria-label="Close saved versions">×</button></header>
      <p>Restore a private draft. Publish when ready.</p>
      {data && <><p className="eb-version-history__limit">{data.retention_applies ? "Autosaves kept" : "Recent autosaves shown"}: {data.limits.page_drafts} per page · {data.limits.site_drafts} for site settings. Published versions stay saved; up to {data.limits.published_shown || 30} recent versions are shown.</p>
        <div className="eb-version-history__tabs" role="tablist" aria-label="Version history" onKeyDown={(event) => {
          if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
          event.preventDefault(); const next = event.key === "Home" ? "page" : event.key === "End" ? "site" : tab === "page" ? "site" : "page";
          setTab(next); document.getElementById(`${id}-${next}`)?.focus();
        }}>{([['page', 'Page'], ['site', 'Site settings']] as const).map(([key, title]) => <button type="button" role="tab" id={`${id}-${key}`} key={key} disabled={busy} aria-selected={tab === key} aria-controls={`${id}-versions`} tabIndex={tab === key ? 0 : -1} onClick={() => setTab(key)}>{title}</button>)}</div>
        <div role="tabpanel" id={`${id}-versions`} aria-labelledby={`${id}-${tab}`}><ol>{data[tab].versions.map((version) => <li key={version.id}>
          <div><strong>{version.title || `Version ${version.version_number}`}</strong><span>Version {version.version_number} · {version.is_current ? "Current" : version.status === "published" ? "Published" : "Autosaved"}</span><time dateTime={version.created_at}>{new Date(version.created_at).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" })}</time></div>
          {data.can_restore && version.can_restore && !version.is_current && <button type="button" disabled={busy} onClick={() => void restore(version)} aria-label={`Restore ${tab === "page" ? "page" : "site settings"} version ${version.version_number}`}>Restore draft</button>}
        </li>)}</ol>{!data[tab].versions.length && <p>No saved versions yet.</p>}</div>
        {!data.can_restore && <p>Activate your plan to restore and edit saved work.</p>}
      </>}
      {busy && <p role="status">{operation.current ? "Restoring draft…" : "Loading saved versions…"}</p>}
      {error && <p className="eb-version-history__error" role="alert">{error}</p>}
    </dialog>
  </>;
}

export function mountWebsiteVersionHistoryNow() {
  document.querySelectorAll<HTMLElement>("[data-website-version-history]").forEach((root) => {
    if (root.dataset.mounted || !root.dataset.historyUrl) return;
    root.dataset.mounted = "true";
    createRoot(root).render(<VersionHistory url={root.dataset.historyUrl} label={root.dataset.label}
      csrf={document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || ""} beforeOpen={() => Promise.resolve(true)} />);
  });
}
mountWebsiteVersionHistoryNow();
