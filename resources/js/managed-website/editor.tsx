import React, { useEffect, useMemo, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import "../../css/managed-website-editor.css";

type Block = Record<string, string> & { type: string };
type Page = { id: number; title: string; slug: string; blocks: Block[]; seo: Record<string, string> };

const labels: Record<string, string> = { announcement: "Announcement", header: "Header", hero: "Hero", text: "Text", image: "Image", services: "Services", testimonial: "Testimonial", faq: "FAQ", product_grid: "Featured products", contact_form: "Contact form", cta: "Call to action", footer: "Footer" };
const makeBlock = (type: string): Block => ({ type, heading: labels[type] || "Section", body: type === "hero" ? "Add a clear message for your customers." : "Add clear, helpful details here." });

function Editor({ root }: { root: HTMLElement }) {
  const initial = JSON.parse(root.dataset.page || "{}") as Page;
  const pages = JSON.parse(root.dataset.pages || "[]") as Array<Pick<Page, "id" | "title" | "slug">>;
  const site = JSON.parse(root.dataset.site || "{}") as { name: string; status: string; preview_url?: string | null };
  const [page, setPage] = useState<Page>(initial);
  const [selected, setSelected] = useState(0);
  const [device, setDevice] = useState<"desktop" | "mobile">("desktop");
  const [saving, setSaving] = useState("Saved just now");
  const [history, setHistory] = useState<Page[]>([]);
  const [future, setFuture] = useState<Page[]>([]);
  const dragIndex = useRef<number | null>(null);
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || "";
  const selectedBlock = page.blocks[selected] || null;

  const commit = (next: Page) => { setHistory((items) => [...items.slice(-29), page]); setFuture([]); setPage(next); setSaving("Unsaved changes"); };
  const updateBlock = (key: string, value: string) => { if (!selectedBlock) return; const blocks = page.blocks.map((block, index) => index === selected ? { ...block, [key]: value } : block); commit({ ...page, blocks }); };
  const move = (from: number, to: number) => { if (from === to) return; const blocks = [...page.blocks]; const [block] = blocks.splice(from, 1); blocks.splice(to, 0, block); commit({ ...page, blocks }); setSelected(to); };
  const undo = () => { const previous = history.at(-1); if (!previous) return; setFuture((items) => [page, ...items]); setHistory((items) => items.slice(0, -1)); setPage(previous); setSaving("Unsaved changes"); };
  const redo = () => { const next = future[0]; if (!next) return; setHistory((items) => [...items, page]); setFuture((items) => items.slice(1)); setPage(next); setSaving("Unsaved changes"); };
  const save = async () => {
    setSaving("Saving…");
    const response = await fetch(root.dataset.saveUrl || "", { method: "PUT", headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": csrf }, body: JSON.stringify({ title: page.title, blocks: page.blocks, seo: page.seo }) });
    if (!response.ok) { setSaving("Could not save — check the highlighted content."); return; }
    setSaving("Saved just now");
  };
  const publish = async () => {
    if (root.dataset.publishingEnabled !== "true") return;
    setSaving("Publishing…"); const response = await fetch(root.dataset.publishUrl || "", { method: "POST", headers: { Accept: "application/json", "X-CSRF-TOKEN": csrf } });
    setSaving(response.ok ? "Published safely" : "Could not publish");
  };
  useEffect(() => { const onKey = (event: KeyboardEvent) => { if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "s") { event.preventDefault(); void save(); } }; window.addEventListener("keydown", onKey); return () => window.removeEventListener("keydown", onKey); });

  return <div className="eb-editor">
    <header className="eb-editor-top"><div className="eb-editor-top__left"><a className="eb-icon" href={root.dataset.indexUrl} aria-label="Back to Website">←</a><div className="eb-editor-title"><strong>{site.name}</strong><span className={site.status === "published" ? "is-live" : ""}>{site.status === "published" ? "Live" : "Draft"}</span></div><span className="eb-editor-page">⌂ {page.title}</span></div><div className="eb-editor-top__right"><span className="eb-save" aria-live="polite">{saving}</span><button className="eb-icon" onClick={undo} disabled={!history.length} aria-label="Undo">↶</button><button className="eb-icon" onClick={redo} disabled={!future.length} aria-label="Redo">↷</button>{site.preview_url && <a className="eb-button eb-button--quiet" target="_blank" rel="noreferrer" href={site.preview_url}>Preview</a>}<button className="eb-button" onClick={() => void save()}>Save</button><button className="eb-button eb-button--primary" disabled={root.dataset.publishingEnabled !== "true"} onClick={() => void publish()}>Publish</button></div></header>
    <div className="eb-editor-layout"><aside className="eb-tree"><div className="eb-tree__heading">{page.title}</div><div className="eb-tree__fixed">▤ Announcement</div><div className="eb-tree__fixed">⌁ Header</div><div className="eb-tree__label">Template</div><div className="eb-tree__sections">{page.blocks.map((block, index) => <button key={`${block.type}-${index}`} draggable onDragStart={() => { dragIndex.current = index; }} onDragOver={(e) => e.preventDefault()} onDrop={() => { if (dragIndex.current !== null) move(dragIndex.current, index); dragIndex.current = null; }} className={selected === index ? "is-selected" : ""} onClick={() => setSelected(index)}><span className="eb-grip">⋮⋮</span><span>{labels[block.type] || block.type}</span></button>)}</div><select aria-label="Add section" defaultValue="" onChange={(e) => { if (!e.target.value) return; commit({ ...page, blocks: [...page.blocks, makeBlock(e.target.value)] }); setSelected(page.blocks.length); e.currentTarget.value = ""; }}><option value="">＋ Add section</option>{Object.entries(labels).filter(([type]) => !["announcement", "header", "footer"].includes(type)).map(([type, label]) => <option key={type} value={type}>{label}</option>)}</select><div className="eb-tree__fixed">⌁ Footer</div></aside>
      <main className="eb-canvas"><div className="eb-device-toggle"><button className={device === "desktop" ? "active" : ""} onClick={() => setDevice("desktop")}>▣ Desktop</button><button className={device === "mobile" ? "active" : ""} onClick={() => setDevice("mobile")}>▯ Mobile</button></div><div className={`eb-preview ${device}`}><div className="eb-preview__bar"><strong>{site.name}</strong><span>Services &nbsp; Shop &nbsp; Contact</span></div>{page.blocks.map((block, index) => <section key={`${block.type}-${index}`} className={`eb-preview__section ${block.type} ${selected === index ? "is-selected" : ""}`} onClick={() => setSelected(index)}><button className="eb-preview__insert" onClick={(event) => { event.stopPropagation(); const blocks = [...page.blocks]; blocks.splice(index + 1, 0, makeBlock("text")); commit({ ...page, blocks }); setSelected(index + 1); }}>＋</button>{block.type === "hero" && <small>MAKE IT EASY TO ACT</small>}<h1>{block.heading || labels[block.type]}</h1>{block.body && <p>{block.body}</p>}{block.cta_label && <span className="eb-preview__cta">{block.cta_label}</span>}{block.type === "services" && <div className="eb-preview__cards"><i></i><i></i><i></i></div>}{block.type === "product_grid" && <div className="eb-preview__products"><i></i><i></i><i></i></div>}</section>)}</div></main>
      <aside className="eb-inspector"><div className="eb-inspector__head"><strong>{selectedBlock ? labels[selectedBlock.type] || selectedBlock.type : "Select a section"}</strong><span>Live section settings</span></div>{selectedBlock ? <div className="eb-inspector__body"><label>Heading<input value={selectedBlock.heading || ""} onChange={(e) => updateBlock("heading", e.target.value)} /></label><label>Text<textarea value={selectedBlock.body || ""} onChange={(e) => updateBlock("body", e.target.value)} /></label><label>Button label<input value={selectedBlock.cta_label || ""} onChange={(e) => updateBlock("cta_label", e.target.value)} /></label><label>Button link<input value={selectedBlock.cta_url || ""} onChange={(e) => updateBlock("cta_url", e.target.value)} placeholder="/shop or https://" /></label><button className="eb-delete" onClick={() => { if (page.blocks.length <= 1) return; const blocks = page.blocks.filter((_, index) => index !== selected); commit({ ...page, blocks }); setSelected(Math.max(0, selected - 1)); }}>Remove section</button></div> : <div className="eb-inspector__empty">Select a section in the preview or outline to change it.</div>}</aside>
    </div></div>;
}

export function mountManagedWebsiteEditorNow() { const root = document.getElementById("managed-website-editor-root"); if (!root || root.dataset.mounted) return; root.dataset.mounted = "true"; createRoot(root).render(<Editor root={root} />); }
mountManagedWebsiteEditorNow();
