import { createRoot } from "react-dom/client";
import { useMemo, useState } from "react";
import { requestMessagingJson } from "./messaging/api";
import type { EmailSection } from "./messaging/types";
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

function App({ initial }: { initial: Bootstrap }) {
  const [draft, setDraft] = useState(initial.draft);
  const [selected, setSelected] = useState(0);
  const [preview, setPreview] = useState<"desktop" | "mobile">("desktop");
  const [saving, setSaving] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const [testEmail, setTestEmail] = useState("");
  const [testing, setTesting] = useState(false);
  const section = draft.sections[selected];
  const previewHtml = useMemo(() => draft.rendered_html, [draft.rendered_html]);

  const replaceSection = (change: Partial<EmailSection>) => setDraft((current) => ({
    ...current, sections: current.sections.map((item, index) => index === selected ? { ...item, ...change } : item),
  }));
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
    <div className="wem-grid"><aside className="wem-blocks"><h2>16 editable blocks</h2>{draft.sections.map((item, index) => <button key={item.id} onClick={() => setSelected(index)} className={selected === index ? "active" : ""}>{blockLabel(item, index)}</button>)}<div className="wem-locked"><strong>Locked compliance footer</strong><span>Unsubscribe and privacy links are appended server-side.</span></div></aside>
    <main className="wem-editor"><label>Subject<input value={draft.subject} maxLength={200} onChange={(event) => setDraft({ ...draft, subject: event.target.value })} /></label><label>Personalization token<input value={draft.personalization.first_name_token || ""} onChange={(event) => setDraft({ ...draft, personalization: { ...draft.personalization, first_name_token: event.target.value } })} /></label><h2>Edit block {selected + 1}</h2>
      {(section.type === "heading") && <><label>Heading<input value={section.text || ""} onChange={(event) => replaceSection({ text: event.target.value })} /></label></>}
      {section.type === "text" && <label>Copy<textarea value={section.html || ""} onChange={(event) => replaceSection({ html: event.target.value })} /></label>}
      {section.type === "image" && <><label>Image URL<input value={section.imageUrl || ""} onChange={(event) => replaceSection({ imageUrl: event.target.value })} /></label><label>Alt text<input value={section.alt || ""} onChange={(event) => replaceSection({ alt: event.target.value })} /></label><label>Destination URL<input value={section.href || ""} onChange={(event) => replaceSection({ href: event.target.value })} /></label></>}
      {section.type === "button" && <><label>Button label<input value={section.label || ""} onChange={(event) => replaceSection({ label: event.target.value })} /></label><label>Destination URL<input value={section.href || ""} onChange={(event) => replaceSection({ href: event.target.value })} /></label></>}
      {section.type === "product_grid_4" && <><label>Grid heading<input value={section.heading || ""} onChange={(event) => replaceSection({ heading: event.target.value })} /></label><textarea aria-label="Candle products" value={JSON.stringify(section.products || [], null, 2)} onChange={(event) => { try { replaceSection({ products: JSON.parse(event.target.value) }); } catch { /* preserve the last valid product links */ } }} /></>}
      {section.type === "fading_divider" && <><label>Top spacing<input type="number" value={section.spacingTop || 0} onChange={(event) => replaceSection({ spacingTop: Number(event.target.value) })} /></label><label>Bottom spacing<input type="number" value={section.spacingBottom || 0} onChange={(event) => replaceSection({ spacingBottom: Number(event.target.value) })} /></label></>}
      <div className="wem-test"><h2>Send a test only</h2><p>Tests go only to addresses entered below. This screen has no campaign send control and never contacts prospects.</p><input aria-label="Test email" placeholder="you@example.com" value={testEmail} onChange={(event) => setTestEmail(event.target.value)} /><button className="wem-button" disabled={testing} onClick={() => void testSend()}>{testing ? "Sending test…" : "Send test email"}</button></div>
    </main><section className={`wem-preview wem-${preview}`}><div className="wem-preview-title">{preview === "desktop" ? "Desktop" : "Mobile"} preview</div><iframe title="Email preview" sandbox="allow-same-origin" srcDoc={previewHtml} /></section></div></div>;
}

if (bootstrap?.authorized && bootstrap.draft) createRoot(document.getElementById("wholesale-email-messenger-root")!).render(<App initial={bootstrap} />);
