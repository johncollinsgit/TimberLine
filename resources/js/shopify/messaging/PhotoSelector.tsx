import { useEffect, useState } from "react";
import { requestMessagingJson } from "./api";

export interface SelectedPhoto { id: string; url: string; alt: string }
interface FilePickerResponse { code: "ok" | "closed" | "error"; data?: { ids?: unknown }; message?: string }
interface PhotoSelectorProps {
  url?: string;
  alt?: string;
  resolveUrl?: string;
  contextToken?: string | null;
  onSelect: (photo: SelectedPhoto) => void;
  onBusyChange: (busy: boolean) => void;
}

export default function PhotoSelector({ url, alt, resolveUrl, contextToken, onSelect, onBusyChange }: PhotoSelectorProps) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [unavailable, setUnavailable] = useState(false);
  useEffect(() => { setUnavailable(false); setError(null); }, [url]);

  const choosePhoto = async () => {
    const bridge = (window as Window & { shopify?: { intents?: { invoke: (intent: string, options: { data: { mediaTypes: string[]; multiSelect: boolean } }) => Promise<{ complete: Promise<FilePickerResponse> }> } } }).shopify;
    if (!resolveUrl || !bridge?.intents?.invoke) {
      setError("Open this editor from Shopify Admin to choose a photo.");
      return;
    }
    setBusy(true); setError(null); onBusyChange(true);
    try {
      const activity = await bridge.intents.invoke("pick:shopify/File", { data: { mediaTypes: ["MediaImage"], multiSelect: false } });
      const response = await activity.complete;
      if (response.code === "closed") return;
      if (response.code !== "ok") throw new Error(response.message || "The photo selector could not be opened. Please try again.");
      const ids = response.data?.ids;
      if (!Array.isArray(ids) || ids.length !== 1 || typeof ids[0] !== "string") throw new Error("Choose one photo.");
      const result = await requestMessagingJson<SelectedPhoto>(resolveUrl, {
        method: "POST", body: JSON.stringify({ id: ids[0], context_token: contextToken }),
      });
      if (!result.data?.url) throw new Error("That photo is unavailable. Choose another photo.");
      onSelect(result.data);
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "The photo could not be selected. Please try again.");
    } finally { setBusy(false); onBusyChange(false); }
  };

  return <div className="wem-photo-selector">
    <span className="wem-photo-label">Photo</span>
    {url && !unavailable ? <div className="wem-selected-photo"><img src={url} alt={alt || "Selected photo"} onError={() => setUnavailable(true)} /></div> : <div className="wem-photo-placeholder">{unavailable ? "This photo is unavailable. Choose another." : "Choose a photo for this block."}</div>}
    <button className="wem-button wem-secondary" type="button" disabled={busy} onClick={() => void choosePhoto()}>{busy ? "Choosing photo…" : url ? "Change photo" : "Choose photo"}</button>
    {error && <p className="wem-photo-error" role="alert">{error}</p>}
  </div>;
}
