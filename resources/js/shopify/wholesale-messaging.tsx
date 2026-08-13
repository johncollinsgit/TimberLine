import { createRoot } from "react-dom/client";
import { useState } from "react";
import { requestMessagingJson } from "./messaging/api";
import type { EmailProductTile, EmailSection } from "./messaging/types";
import "./wholesale-messaging.css";

interface Draft {
  id: number; name: string; subject: string; sections: EmailSection[]; personalization: Record<string, string>;
  revision: number; rendered_html: string; locked_footer: boolean; sender: { from_email: string; from_name: string };
}
interface Bootstrap { authorized: boolean; draft: Draft; endpoints: { save: string; test_send: string } }
const node = document.getElementById("wholesale-email-messenger-bootstrap");
const bootstrap = node?.textContent ? JSON.parse(node.textContent) as Bootstrap : null;

function blockLabel(section: EmailSection, number: number) {
  const title = section.type === "heading" ? section.text : section.type === "button" ? section.label : section.type === "image" ? section.alt : section.type === "product_grid_4" ? section.heading : section.type;
  return `${number + 1}. ${title || section.type}`;
}

function plainText(value?: string) {
  return (value || "").replace(/<br\s*\/?\s*>/gi, "\n").replace(/<[^>]*>/g, "").replace(/&nbsp;/gi, " ");
}

function CanvasImage({ section }: { section: EmailSection }) {
  if (!section.imageUrl) return <div className="wem-image-empty">Add an image URL in the inspector.</div>;
  return <img src={section.imageUrl} alt={section.alt || "Email content"} />;
}

function EmailCanvas({ draft, selected, preview, onSelect }: { draft: Draft; selected: number; preview: "desktop" | "mobile"; onSelect: (index: number) => void }) {
  const selectable = (index: number, section: EmailSection) => ({
    className: `wem-canvas-block wem-${section.type} ${selected === index ? "is-selected" : ""}`,
    onClick: () => onSelect(index),
    onKeyDown: (event: React.KeyboardEvent<HTMLDivElement>) => {
      if (event.key === "Enter" || event.key === " ") { event.preventDefault(); onSelect(index); }
    },
    role: "button" as const,
    tabIndex: 0,
    "aria-label": `Edit ${blockLabel(section, index)}`,
  });

  return <section className={`wem-canvas-panel wem-${preview}`} aria-label={`${preview} email preview`}>
    <div className="wem-canvas-meta"><span>{preview === "desktop" ? "Desktop email" : "Mobile email"}</span><span>Click any content block to edit it</span></div>
    <article className="wem-email-canvas">
      <div className="wem-canvas-subject" aria-label="Email subject"><span>Subject</span>{draft.subject}</div>
      {draft.sections.map((section, index) => {
        const props = selectable(index, section);
        if (section.type === "heading") return <div key={section.id} {...props}><h1 style={{ textAlign: section.align || "left" }}>{section.text}</h1></div>;
        if (section.type === "text") return <div key={section.id} {...props}><p>{plainText(section.html)}</p></div>;
        if (section.type === "image") return <div key={section.id} {...props}><CanvasImage section={section} /></div>;
        if (section.type === "button") return <div key={section.id} {...props} style={{ textAlign: section.align || "center" }}><span className="wem-email-button">{section.label || "Learn more"}</span></div>;
        if (section.type === "fading_divider") return <div key={section.id} {...props} style={{ paddingTop: section.spacingTop || 0, paddingBottom: section.spacingBottom || 0 }}><hr /></div>;
        if (section.type === "product_grid_4") return <div key={section.id} {...props}>
          {section.heading && <h2>{section.heading}</h2>}
          <div className="wem-product-grid">{(section.products || []).map((product, productIndex) => <div className="wem-product-card" key={`${product.title || "product"}-${productIndex}`}>
            {product.imageUrl ? <img src={product.imageUrl} alt={product.title || "Candle"} /> : <div className="wem-product-image-empty">Add a product image</div>}
            <strong>{product.title || "Candle"}</strong><span>{product.buttonLabel || "View candle"}</span>
          </div>)}</div>
        </div>;
        return <div key={section.id} {...props}><p>This block is ready to edit.</p></div>;
      })}
      <footer className="wem-canvas-footer"><strong>Locked compliance footer</strong><span>Unsubscribe and privacy links are included automatically when a test is sent.</span></footer>
    </article>
  </section>;
}

function ProductGridInspector({ section, replaceSection }: { section: EmailSection; replaceSection: (change: Partial<EmailSection>) => void }) {
  const products = section.products || [];
  const replaceProduct = (index: number, change: Partial<EmailProductTile>) => replaceSection({ products: products.map((product, productIndex) => productIndex === index ? { ...product, ...change } : product) });
  return <>
    <label>Grid heading<input value={section.heading || ""} onChange={(event) => replaceSection({ heading: event.target.value })} /></label>
    {products.map((product, index) => <fieldset className="wem-product-fields" key={`${product.title || "product"}-${index}`}><legend>Product {index + 1}</legend>
      <label>Name<input value={product.title || ""} onChange={(event) => replaceProduct(index, { title: event.target.value })} /></label>
      <label>Image URL<input value={product.imageUrl || ""} onChange={(event) => replaceProduct(index, { imageUrl: event.target.value })} /></label>
      <label>Product URL<input value={product.href || ""} onChange={(event) => replaceProduct(index, { href: event.target.value })} /></label>
      <label>Link label<input value={product.buttonLabel || ""} onChange={(event) => replaceProduct(index, { buttonLabel: event.target.value })} /></label>
    </fieldset>)}
  </>;
}

