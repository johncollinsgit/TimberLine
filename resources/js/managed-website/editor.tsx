import React, { useEffect, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import "../../css/managed-website-editor.css";
import { PhotoPicker, PhotoAsset, PhotoValue, uploadPhoto } from "./PhotoPicker";
import { VersionHistory } from "./VersionHistory";
import "../../css/website-version-history.css";

type Item = Record<string, string>;
type Block = Record<string, string | Item[] | undefined> & { id: string; type: string; items?: Item[] };
type Page = { id: number; version_id: number; title: string; slug: string; blocks: Block[]; seo: Record<string, string> };
type PageLink = Pick<Page, "id" | "title" | "slug"> & { url: string };
type NavigationItem = { label: string; url: string; type: "page" | "link" };
type Media = PhotoAsset;
type PhotoChange = (value: PhotoValue, expected?: PhotoValue) => void;
type PhotoControls = { photos: PhotoAsset[]; related: PhotoAsset[]; maxBytes: number; help: string; upload: (file: File, alt: string, apply: (asset: PhotoAsset) => void) => Promise<void> };
type Theme = Record<string, unknown> & { announcement?: { enabled?: boolean; text?: string; url?: string }; theme_palette?: Record<string, string>; footer?: { copyright?: string; tagline?: string } };
type ThemeTarget = "global" | "announcement" | "header" | "footer" | "navigation";
type PreviewSelection = { type?: string; kind?: string; blockIndex?: string | null; itemIndex?: string | null; navigationIndex?: string | null; field?: string | null };

const labels: Record<string, string> = { hero: "Hero", text: "Text", image: "Image", image_with_text: "Image with text", service_cards: "Service cards", trust_bar: "Trust bar", gallery: "Gallery", faq_list: "FAQ", testimonial: "Testimonial", cta: "Call to action", contact_form: "Contact form" };
const sectionDescriptions: Record<string, string> = { hero: "A prominent first impression with one next step.", text: "A simple, readable content section.", image_with_text: "Pair an image with a helpful explanation.", cta: "Guide visitors to one clear action.", service_cards: "Group services or offerings into cards.", trust_bar: "Highlight short proof points.", testimonial: "Feature approved customer feedback.", image: "Add a single visual moment.", gallery: "Show a collection of public images.", faq_list: "Answer common customer questions.", contact_form: "Collect a tenant-owned inquiry." };
const categories = [["Story", ["hero", "text", "image_with_text", "cta"]], ["Services", ["service_cards", "trust_bar", "testimonial"]], ["Media", ["image", "gallery"]], ["Contact", ["faq_list", "contact_form"]]] as const;
const publishChecklistMessages = new Set(["Finish the Website checklist before publishing.", "Add your business name and email before publishing.", "A Home page is required before publishing.", "Save your website theme before publishing."]);

function parse<T>(value: string | undefined, fallback: T): T { try { return value ? JSON.parse(value) as T : fallback; } catch { return fallback; } }
function newBlock(type: string, related: PhotoAsset[]): Block { const photo = ["hero", "image", "image_with_text", "cta"].includes(type) ? related[0] : null; return { id: crypto.randomUUID(), type, heading: labels[type] || "Section", body: type === "hero" ? "Add a clear message and one useful next step." : "Add useful, customer-friendly detail.", ...(photo ? { image_url: photo.url, image_alt: photo.alt_text || "" } : {}), items: ["service_cards", "trust_bar", "faq_list", "gallery"].includes(type) ? [] : undefined }; }
function asIndex(value: string | null | undefined): number | null { const index = Number(value); return Number.isInteger(index) && index >= 0 ? index : null; }

function Editor({ root }: { root: HTMLElement }) {
  const pages = parse<PageLink[]>(root.dataset.pages, []);
  const initialSite = parse<{ name: string; version_id: number; status: string; theme: Theme; navigation: NavigationItem[]; preview_url: string; preview_site_url: string }>(root.dataset.site, { name: "Website", version_id: 0, status: "draft", theme: {}, navigation: [], preview_url: "", preview_site_url: "" });
  const related = parse<PhotoAsset[]>(root.dataset.relatedPhotos, []);
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || "";
  const [page, setPage] = useState<Page>(() => { const initial = parse<Page>(root.dataset.page, { id: 0, version_id: 0, title: "", slug: "", blocks: [], seo: {} }); return { ...initial, blocks: initial.blocks.map((block) => ({ ...block, id: block.id || crypto.randomUUID(), items: block.items?.map((item) => ({ ...item, id: crypto.randomUUID() })) })) }; });
  const [site, setSite] = useState(initialSite);
  const [selected, setSelected] = useState(0);
  const [selectedItem, setSelectedItem] = useState<number | null>(null);
  const [selectedField, setSelectedField] = useState<string | null>(null);
  const [mode, setMode] = useState<"sections" | "theme">("sections");
  const [themeTarget, setThemeTarget] = useState<ThemeTarget>("global");
  const [selectedNavigation, setSelectedNavigation] = useState<number | null>(null);
  const [selectedThemeField, setSelectedThemeField] = useState<string | null>(null);
  const [device, setDevice] = useState<"desktop" | "mobile">("desktop");
  const [saveState, setSaveState] = useState("Saved");
  const [media, setMedia] = useState<Media[]>([]);
  const [iframeKey, setIframeKey] = useState(0);
  const [history, setHistory] = useState<Page[]>([]);
  const [future, setFuture] = useState<Page[]>([]);
  const [sectionLibraryOpen, setSectionLibraryOpen] = useState(false);
  const [sectionSearch, setSectionSearch] = useState("");
  const pageRef = useRef(page);
  const pageVersion = useRef(page.version_id);
  const siteVersion = useRef(initialSite.version_id);
  const siteRef = useRef(site);
  const pageRevision = useRef(0);
  const savedPageRevision = useRef(0);
  const themeRevision = useRef(0);
  const savedThemeRevision = useRef(0);
  const pageSave = useRef<Promise<boolean> | null>(null);
  const themeSave = useRef<Promise<boolean> | null>(null);
  const imageUpload = useRef<Promise<boolean> | null>(null);
  const saveErrors = useRef<{ page?: string; theme?: string }>({});
  const publishing = useRef(false);
  const [isPublishing, setIsPublishing] = useState(false);
  const [publishIssue, setPublishIssue] = useState<{ message: string; action: string } | null>(null);
  const previewFrame = useRef<HTMLIFrameElement>(null);
  const selectedBlock = page.blocks[selected];

  const selectBlock = (index: number, itemIndex: number | null = null, field: string | null = null) => { if (!page.blocks[index]) return; setMode("sections"); setSelected(index); setSelectedItem(itemIndex); setSelectedField(field); setSelectedThemeField(null); };
  const selectTheme = (target: ThemeTarget, navigationIndex: number | null = null, field: string | null = null) => { setMode("theme"); setThemeTarget(target); setSelectedNavigation(navigationIndex); setSelectedThemeField(field); setSelectedField(null); setSelectedItem(null); };
  const hasChanges = () => pageRevision.current !== savedPageRevision.current || themeRevision.current !== savedThemeRevision.current;
  const refreshSaveState = () => {
    const error = saveErrors.current.page || saveErrors.current.theme;
    setSaveState(error || (imageUpload.current ? "Uploading image…" : pageSave.current || themeSave.current ? "Saving…" : hasChanges() ? "Unsaved changes" : publishing.current ? "Publishing…" : "Saved"));
  };
  const commit = (next: Page) => { const current = pageRef.current; pageRef.current = next; pageRevision.current++; delete saveErrors.current.page; setHistory((items) => [...items.slice(-29), current]); setFuture([]); setPage(next); refreshSaveState(); };
  const updateBlock = (key: string, value: string) => { const current = pageRef.current; if (!current.blocks[selected]) return; commit({ ...current, blocks: current.blocks.map((block, index) => index === selected ? { ...block, [key]: value } : block) }); };
  const updateItems = (items: Item[]) => { const current = pageRef.current; if (!current.blocks[selected]) return; commit({ ...current, blocks: current.blocks.map((block, index) => index === selected ? { ...block, items: items.map((item) => ({ ...item, id: item.id || crypto.randomUUID() })) } : block) }); };
  const changePhoto = (blockId: string, itemId: string | null, value: PhotoValue, expected?: PhotoValue) => {
    const current = pageRef.current;
    const target = current.blocks.find((block) => block.id === blockId);
    const slot = itemId === null ? target : target?.items?.find((item) => item.id === itemId);
    if (!slot || (expected && (String(slot.image_url || "") !== expected.url || String(slot.image_alt || "") !== expected.alt))) return;
    commit({ ...current, blocks: current.blocks.map((block) => block.id !== blockId ? block : itemId === null
      ? { ...block, image_url: value.url, image_alt: value.alt }
      : { ...block, items: block.items?.map((item) => item.id === itemId ? { ...item, image_url: value.url, image_alt: value.alt } : item) }) });
  };
  const changeLogo: PhotoChange = (value, expected) => {
    const theme = siteRef.current.theme;
    if (expected && (String(theme.logo_url || "") !== expected.url || String(theme.logo_alt || "") !== expected.alt)) return;
    updateTheme({ ...theme, logo_url: value.url, logo_alt: value.alt });
  };
  const changeSocial: PhotoChange = (value, expected) => {
    const current = pageRef.current;
    if (expected && ((current.seo.social_image || "") !== expected.url || (current.seo.social_image_alt || "") !== expected.alt)) return;
    commit({ ...current, seo: { ...current.seo, social_image: value.url, social_image_alt: value.alt } });
  };
  const move = (from: number, to: number) => { if (from === to) return; const current = pageRef.current; const blocks = [...current.blocks]; const [block] = blocks.splice(from, 1); blocks.splice(to, 0, block); commit({ ...current, blocks }); selectBlock(to); };
  const insertSection = (type: string) => { const current = pageRef.current; commit({ ...current, blocks: [...current.blocks, newBlock(type, related)] }); setMode("sections"); setSelected(current.blocks.length); setSelectedItem(null); setSelectedField(null); setSectionLibraryOpen(false); setSectionSearch(""); };
  const savePage = (): Promise<boolean> => {
    if (pageSave.current) return pageSave.current;
    if (pageRevision.current === savedPageRevision.current) return Promise.resolve(true);
    delete saveErrors.current.page;
    const pending = (async () => {
      while (savedPageRevision.current !== pageRevision.current) {
        const revision = pageRevision.current;
        const current = pageRef.current;
        try {
          const response = await fetch(root.dataset.saveUrl || "", { method: "PUT", headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": csrf }, body: JSON.stringify({ current_version_id: pageVersion.current, title: current.title, blocks: current.blocks, seo: current.seo }) });
          if (!response.ok) throw new Error(response.status === 409 ? "This page changed elsewhere. Your edits remain here; copy them before reloading to review the saved version." : "Could not save page. Try Save again.");
          const receipt = await response.json() as { saved_at?: unknown; id?: unknown };
          if (typeof receipt.saved_at !== "string" || !receipt.saved_at || typeof receipt.id !== "number" || receipt.id <= 0) throw new Error("Could not confirm the page save. Your edits remain here.");
          pageVersion.current = receipt.id;
          savedPageRevision.current = revision;
          setIframeKey((key) => key + 1);
        } catch (failure) { saveErrors.current.page = failure instanceof Error && failure.message.startsWith("This page changed elsewhere.") ? failure.message : "Could not save page. Try Save again."; return false; }
      }
      return true;
    })().finally(() => { pageSave.current = null; refreshSaveState(); });
    pageSave.current = pending;
    refreshSaveState();
    return pending;
  };
  const saveTheme = (): Promise<boolean> => {
    if (themeSave.current) return themeSave.current;
    if (themeRevision.current === savedThemeRevision.current) return Promise.resolve(true);
    delete saveErrors.current.theme;
    const pending = (async () => {
      while (savedThemeRevision.current !== themeRevision.current) {
        const revision = themeRevision.current;
        const current = siteRef.current;
        try {
          const response = await fetch(root.dataset.themeSaveUrl || "", { method: "PUT", headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": csrf }, body: JSON.stringify({ current_version_id: siteVersion.current, settings: current.theme, navigation: current.navigation, seo: {} }) });
          if (!response.ok) throw new Error(response.status === 409 ? "Website settings changed elsewhere. Your edits remain here; copy them before reloading to review the saved version." : "Could not save theme. Try Save again.");
          const receipt = await response.json() as { saved_at?: unknown; id?: unknown };
          if (typeof receipt.saved_at !== "string" || !receipt.saved_at || typeof receipt.id !== "number" || receipt.id <= 0) throw new Error("Could not confirm the theme save. Your edits remain here.");
          siteVersion.current = receipt.id;
          savedThemeRevision.current = revision;
          setIframeKey((key) => key + 1);
        } catch (failure) { saveErrors.current.theme = failure instanceof Error && failure.message.startsWith("Website settings changed elsewhere.") ? failure.message : "Could not save theme. Try Save again."; return false; }
      }
      return true;
    })().finally(() => { themeSave.current = null; refreshSaveState(); });
    themeSave.current = pending;
    refreshSaveState();
    return pending;
  };
  const updateTheme = (next: Theme) => { themeRevision.current++; delete saveErrors.current.theme; siteRef.current = { ...siteRef.current, theme: next }; setSite(siteRef.current); refreshSaveState(); };
  const updateNavigation = (next: NavigationItem[]) => { themeRevision.current++; delete saveErrors.current.theme; siteRef.current = { ...siteRef.current, navigation: next }; setSite(siteRef.current); refreshSaveState(); };
  const saveAll = async (): Promise<boolean> => {
    if (imageUpload.current && !await imageUpload.current) return false;
    while (hasChanges()) {
      if (!await savePage() || !await saveTheme()) return false;
    }
    refreshSaveState();
    return true;
  };
  const navigate = async (url: string) => { if (await saveAll()) window.location.assign(url); };
  const previewSite = async () => { const tab = window.open("about:blank", "_blank"); if (!await saveAll()) { tab?.close(); return; } const url = siteRef.current.preview_site_url; if (!url) { tab?.close(); return; } if (tab) { tab.opener = null; tab.location.replace(url); } else window.open(url, "_blank", "noopener,noreferrer"); };
  const publish = async () => {
    if (publishing.current || root.dataset.publishingEnabled !== "true") return;
    publishing.current = true; setIsPublishing(true); setPublishIssue(null);
    try {
      if (!await saveAll()) return;
      const pageAtPublish = pageRevision.current; const themeAtPublish = themeRevision.current;
      setSaveState("Publishing…");
      const response = await fetch(root.dataset.publishUrl || "", { method: "POST", headers: { Accept: "application/json", "X-CSRF-TOKEN": csrf } });
      if (!response.ok) {
        const payload = await response.json().catch(() => null) as { message?: unknown } | null;
        const checklist = response.status === 422;
        const message = checklist
          ? typeof payload?.message === "string" && publishChecklistMessages.has(payload.message) ? payload.message : "Complete the Website checklist before publishing."
          : response.status === 423 ? "Publishing is paused or your plan needs attention."
            : response.status === 403 ? "Only a website owner or admin can publish."
              : response.status === 409 ? "Your website changed elsewhere. Keep your edits here and review the saved draft before publishing."
                : response.status === 401 || response.status === 419 ? "Your session expired. Keep a copy of any new edits before signing in again."
                  : "Publishing was not confirmed. Review your website before trying again.";
        setPublishIssue({ message, action: checklist ? "Review checklist" : "Review Website" });
        setSaveState(hasChanges() ? "Unsaved changes remain." : "Your draft is saved.");
        return;
      }
      const receipt = await response.json() as { status?: string };
      if (!["published", "published_safely"].includes(receipt.status || "")) throw new Error("Publish was not confirmed");
      siteRef.current = { ...siteRef.current, status: "published" }; setSite(siteRef.current);
      setSaveState(hasChanges() ? "Unsaved changes" : pageAtPublish !== pageRevision.current || themeAtPublish !== themeRevision.current ? "Saved changes awaiting publish" : "Published");
    } catch { setSaveState(hasChanges() ? "Could not publish. Unsaved changes remain." : "Could not publish. Your draft is saved. Try again."); }
    finally { publishing.current = false; setIsPublishing(false); }
  };
  const loadMedia = async () => { try { const response = await fetch(root.dataset.mediaUrl || "", { headers: { Accept: "application/json" } }); if (response.ok) { const payload = await response.json() as { media: Media[] }; setMedia(payload.media); } } catch { /* Save and upload remain available during a temporary library outage. */ } };
  const upload = async (file: File, alt: string, apply: (asset: PhotoAsset) => void): Promise<void> => {
    if (imageUpload.current) throw new Error("Wait for the current image to finish uploading.");
    let failure: Error | null = null;
    const pending = (async () => {
      try {
        const asset = await uploadPhoto(root.dataset.mediaUploadUrl || "", csrf, file, alt);
        setMedia((items) => [asset, ...items.filter((item) => item.id !== asset.id)]);
        apply(asset);
        return true;
      } catch (error) {
        failure = error instanceof Error ? error : new Error("Could not upload image. Try again.");
        setSaveState(failure.message); return false;
      }
    })();
    imageUpload.current = pending; refreshSaveState();
    const uploaded = await pending;
    imageUpload.current = null;
    if (!uploaded) throw failure || new Error("Could not upload image. Try again.");
    refreshSaveState();
  };
  const photoControls: PhotoControls = { photos: media, related, maxBytes: Number(root.dataset.mediaMaxBytes) || 10 * 1024 * 1024, help: root.dataset.photoHelp || "", upload };


  useEffect(() => { void loadMedia(); }, []);
  useEffect(() => { if (!hasChanges()) return; const timer = window.setTimeout(() => void saveAll(), 900); return () => window.clearTimeout(timer); }, [page, site.theme, site.navigation]);
  useEffect(() => { const onKey = (event: KeyboardEvent) => { if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "s") { event.preventDefault(); void saveAll(); } }; window.addEventListener("keydown", onKey); return () => window.removeEventListener("keydown", onKey); });
  useEffect(() => { const warn = (event: BeforeUnloadEvent) => { if (hasChanges() || imageUpload.current) { event.preventDefault(); event.returnValue = ""; } }; window.addEventListener("beforeunload", warn); return () => window.removeEventListener("beforeunload", warn); }, []);
  useEffect(() => {
    const field = mode === "sections" ? selectedField : selectedThemeField;
    if (!field) return;
    const timer = window.setTimeout(() => {
      const selector = mode === "sections" && selectedItem !== null
        ? `[data-eb-inspector-field="${field}"][data-eb-item-index="${selectedItem}"]`
        : `[data-eb-inspector-field="${field}"]`;
      document.querySelector<HTMLElement>(selector)?.focus();
    });
    return () => window.clearTimeout(timer);
  }, [mode, selected, selectedField, selectedItem, selectedThemeField]);
  useEffect(() => {
    const receiveSelection = (event: MessageEvent<PreviewSelection>) => {
      if (event.origin !== window.location.origin || event.source !== previewFrame.current?.contentWindow || event.data?.type !== "everbranch.website.editor.select") return;
      const blockIndex = asIndex(event.data.blockIndex); const itemIndex = asIndex(event.data.itemIndex); const navigationIndex = asIndex(event.data.navigationIndex);
      if (event.data.kind === "section" && blockIndex !== null) selectBlock(blockIndex, itemIndex, event.data.field || null);
      if (event.data.kind === "announcement") selectTheme("announcement", null, event.data.field || null);
      if (event.data.kind === "header") selectTheme("header", null, event.data.field || null);
      if (event.data.kind === "footer") selectTheme("footer", null, event.data.field || null);
      if (event.data.kind === "navigation" && navigationIndex !== null && site.navigation[navigationIndex]) selectTheme("navigation", navigationIndex, event.data.field || "navigation_label");
    };
    window.addEventListener("message", receiveSelection); return () => window.removeEventListener("message", receiveSelection);
  }, [page.blocks, site.navigation]);

  return <div className="eb-editor">
    <header className="eb-editor-top"><div className="eb-editor-top__left"><a className="eb-icon" href={root.dataset.indexUrl} onClick={(event) => { event.preventDefault(); if (root.dataset.indexUrl) void navigate(root.dataset.indexUrl); }} aria-label="Back to Website">←</a><div className="eb-editor-title"><strong>{site.name}</strong><span className={site.status === "published" ? "is-live" : ""}>{site.status === "published" ? "Live" : "Draft"}</span></div><label className="eb-page-picker"><span className="sr-only">Page</span><select value={page.id} onChange={(event) => { const next = pages.find((item) => item.id === Number(event.target.value)); if (next) void navigate(next.url); }}>{pages.map((item) => <option key={item.id} value={item.id}>{item.slug === "/" ? "Home" : item.title}</option>)}</select></label></div><div className="eb-editor-top__right"><span className="eb-save" aria-live="polite">{saveState}</span><button className="eb-icon" onClick={() => { const previous = history.at(-1); if (!previous) return; setFuture((items) => [page, ...items]); setHistory((items) => items.slice(0, -1)); pageRef.current = previous; pageRevision.current++; delete saveErrors.current.page; setPage(previous); refreshSaveState(); }} disabled={!history.length} aria-label="Undo">↶</button><button className="eb-icon" onClick={() => { const next = future[0]; if (!next) return; setHistory((items) => [...items, page]); setFuture((items) => items.slice(1)); pageRef.current = next; pageRevision.current++; delete saveErrors.current.page; setPage(next); refreshSaveState(); }} disabled={!future.length} aria-label="Redo">↷</button><button className="eb-button eb-button--quiet" onClick={() => void previewSite()}>Preview site</button>{root.dataset.historyUrl && <VersionHistory url={root.dataset.historyUrl} csrf={csrf} beforeOpen={saveAll} />}<button className="eb-button eb-button--quiet" onClick={() => void saveAll()}>Save</button>{root.dataset.privateTrial !== "true" && <button className="eb-button eb-button--primary" disabled={isPublishing || root.dataset.publishingEnabled !== "true"} onClick={() => void publish()}>Publish</button>}</div></header>
    <div className="eb-editor-layout"><aside className="eb-tree"><div className="eb-tabs"><button className={mode === "sections" ? "active" : ""} onClick={() => setMode("sections")}>Sections</button><button className={mode === "theme" ? "active" : ""} onClick={() => selectTheme("global")}>Theme</button></div>{mode === "sections" ? <><div className="eb-tree__heading">{page.title}</div><button className="eb-tree__fixed" onClick={() => selectTheme("announcement")}>Announcement</button><button className="eb-tree__fixed" onClick={() => selectTheme("header")}>Header & menu</button><div className="eb-tree__sections">{page.blocks.map((block, index) => <button key={block.id || `${block.type}-${index}`} draggable onDragStart={(event) => event.dataTransfer.setData("text/plain", String(index))} onDragOver={(event) => event.preventDefault()} onDrop={(event) => { const from = Number(event.dataTransfer.getData("text/plain")); if (Number.isFinite(from)) move(from, index); }} className={selected === index ? "is-selected" : ""} onClick={() => selectBlock(index)}><span className="eb-grip">⋮⋮</span><span>{labels[block.type] || block.type}</span><small>{block.hidden === "true" ? "Hidden" : ""}</small></button>)}</div><button className="eb-add-section" onClick={() => setSectionLibraryOpen(true)}><span aria-hidden="true">＋</span> Add section</button><button className="eb-tree__fixed" onClick={() => selectTheme("footer")}>Footer</button></> : <ThemeTree site={site} pages={pages} updateTheme={updateTheme} updateNavigation={updateNavigation} />}</aside><main className="eb-canvas">{publishIssue && <p className="eb-canvas__hint" role="alert">{publishIssue.message} {root.dataset.indexUrl && <a className="eb-text-button" href={root.dataset.indexUrl} onClick={(event) => { event.preventDefault(); if (root.dataset.indexUrl) void navigate(root.dataset.indexUrl); }}>{publishIssue.action}</a>}</p>}<div className="eb-device-toggle"><button className={device === "desktop" ? "active" : ""} onClick={() => setDevice("desktop")}>Desktop</button><button className={device === "mobile" ? "active" : ""} onClick={() => setDevice("mobile")}>Mobile</button></div><div className={`eb-preview-frame ${device}`}><iframe ref={previewFrame} key={iframeKey} title="Draft website editor canvas" src={site.preview_url} /></div><p className="eb-canvas__hint">Select a sentence, button, image, card, or menu item to edit that exact element. Use Preview site to test customer navigation.</p></main><aside className="eb-inspector">{mode === "sections" ? <SectionInspector block={selectedBlock} photoControls={photoControls} selectedItem={selectedItem} selectedField={selectedField} selectItem={(index) => setSelectedItem(index)} update={updateBlock} updateItems={updateItems} changePhoto={(value, expected) => { if (selectedBlock) changePhoto(selectedBlock.id, selectedItem === null ? null : selectedBlock.items?.[selectedItem]?.id || "", value, expected); }} remove={() => { if (page.blocks.length <= 1) return; commit({ ...page, blocks: page.blocks.filter((_, index) => index !== selected) }); selectBlock(Math.max(0, selected - 1)); }} /> : <ThemeInspector photoControls={photoControls} logoChange={changeLogo} socialChange={changeSocial} seo={page.seo} theme={site.theme} navigation={site.navigation} pages={pages} target={themeTarget} selectedNavigation={selectedNavigation} updateTheme={updateTheme} updateNavigation={updateNavigation} />}</aside></div>
    {sectionLibraryOpen && <SectionLibrary search={sectionSearch} updateSearch={setSectionSearch} add={insertSection} close={() => setSectionLibraryOpen(false)} />}
  </div>;
}

function SectionLibrary({ search, updateSearch, add, close }: { search: string; updateSearch: (value: string) => void; add: (type: string) => void; close: () => void }) { const normalized = search.trim().toLowerCase(); const filtered = categories.map(([category, types]) => [category, types.filter((type) => !normalized || `${category} ${labels[type]}`.toLowerCase().includes(normalized))] as const).filter(([, types]) => types.length > 0); return <div className="eb-library-backdrop" role="presentation" onMouseDown={close}><section className="eb-section-library" role="dialog" aria-modal="true" aria-label="Add a section" onMouseDown={(event) => event.stopPropagation()}><div className="eb-library__top"><strong>Add a section</strong><button onClick={close} aria-label="Close section library">×</button></div><label className="eb-library__search"><span aria-hidden="true">⌕</span><input autoFocus value={search} onChange={(event) => updateSearch(event.target.value)} placeholder="Search sections" /></label><p className="eb-library__hint">Choose a structured section, then tailor its content in the inspector.</p><div className="eb-library__list">{filtered.map(([category, types]) => <section key={category}><h2>{category}</h2>{types.map((type) => <button key={type} onClick={() => add(type)}><span className="eb-library__icon" aria-hidden="true">{type === "image" || type === "gallery" ? "▧" : type === "faq_list" ? "?" : type === "contact_form" ? "✉" : "▤"}</span><span><strong>{labels[type]}</strong><small>{sectionDescriptions[type]}</small></span></button>)}</section>)}</div>{!filtered.length && <p className="eb-library__empty">No section matches “{search}”.</p>}</section></div>; }

function ThemeTree({ site, pages, updateTheme, updateNavigation }: { site: { theme: Theme; navigation: NavigationItem[] }; pages: PageLink[]; updateTheme: (theme: Theme) => void; updateNavigation: (items: NavigationItem[]) => void }) { const announcement = site.theme.announcement || {}; return <div className="eb-theme-tree"><div className="eb-tree__heading">Theme settings</div><label><input type="checkbox" checked={Boolean(announcement.enabled)} onChange={(event) => updateTheme({ ...site.theme, announcement: { ...announcement, enabled: event.target.checked } })} /> Show announcement</label><label>Announcement<input value={String(announcement.text || "")} onChange={(event) => updateTheme({ ...site.theme, announcement: { ...announcement, text: event.target.value } })} /></label><label>Announcement link<input value={String(announcement.url || "")} onChange={(event) => updateTheme({ ...site.theme, announcement: { ...announcement, url: event.target.value } })} /></label><div className="eb-menu-heading">Menu</div>{site.navigation.map((item, index) => <div className="eb-menu-row" key={`${item.url}-${index}`}><input value={item.label} aria-label={`Menu label ${index + 1}`} onChange={(event) => updateNavigation(site.navigation.map((candidate, itemIndex) => itemIndex === index ? { ...candidate, label: event.target.value } : candidate))} /><select value={item.url} aria-label={`Menu destination ${index + 1}`} onChange={(event) => updateNavigation(site.navigation.map((candidate, itemIndex) => itemIndex === index ? { ...candidate, url: event.target.value, type: "page" } : candidate))}>{pages.map((candidate) => <option key={candidate.id} value={candidate.slug === "/" ? "/" : `/${candidate.slug}`}>{candidate.title}</option>)}</select><button onClick={() => updateNavigation(site.navigation.filter((_, itemIndex) => itemIndex !== index))} aria-label="Remove menu item">×</button></div>)}<button className="eb-text-button" onClick={() => { const first = pages.find((candidate) => !site.navigation.some((item) => item.url === (candidate.slug === "/" ? "/" : `/${candidate.slug}`))); if (first) updateNavigation([...site.navigation, { label: first.title, url: first.slug === "/" ? "/" : `/${first.slug}`, type: "page" }]); }}>Add a page to menu</button></div>; }

function PhotoSlot({ label = "Image", value, controls, change, urlAttributes = {} }: { label?: string; value: PhotoValue; controls: PhotoControls; change: PhotoChange; urlAttributes?: Record<string, string | number> }) {
  return <PhotoPicker label={label} value={value} photos={controls.photos} related={controls.related} maxBytes={controls.maxBytes} help={controls.help} urlAttributes={urlAttributes} onChange={change} onUpload={(file, alt) => controls.upload(file, alt, (asset) => change({ url: asset.url, alt: asset.alt_text || "" }, value))} />;
}

function ThemeInspector({ photoControls, logoChange, socialChange, seo, theme, navigation, pages, target, selectedNavigation, updateTheme, updateNavigation }: { photoControls: PhotoControls; logoChange: PhotoChange; socialChange: PhotoChange; seo: Record<string, string>; theme: Theme; navigation: NavigationItem[]; pages: PageLink[]; target: ThemeTarget; selectedNavigation: number | null; updateTheme: (theme: Theme) => void; updateNavigation: (items: NavigationItem[]) => void }) {
  const palette = theme.theme_palette || {}; const footer = theme.footer || {}; const announcement = theme.announcement || {};
  const setPalette = (key: string, value: string) => updateTheme({ ...theme, theme_palette: { ...palette, [key]: value } });
  const updateItem = (index: number, patch: Partial<NavigationItem>) => updateNavigation(navigation.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item));
  if (target === "announcement") return <div className="eb-inspector__body"><InspectorHead title="Announcement" detail="This bar appears above the site header." /><label className="eb-toggle"><input type="checkbox" checked={Boolean(announcement.enabled)} onChange={(event) => updateTheme({ ...theme, announcement: { ...announcement, enabled: event.target.checked } })} /> Show announcement</label><label>Message<input data-eb-inspector-field="announcement_text" value={String(announcement.text || "")} onChange={(event) => updateTheme({ ...theme, announcement: { ...announcement, text: event.target.value } })} /></label><label>Link<input data-eb-inspector-field="announcement_url" value={String(announcement.url || "")} onChange={(event) => updateTheme({ ...theme, announcement: { ...announcement, url: event.target.value } })} placeholder="/contact or https://" /></label></div>;
  if (target === "navigation" && selectedNavigation !== null && navigation[selectedNavigation]) { const item = navigation[selectedNavigation]; return <div className="eb-inspector__body"><InspectorHead title="Menu item" detail="This link stays inside your Website preview when it points to one of your pages." /><label>Label<input data-eb-inspector-field="navigation_label" value={item.label} onChange={(event) => updateItem(selectedNavigation, { label: event.target.value })} /></label><label>Destination<select data-eb-inspector-field="navigation_url" value={item.url} onChange={(event) => updateItem(selectedNavigation, { url: event.target.value, type: "page" })}>{pages.map((candidate) => <option key={candidate.id} value={candidate.slug === "/" ? "/" : `/${candidate.slug}`}>{candidate.title}</option>)}</select></label><button className="eb-delete" onClick={() => updateNavigation(navigation.filter((_, index) => index !== selectedNavigation))}>Remove menu item</button></div>; }
  if (target === "footer") return <div className="eb-inspector__body"><InspectorHead title="Footer" detail="Shown at the bottom of every page." /><label>Footer line<input data-eb-inspector-field="footer_tagline" value={String(footer.tagline || "")} onChange={(event) => updateTheme({ ...theme, footer: { ...footer, tagline: event.target.value } })} /></label><label>Copyright<input data-eb-inspector-field="footer_copyright" value={String(footer.copyright || "")} onChange={(event) => updateTheme({ ...theme, footer: { ...footer, copyright: event.target.value } })} /></label></div>;
  return <div className="eb-inspector__body"><InspectorHead title={target === "header" ? "Header & menu" : "Theme settings"} detail={target === "header" ? "Your site name and menus are shared across pages." : "Applied to the entire website draft."} /><label>Theme name<input data-eb-inspector-field="theme_name" value={String(theme.theme_name || "")} onChange={(event) => updateTheme({ ...theme, theme_name: event.target.value })} /></label>{target === "global" && <><label>Typography<select value={String(theme.typography || "sans")} onChange={(event) => updateTheme({ ...theme, typography: event.target.value })}><option value="sans">Modern sans</option><option value="serif">Editorial serif</option><option value="system">System</option></select></label><label>Corner style<select value={String(theme.corners || "soft")} onChange={(event) => updateTheme({ ...theme, corners: event.target.value })}><option value="square">Square</option><option value="soft">Soft</option><option value="rounded">Rounded</option></select></label><div className="eb-colors">{["brand", "accent", "ink", "surface", "soft"].map((key) => <label key={key}>{key}<input type="color" value={String(palette[key] || "#ffffff")} onChange={(event) => setPalette(key, event.target.value)} /></label>)}</div></>}<PhotoSlot label="Logo" value={{ url: String(theme.logo_url || ""), alt: String(theme.logo_alt || "") }} controls={{ ...photoControls, related: [] }} change={logoChange} />{target === "global" && <PhotoSlot label="Social image" value={{ url: seo.social_image || "", alt: seo.social_image_alt || "" }} controls={photoControls} change={socialChange} />}<div className="eb-items"><strong>Menu</strong>{navigation.map((item, index) => <div className="eb-item" key={`${item.url}-${index}`}><label>Label<input value={item.label} onChange={(event) => updateItem(index, { label: event.target.value })} /></label><label>Destination<select value={item.url} onChange={(event) => updateItem(index, { url: event.target.value, type: "page" })}>{pages.map((candidate) => <option key={candidate.id} value={candidate.slug === "/" ? "/" : `/${candidate.slug}`}>{candidate.title}</option>)}</select></label></div>)}</div></div>;
}
function InspectorHead({ title, detail }: { title: string; detail: string }) { return <div className="eb-inspector__head"><strong>{title}</strong><span>{detail}</span></div>; }

function SectionInspector({ block, photoControls, selectedItem, selectedField, selectItem, update, updateItems, changePhoto, remove }: { block?: Block; photoControls: PhotoControls; selectedItem: number | null; selectedField: string | null; selectItem: (index: number | null) => void; update: (key: string, value: string) => void; updateItems: (items: Item[]) => void; changePhoto: PhotoChange; remove: () => void }) {
  if (!block) return <div className="eb-inspector__empty">Select a section to edit.</div>;
  const items = block.items || [];
  const hasItems = ["service_cards", "trust_bar", "faq_list", "gallery"].includes(block.type);
  const selectedCard = selectedItem !== null ? items[selectedItem] : undefined;
  const updateItem = (key: string, value: string) => { if (selectedItem === null) return; updateItems(items.map((item, index) => index === selectedItem ? { ...item, [key]: value } : item)); };
  const itemList = hasItems && <div className="eb-items"><strong>{block.type === "faq_list" ? "Questions" : block.type === "gallery" ? "Images" : "Cards"}</strong><div className="eb-inspector-items">{items.map((item, index) => <button type="button" key={item.id || index} aria-pressed={selectedItem === index} onClick={() => selectItem(index)}>{item.image_url && <img src={item.image_url} alt="" />}<span>{item.heading || item.image_alt || `Image ${index + 1}`}</span><small>{item.image_url ? "Edit" : block.type === "gallery" ? "Add photo" : "Edit"}</small></button>)}</div><button className="eb-text-button" type="button" disabled={items.length >= 12} onClick={() => { updateItems([...items, { id: crypto.randomUUID(), heading: block.type === "gallery" ? "" : "New item", body: block.type === "gallery" ? "" : "Add a useful detail." }]); selectItem(items.length); }}>Add item</button></div>;
  if (selectedCard) return <div className="eb-inspector__body"><InspectorHead title={block.type === "faq_list" ? "Question" : block.type === "gallery" ? "Gallery image" : "Card"} detail="Edit this item." /><button className="eb-text-button" type="button" onClick={() => selectItem(null)}>All {block.type === "gallery" ? "images" : block.type === "faq_list" ? "questions" : "cards"}</button>{block.type !== "gallery" && <><label>Heading<input data-eb-inspector-field="item_heading" data-eb-item-index={selectedItem} value={selectedCard.heading || ""} onChange={(event) => updateItem("heading", event.target.value)} /></label><label>Text<textarea data-eb-inspector-field="item_body" data-eb-item-index={selectedItem} value={selectedCard.body || ""} onChange={(event) => updateItem("body", event.target.value)} /></label></>}<PhotoSlot key={`${block.id}-${selectedCard.id}`} value={{ url: selectedCard.image_url || "", alt: selectedCard.image_alt || "" }} controls={photoControls} change={changePhoto} urlAttributes={{ "data-eb-inspector-field": "item_image", "data-eb-item-index": selectedItem! }} /><button className="eb-delete" type="button" onClick={() => { updateItems(items.filter((_, index) => index !== selectedItem)); selectItem(null); }}>Remove {block.type === "faq_list" ? "question" : "item"}</button>{itemList}</div>;
  return <div className="eb-inspector__body"><InspectorHead title={labels[block.type] || block.type} detail="Section settings" /><label>Eyebrow<input data-eb-inspector-field="label" value={String(block.label || "")} onChange={(event) => update("label", event.target.value)} /></label><label>Heading<input data-eb-inspector-field="heading" value={String(block.heading || "")} onChange={(event) => update("heading", event.target.value)} /></label><label>Text<textarea data-eb-inspector-field="body" value={String(block.body || "")} onChange={(event) => update("body", event.target.value)} /></label><label>Button label<input data-eb-inspector-field="cta_label" value={String(block.cta_label || "")} onChange={(event) => update("cta_label", event.target.value)} /></label><label>Button link<input data-eb-inspector-field="cta_url" value={String(block.cta_url || "")} onChange={(event) => update("cta_url", event.target.value)} placeholder="/contact, https://, tel:" /></label><label className="eb-toggle"><input type="checkbox" checked={block.hidden === "true"} onChange={(event) => update("hidden", event.target.checked ? "true" : "false")} /> Hide this section</label>{block.type !== "gallery" && <PhotoSlot key={block.id} value={{ url: String(block.image_url || ""), alt: String(block.image_alt || "") }} controls={photoControls} change={changePhoto} urlAttributes={{ "data-eb-inspector-field": "image" }} />}{itemList}<button className="eb-delete" type="button" onClick={remove}>Remove section</button></div>;
}

export function mountManagedWebsiteEditorNow() { const root = document.getElementById("managed-website-editor-root"); if (!root || root.dataset.mounted) return; root.dataset.mounted = "true"; createRoot(root).render(<Editor root={root} />); }
mountManagedWebsiteEditorNow();
