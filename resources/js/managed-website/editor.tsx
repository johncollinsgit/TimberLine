import React, { ChangeEvent, useEffect, useMemo, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import "../../css/managed-website-editor.css";

type Item = Record<string, string>;
type Block = Record<string, string | Item[] | undefined> & { id: string; type: string; items?: Item[] };
type Page = { id: number; title: string; slug: string; blocks: Block[]; seo: Record<string, string> };
type NavigationItem = { label: string; url: string; type: "page" | "link" };
type Media = { id: number; name: string; url: string; alt_text?: string; mime_type: string };
type Theme = Record<string, unknown> & { announcement?: { enabled?: boolean; text?: string; url?: string }; theme_palette?: Record<string, string>; footer?: { copyright?: string; tagline?: string } };

const labels: Record<string, string> = {
  hero: "Hero", text: "Text", image: "Image", image_with_text: "Image with text", service_cards: "Service cards", trust_bar: "Trust bar", gallery: "Gallery", faq_list: "FAQ", testimonial: "Testimonial", cta: "Call to action", contact_form: "Contact form",
};
const sectionDescriptions: Record<string, string> = {
  hero: "A prominent first impression with one next step.", text: "A simple, readable content section.", image_with_text: "Pair an image with a helpful explanation.", cta: "Guide visitors to one clear action.", service_cards: "Group services or offerings into cards.", trust_bar: "Highlight short proof points.", testimonial: "Feature approved customer feedback.", image: "Add a single visual moment.", gallery: "Show a collection of public images.", faq_list: "Answer common customer questions.", contact_form: "Collect a tenant-owned inquiry.",
};
const categories = [
  ["Story", ["hero", "text", "image_with_text", "cta"]],
  ["Services", ["service_cards", "trust_bar", "testimonial"]],
  ["Media", ["image", "gallery"]],
  ["Contact", ["faq_list", "contact_form"]],
] as const;

function parse<T>(value: string | undefined, fallback: T): T { try { return value ? JSON.parse(value) as T : fallback; } catch { return fallback; } }
function newBlock(type: string): Block {
  return { id: crypto.randomUUID(), type, heading: labels[type] || "Section", body: type === "hero" ? "Add a clear message and one useful next step." : "Add useful, customer-friendly detail.", items: ["service_cards", "trust_bar", "faq_list", "gallery"].includes(type) ? [] : undefined };
}

function Editor({ root }: { root: HTMLElement }) {
  const initial = parse<Page>(root.dataset.page, { id: 0, title: "", slug: "", blocks: [], seo: {} });
  const pages = parse<Array<Pick<Page, "id" | "title" | "slug"> & { url: string }>>(root.dataset.pages, []);
  const initialSite = parse<{ name: string; status: string; theme: Theme; navigation: NavigationItem[]; preview_url: string }>(root.dataset.site, { name: "Website", status: "draft", theme: {}, navigation: [], preview_url: "" });
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || "";
  const [page, setPage] = useState<Page>(initial);
  const [site, setSite] = useState(initialSite);
  const [selected, setSelected] = useState(0);
  const [mode, setMode] = useState<"sections" | "theme">("sections");
  const [device, setDevice] = useState<"desktop" | "mobile">("desktop");
  const [saveState, setSaveState] = useState("Saved");
  const [media, setMedia] = useState<Media[]>([]);
  const [iframeKey, setIframeKey] = useState(0);
  const [history, setHistory] = useState<Page[]>([]);
  const [future, setFuture] = useState<Page[]>([]);
  const [sectionLibraryOpen, setSectionLibraryOpen] = useState(false);
  const [sectionSearch, setSectionSearch] = useState("");
  const pageDirty = useRef(false);
  const themeDirty = useRef(false);
  const selectedBlock = page.blocks[selected];

  const mediaByUrl = useMemo(() => new Map(media.map((item) => [item.url, item])), [media]);
  const commit = (next: Page) => { pageDirty.current = true; setHistory((items) => [...items.slice(-29), page]); setFuture([]); setPage(next); setSaveState("Unsaved changes"); };
  const updateBlock = (key: string, value: string) => { if (!selectedBlock) return; commit({ ...page, blocks: page.blocks.map((block, index) => index === selected ? { ...block, [key]: value } : block) }); };
  const updateItems = (items: Item[]) => { if (!selectedBlock) return; commit({ ...page, blocks: page.blocks.map((block, index) => index === selected ? { ...block, items } : block) }); };
  const move = (from: number, to: number) => { if (from === to) return; const blocks = [...page.blocks]; const [block] = blocks.splice(from, 1); blocks.splice(to, 0, block); commit({ ...page, blocks }); setSelected(to); };
  const insertSection = (type: string) => { commit({ ...page, blocks: [...page.blocks, newBlock(type)] }); setSelected(page.blocks.length); setSectionLibraryOpen(false); setSectionSearch(""); };
  const savePage = async () => {
    if (!pageDirty.current) return;
    setSaveState("Saving page…");
    const response = await fetch(root.dataset.saveUrl || "", { method: "PUT", headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": csrf }, body: JSON.stringify({ title: page.title, blocks: page.blocks, seo: page.seo }) });
    if (!response.ok) { setSaveState("Could not save page"); return; }
    pageDirty.current = false; setSaveState("Saved"); setIframeKey((key) => key + 1);
  };
  const saveTheme = async () => {
    if (!themeDirty.current) return;
    setSaveState("Saving theme…");
    const response = await fetch(root.dataset.themeSaveUrl || "", { method: "PUT", headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": csrf }, body: JSON.stringify({ settings: site.theme, navigation: site.navigation, seo: {} }) });
    if (!response.ok) { setSaveState("Could not save theme"); return; }
    themeDirty.current = false; setSaveState("Saved"); setIframeKey((key) => key + 1);
  };
  const updateTheme = (next: Theme) => { themeDirty.current = true; setSite((current) => ({ ...current, theme: next })); setSaveState("Unsaved changes"); };
  const updateNavigation = (next: NavigationItem[]) => { themeDirty.current = true; setSite((current) => ({ ...current, navigation: next })); setSaveState("Unsaved changes"); };
  const saveAll = async () => { await savePage(); await saveTheme(); };
  const publish = async () => { if (root.dataset.publishingEnabled !== "true") return; await saveAll(); setSaveState("Publishing…"); const response = await fetch(root.dataset.publishUrl || "", { method: "POST", headers: { Accept: "application/json", "X-CSRF-TOKEN": csrf } }); setSaveState(response.ok ? "Published" : "Could not publish"); };
  const loadMedia = async () => { const response = await fetch(root.dataset.mediaUrl || "", { headers: { Accept: "application/json" } }); if (response.ok) { const payload = await response.json() as { media: Media[] }; setMedia(payload.media); } };
  const upload = async (event: ChangeEvent<HTMLInputElement>) => { const file = event.currentTarget.files?.[0]; if (!file) return; const data = new FormData(); data.append("image", file); data.append("alt_text", ""); setSaveState("Uploading image…"); const response = await fetch(root.dataset.mediaUploadUrl || "", { method: "POST", headers: { Accept: "application/json", "X-CSRF-TOKEN": csrf }, body: data }); if (response.ok) { const payload = await response.json() as { media: Media }; setMedia((items) => [payload.media, ...items]); updateBlock("image_url", payload.media.url); updateBlock("image_alt", payload.media.alt_text || ""); } else setSaveState("Could not upload image"); event.currentTarget.value = ""; };
  useEffect(() => { void loadMedia(); }, []);
  useEffect(() => { if (!pageDirty.current) return; const timer = window.setTimeout(() => void savePage(), 900); return () => window.clearTimeout(timer); }, [page]);
  useEffect(() => { if (!themeDirty.current) return; const timer = window.setTimeout(() => void saveTheme(), 900); return () => window.clearTimeout(timer); }, [site.theme, site.navigation]);
  useEffect(() => { const onKey = (event: KeyboardEvent) => { if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "s") { event.preventDefault(); void saveAll(); } }; window.addEventListener("keydown", onKey); return () => window.removeEventListener("keydown", onKey); });

  return <div className="eb-editor">
    <header className="eb-editor-top">
      <div className="eb-editor-top__left"><a className="eb-icon" href={root.dataset.indexUrl} aria-label="Back to Website">←</a><div className="eb-editor-title"><strong>{site.name}</strong><span className={site.status === "published" ? "is-live" : ""}>{site.status === "published" ? "Live" : "Draft"}</span></div><label className="eb-page-picker"><span className="sr-only">Page</span><select value={page.id} onChange={(event) => { const next = pages.find((item) => item.id === Number(event.target.value)); if (next) window.location.assign(next.url); }}>{pages.map((item) => <option key={item.id} value={item.id}>{item.slug === "/" ? "Home" : item.title}</option>)}</select></label></div>
      <div className="eb-editor-top__right"><span className="eb-save" aria-live="polite">{saveState}</span><button className="eb-icon" onClick={() => { const previous = history.at(-1); if (!previous) return; setFuture((items) => [page, ...items]); setHistory((items) => items.slice(0, -1)); setPage(previous); pageDirty.current = true; }} disabled={!history.length} aria-label="Undo">↶</button><button className="eb-icon" onClick={() => { const next = future[0]; if (!next) return; setHistory((items) => [...items, page]); setFuture((items) => items.slice(1)); setPage(next); pageDirty.current = true; }} disabled={!future.length} aria-label="Redo">↷</button><button className="eb-button eb-button--quiet" onClick={() => void saveAll()}>Save</button><button className="eb-button eb-button--primary" disabled={root.dataset.publishingEnabled !== "true"} onClick={() => void publish()}>Publish</button></div>
    </header>
    <div className="eb-editor-layout">
      <aside className="eb-tree">
        <div className="eb-tabs"><button className={mode === "sections" ? "active" : ""} onClick={() => setMode("sections")}>Sections</button><button className={mode === "theme" ? "active" : ""} onClick={() => setMode("theme")}>Theme</button></div>
        {mode === "sections" ? <>
          <div className="eb-tree__heading">{page.title}</div>
          <div className="eb-tree__fixed">Announcement & header</div>
          <div className="eb-tree__sections">{page.blocks.map((block, index) => <button key={block.id || `${block.type}-${index}`} draggable onDragStart={(event) => event.dataTransfer.setData("text/plain", String(index))} onDragOver={(event) => event.preventDefault()} onDrop={(event) => { const from = Number(event.dataTransfer.getData("text/plain")); if (Number.isFinite(from)) move(from, index); }} className={selected === index ? "is-selected" : ""} onClick={() => setSelected(index)}><span className="eb-grip">⋮⋮</span><span>{labels[block.type] || block.type}</span><small>{block.hidden === "true" ? "Hidden" : ""}</small></button>)}</div>
          <button className="eb-add-section" onClick={() => setSectionLibraryOpen(true)}><span aria-hidden="true">＋</span> Add section</button>
          <div className="eb-tree__fixed">Footer</div>
        </> : <ThemeTree site={site} pages={pages} updateTheme={updateTheme} updateNavigation={updateNavigation} />}
      </aside>
      <main className="eb-canvas"><div className="eb-device-toggle"><button className={device === "desktop" ? "active" : ""} onClick={() => setDevice("desktop")}>Desktop</button><button className={device === "mobile" ? "active" : ""} onClick={() => setDevice("mobile")}>Mobile</button></div><div className={`eb-preview-frame ${device}`}><iframe key={iframeKey} title="Draft website preview" src={site.preview_url} /></div><p className="eb-canvas__hint">This is your saved draft rendered by the same theme engine as the public site.</p></main>
      <aside className="eb-inspector">{mode === "sections" ? <SectionInspector block={selectedBlock} media={media} selectedMedia={selectedBlock?.image_url ? mediaByUrl.get(String(selectedBlock.image_url)) : undefined} update={updateBlock} updateItems={updateItems} upload={upload} remove={() => { if (page.blocks.length <= 1) return; commit({ ...page, blocks: page.blocks.filter((_, index) => index !== selected) }); setSelected(Math.max(0, selected - 1)); }} /> : <ThemeInspector theme={site.theme} updateTheme={updateTheme} />}</aside>
    </div>
    {sectionLibraryOpen && <SectionLibrary search={sectionSearch} updateSearch={setSectionSearch} add={insertSection} close={() => setSectionLibraryOpen(false)} />}
  </div>;
}

function SectionLibrary({ search, updateSearch, add, close }: { search: string; updateSearch: (value: string) => void; add: (type: string) => void; close: () => void }) {
  const normalized = search.trim().toLowerCase();
  const filtered = categories.map(([category, types]) => [category, types.filter((type) => !normalized || `${category} ${labels[type]}`.toLowerCase().includes(normalized))] as const).filter(([, types]) => types.length > 0);
  return <div className="eb-library-backdrop" role="presentation" onMouseDown={close}>
    <section className="eb-section-library" role="dialog" aria-modal="true" aria-label="Add a section" onMouseDown={(event) => event.stopPropagation()}>
      <div className="eb-library__top"><strong>Add a section</strong><button onClick={close} aria-label="Close section library">×</button></div>
      <label className="eb-library__search"><span aria-hidden="true">⌕</span><input autoFocus value={search} onChange={(event) => updateSearch(event.target.value)} placeholder="Search sections" /></label>
      <p className="eb-library__hint">Choose a structured section, then tailor its content in the inspector.</p>
      <div className="eb-library__list">{filtered.map(([category, types]) => <section key={category}><h2>{category}</h2>{types.map((type) => <button key={type} onClick={() => add(type)}><span className="eb-library__icon" aria-hidden="true">{type === "image" || type === "gallery" ? "▧" : type === "faq_list" ? "?" : type === "contact_form" ? "✉" : "▤"}</span><span><strong>{labels[type]}</strong><small>{sectionDescriptions[type]}</small></span></button>)}</section>)}</div>
      {!filtered.length && <p className="eb-library__empty">No section matches “{search}”.</p>}
    </section>
  </div>;
}

function ThemeTree({ site, pages, updateTheme, updateNavigation }: { site: { theme: Theme; navigation: NavigationItem[] }; pages: Array<Pick<Page, "id" | "title" | "slug">>; updateTheme: (theme: Theme) => void; updateNavigation: (items: NavigationItem[]) => void }) {
  const announcement = site.theme.announcement || {};
  return <div className="eb-theme-tree"><div className="eb-tree__heading">Theme settings</div><label><input type="checkbox" checked={Boolean(announcement.enabled)} onChange={(event) => updateTheme({ ...site.theme, announcement: { ...announcement, enabled: event.target.checked } })} /> Show announcement</label><label>Announcement<input value={String(announcement.text || "")} onChange={(event) => updateTheme({ ...site.theme, announcement: { ...announcement, text: event.target.value } })} /></label><label>Announcement link<input value={String(announcement.url || "")} onChange={(event) => updateTheme({ ...site.theme, announcement: { ...announcement, url: event.target.value } })} /></label><div className="eb-menu-heading">Menu</div>{site.navigation.map((item, index) => <div className="eb-menu-row" key={`${item.url}-${index}`}><input value={item.label} aria-label={`Menu label ${index + 1}`} onChange={(event) => updateNavigation(site.navigation.map((candidate, itemIndex) => itemIndex === index ? { ...candidate, label: event.target.value } : candidate))} /><select value={item.url} aria-label={`Menu destination ${index + 1}`} onChange={(event) => updateNavigation(site.navigation.map((candidate, itemIndex) => itemIndex === index ? { ...candidate, url: event.target.value, type: "page" } : candidate))}>{pages.map((page) => <option key={page.id} value={page.slug === "/" ? "/" : `/${page.slug}`}>{page.title}</option>)}</select><button onClick={() => updateNavigation(site.navigation.filter((_, itemIndex) => itemIndex !== index))} aria-label="Remove menu item">×</button></div>)}<button className="eb-text-button" onClick={() => { const first = pages.find((page) => !site.navigation.some((item) => item.url === (page.slug === "/" ? "/" : `/${page.slug}`))); if (first) updateNavigation([...site.navigation, { label: first.title, url: first.slug === "/" ? "/" : `/${first.slug}`, type: "page" }]); }}>Add a page to menu</button></div>;
}

function ThemeInspector({ theme, updateTheme }: { theme: Theme; updateTheme: (theme: Theme) => void }) {
  const palette = theme.theme_palette || {};
  const footer = theme.footer || {};
  const setPalette = (key: string, value: string) => updateTheme({ ...theme, theme_palette: { ...palette, [key]: value } });
  return <div className="eb-inspector__body"><div className="eb-inspector__head"><strong>Theme settings</strong><span>Applied to the entire website draft.</span></div><label>Theme name<input value={String(theme.theme_name || "")} onChange={(event) => updateTheme({ ...theme, theme_name: event.target.value })} /></label><label>Typography<select value={String(theme.typography || "sans")} onChange={(event) => updateTheme({ ...theme, typography: event.target.value })}><option value="sans">Modern sans</option><option value="serif">Editorial serif</option><option value="system">System</option></select></label><label>Corner style<select value={String(theme.corners || "soft")} onChange={(event) => updateTheme({ ...theme, corners: event.target.value })}><option value="square">Square</option><option value="soft">Soft</option><option value="rounded">Rounded</option></select></label><div className="eb-colors">{["brand", "accent", "ink", "surface", "soft"].map((key) => <label key={key}>{key}<input type="color" value={String(palette[key] || "#ffffff")} onChange={(event) => setPalette(key, event.target.value)} /></label>)}</div><label>Footer line<input value={String(footer.tagline || "")} onChange={(event) => updateTheme({ ...theme, footer: { ...footer, tagline: event.target.value } })} /></label><label>Copyright<input value={String(footer.copyright || "")} onChange={(event) => updateTheme({ ...theme, footer: { ...footer, copyright: event.target.value } })} /></label></div>;
}

function SectionInspector({ block, media, selectedMedia, update, updateItems, upload, remove }: { block?: Block; media: Media[]; selectedMedia?: Media; update: (key: string, value: string) => void; updateItems: (items: Item[]) => void; upload: (event: ChangeEvent<HTMLInputElement>) => void; remove: () => void }) {
  if (!block) return <div className="eb-inspector__empty">Select a section in the outline to adjust its content and layout.</div>;
  const items = block.items || [];
  const hasItems = ["service_cards", "trust_bar", "faq_list", "gallery"].includes(block.type);
  return <div className="eb-inspector__body"><div className="eb-inspector__head"><strong>{labels[block.type] || block.type}</strong><span>Section settings</span></div><label>Heading<input value={String(block.heading || "")} onChange={(event) => update("heading", event.target.value)} /></label><label>Text<textarea value={String(block.body || "")} onChange={(event) => update("body", event.target.value)} /></label><label>Button label<input value={String(block.cta_label || "")} onChange={(event) => update("cta_label", event.target.value)} /></label><label>Button link<input value={String(block.cta_url || "")} onChange={(event) => update("cta_url", event.target.value)} placeholder="/contact, https://, tel:" /></label><label className="eb-toggle"><input type="checkbox" checked={block.hidden === "true"} onChange={(event) => update("hidden", event.target.checked ? "true" : "false")} /> Hide this section</label><div className="eb-media-picker"><strong>Image</strong><label className="eb-upload">Upload image<input type="file" accept="image/jpeg,image/png,image/webp,image/avif" onChange={upload} /></label><div className="eb-media-grid">{media.slice(0, 12).map((item) => <button key={item.id} className={selectedMedia?.id === item.id ? "is-selected" : ""} onClick={() => { update("image_url", item.url); update("image_alt", item.alt_text || ""); }}><img src={item.url} alt="" /></button>)}</div><label>Image URL<input value={String(block.image_url || "")} onChange={(event) => update("image_url", event.target.value)} /></label><label>Image description<input value={String(block.image_alt || "")} onChange={(event) => update("image_alt", event.target.value)} /></label></div>{hasItems && <div className="eb-items"><strong>{block.type === "faq_list" ? "Questions" : "Cards"}</strong>{items.map((item, index) => <div className="eb-item" key={index}><input value={item.heading || ""} placeholder="Heading" onChange={(event) => updateItems(items.map((candidate, itemIndex) => itemIndex === index ? { ...candidate, heading: event.target.value } : candidate))} /><textarea value={item.body || ""} placeholder="Detail" onChange={(event) => updateItems(items.map((candidate, itemIndex) => itemIndex === index ? { ...candidate, body: event.target.value } : candidate))} /><button onClick={() => updateItems(items.filter((_, itemIndex) => itemIndex !== index))}>Remove</button></div>)}<button className="eb-text-button" onClick={() => updateItems([...items, { heading: "New item", body: "Add a useful detail." }])}>Add item</button></div>}<button className="eb-delete" onClick={remove}>Remove section</button></div>;
}

export function mountManagedWebsiteEditorNow() { const root = document.getElementById("managed-website-editor-root"); if (!root || root.dataset.mounted) return; root.dataset.mounted = "true"; createRoot(root).render(<Editor root={root} />); }
mountManagedWebsiteEditorNow();