function App({ initial }: { initial: Bootstrap }) {
  const [draft, setDraft] = useState(initial.draft);
  const [selected, setSelected] = useState(0);
  const [preview, setPreview] = useState<"desktop" | "mobile">("desktop");
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [testEmail, setTestEmail] = useState("");
  const [testing, setTesting] = useState(false);
  const section = draft.sections[selected];
  const replaceSection = (change: Partial<EmailSection>) => setDraft((current) => ({ ...current, sections: current.sections.map((item, index) => index === selected ? { ...item, ...change } : item) }));

  const save = async (): Promise<boolean> => {
    setSaving(true); setNotice(null);
    try {
      const response = await requestMessagingJson<Draft>(initial.endpoints.save, { method: "POST", body: JSON.stringify({ subject: draft.subject, sections: draft.sections, personalization: draft.personalization, revision: draft.revision }) });
      if (response.data) setDraft(response.data);
      setNotice("Draft saved to the wholesale workspace."); return true;
    } catch (error) { setNotice(error instanceof Error ? error.message : "Draft could not be saved."); return false; }
    finally { setSaving(false); }
  };
  const testSend = async () => {
    const recipients = testEmail.split(/[\s,]+/).map((email) => email.trim()).filter(Boolean);
    if (!recipients.length) { setNotice("Add a test email address first."); return; }
    if (!await save()) return;
    setTesting(true); setNotice(null);
    try {
      const response = await requestMessagingJson<{ summary?: { sent?: number; failed?: number } }>(initial.endpoints.test_send, { method: "POST", body: JSON.stringify({ test_emails: recipients }) });
      setNotice(response.message || "Test email submitted. No campaign recipients were contacted.");
    } catch (error) { setNotice(error instanceof Error ? error.message : "Test email could not be sent."); }
    finally { setTesting(false); }
  };

  return <div className="wem-shell">
    <div className="wem-toolbar"><div><span className="wem-eyebrow">MF Wholesale Backstage</span><strong>{draft.name}</strong><small>From {draft.sender.from_name} &lt;{draft.sender.from_email}&gt; · campaign sending is disabled</small></div><div><button className="wem-button wem-secondary" onClick={() => setPreview(preview === "desktop" ? "mobile" : "desktop")}>{preview === "desktop" ? "Mobile preview" : "Desktop preview"}</button><button className="wem-button" disabled={saving} onClick={() => void save()}>{saving ? "Saving…" : "Save draft"}</button></div></div>
    {notice && <div className="wem-notice">{notice}</div>}
    <div className="wem-grid">
      <aside className="wem-blocks"><h2>16 editable blocks</h2><p>Choose a block here or click it in the email.</p>{draft.sections.map((item, index) => <button key={item.id} onClick={() => setSelected(index)} className={selected === index ? "active" : ""} aria-pressed={selected === index}>{blockLabel(item, index)}</button>)}<div className="wem-locked"><strong>Locked compliance footer</strong><span>Unsubscribe and privacy links are appended server-side.</span></div></aside>
      <EmailCanvas draft={draft} selected={selected} preview={preview} onSelect={setSelected} />
      <aside className="wem-inspector"><div className="wem-inspector-head"><span>Editing block {selected + 1} of 16</span><h2>{blockLabel(section, selected)}</h2></div>
        <label>Subject<input value={draft.subject} maxLength={200} onChange={(event) => setDraft({ ...draft, subject: event.target.value })} /></label>
        <label>Personalization token<input value={draft.personalization.first_name_token || ""} onChange={(event) => setDraft({ ...draft, personalization: { ...draft.personalization, first_name_token: event.target.value } })} /></label>
        <div className="wem-inspector-fields">
          {section.type === "heading" && <label>Heading<input value={section.text || ""} onChange={(event) => replaceSection({ text: event.target.value })} /></label>}
          {section.type === "text" && <label>Copy<textarea value={section.html || ""} onChange={(event) => replaceSection({ html: event.target.value })} /></label>}
          {section.type === "image" && <><label>Image URL<input value={section.imageUrl || ""} onChange={(event) => replaceSection({ imageUrl: event.target.value })} /></label><label>Alt text<input value={section.alt || ""} onChange={(event) => replaceSection({ alt: event.target.value })} /></label><label>Destination URL<input value={section.href || ""} onChange={(event) => replaceSection({ href: event.target.value })} /></label></>}
          {section.type === "button" && <><label>Button label<input value={section.label || ""} onChange={(event) => replaceSection({ label: event.target.value })} /></label><label>Destination URL<input value={section.href || ""} onChange={(event) => replaceSection({ href: event.target.value })} /></label></>}
          {section.type === "product_grid_4" && <ProductGridInspector section={section} replaceSection={replaceSection} />}
          {section.type === "fading_divider" && <><label>Top spacing<input type="number" value={section.spacingTop || 0} onChange={(event) => replaceSection({ spacingTop: Number(event.target.value) })} /></label><label>Bottom spacing<input type="number" value={section.spacingBottom || 0} onChange={(event) => replaceSection({ spacingBottom: Number(event.target.value) })} /></label></>}
        </div>
        <div className="wem-test"><h2>Send a test only</h2><p>Tests go only to addresses entered below. This screen has no campaign send control and never contacts prospects.</p><input aria-label="Test email" placeholder="you@example.com" value={testEmail} onChange={(event) => setTestEmail(event.target.value)} /><button className="wem-button" disabled={testing} onClick={() => void testSend()}>{testing ? "Sending test…" : "Send test email"}</button></div>
      </aside>
    </div>
  </div>;
}

if (bootstrap?.authorized && bootstrap.draft) createRoot(document.getElementById("wholesale-email-messenger-root")!).render(<App initial={bootstrap} />);
