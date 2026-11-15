import React, { useId, useRef, useState } from "react";
import "../../css/website-photo-picker.css";

export type PhotoAsset = { id: number | string; name: string; url: string; catalog_url?: string; alt_text?: string; mime_type?: string; size?: number; source?: string; context?: string };
export type PhotoValue = { url: string; alt: string };
export type PhotoPickerProps = {
  label?: string;
  value: PhotoValue;
  photos: PhotoAsset[];
  related: PhotoAsset[];
  onChange: (value: PhotoValue) => void;
  onUpload?: (file: File, alt: string) => Promise<void>;
  maxBytes?: number;
  help?: string;
  disabled?: boolean;
  allowRemove?: boolean;
  showDescription?: boolean;
  descriptionLabel?: string;
  urlAttributes?: Record<string, string | number>;
};

/** One image slot. The caller owns its immutable target and publication context. */
export function PhotoPicker({ label = "Image", value, photos, related, onChange, onUpload, maxBytes = 10 * 1024 * 1024, help, disabled = false, allowRemove = true, showDescription = true, descriptionLabel = "Image description", urlAttributes = {} }: PhotoPickerProps) {
  const id = useId();
  const launcher = useRef<HTMLButtonElement>(null);
  const fileInput = useRef<HTMLInputElement>(null);
  const [open, setOpen] = useState(false);
  const [tab, setTab] = useState<"library" | "related">("library");
  const [search, setSearch] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [failedPreview, setFailedPreview] = useState("");
  const close = () => { setOpen(false); launcher.current?.focus(); };
  const choose = (photo: PhotoAsset) => { setError(""); onChange({ url: photo.url, alt: photo.alt_text || "" }); };
  const source = tab === "library" ? photos : related;
  const visible = source.filter((photo) => `${photo.name} ${photo.alt_text || ""}`.toLowerCase().includes(search.trim().toLowerCase()));
  const upload = async (input: HTMLInputElement) => {
    const file = input.files?.[0];
    if (!file || !onUpload || busy) return;
    setError("");
    if (!["image/jpeg", "image/png", "image/webp", "image/avif"].includes(file.type)) {
      setError("Choose a JPG, PNG, WebP or AVIF image."); input.value = ""; return;
    }
    if (file.size > maxBytes) {
      setError(`Choose an image smaller than ${Math.round(maxBytes / 1024 / 1024)} MB.`); input.value = ""; return;
    }
    // Keep the caller from the original slot, even if selection changes mid-upload.
    const save = onUpload;
    setBusy(true);
    try { await save(file, ""); setTab("library"); }
    catch (failure) { setError(failure instanceof Error ? failure.message : "Could not upload this image. Try again."); }
    finally { setBusy(false); input.value = ""; }
  };
  return <section className="eb-photo" aria-label={`${label} picker`} aria-busy={busy} onKeyDown={(event) => { if (event.key === "Escape" && open) { event.preventDefault(); event.stopPropagation(); close(); } }}>
    <div className="eb-photo__heading"><strong>{label}</strong>{busy && <span role="status">Uploading…</span>}</div>
    <div className={`eb-photo__preview${value.url ? "" : " is-empty"}`}>
      {value.url && failedPreview !== value.url ? <img src={value.url} alt={value.alt || `${label} preview`} onError={() => setFailedPreview(value.url)} /> : <span>{value.url ? "Preview unavailable" : "Add a photo"}</span>}
    </div>
    <div className="eb-photo__actions">
      <button ref={launcher} {...urlAttributes} type="button" disabled={disabled} aria-expanded={open} aria-controls={`${id}-choices`} onClick={() => setOpen(!open)}>{value.url ? "Replace photo" : "Choose photo"}</button>
      {onUpload && <button type="button" disabled={disabled || busy} onClick={() => fileInput.current?.click()}>Upload</button>}
      {value.url && allowRemove && <button type="button" disabled={disabled} onClick={() => { setError(""); onChange({ url: "", alt: "" }); }}>Remove photo</button>}
    </div>
    {onUpload && <input ref={fileInput} className="eb-photo__file" type="file" aria-label={`Upload ${label.toLowerCase()}`} accept="image/jpeg,image/png,image/webp,image/avif" disabled={disabled || busy} onChange={(event) => void upload(event.currentTarget)} />}
    {open && <div id={`${id}-choices`} className="eb-photo__choices">
      <div className="eb-photo__tabs" role="tablist" aria-label="Photo source" onKeyDown={(event) => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
        event.preventDefault();
        const next = event.key === "Home" ? "library" : event.key === "End" ? "related" : tab === "library" ? "related" : "library";
        setTab(next); document.getElementById(`${id}-${next}`)?.focus();
      }}>
        {([['library', 'Your photos'], ['related', 'Related photos']] as const).map(([key, title]) => <button type="button" role="tab" key={key} id={`${id}-${key}`} aria-selected={tab === key} aria-controls={`${id}-photos`} tabIndex={tab === key ? 0 : -1} onClick={() => setTab(key)}>{title}</button>)}
      </div>
      <label className="eb-photo__search">Find photos<input type="search" value={search} onChange={(event) => setSearch(event.target.value)} /></label>
      <div role="tabpanel" id={`${id}-photos`} aria-labelledby={`${id}-${tab}`}>
        <div className="eb-photo__grid">{visible.map((photo) => <button key={`${photo.id}-${photo.url}`} type="button" disabled={disabled} aria-label={`Use ${photo.name}`} aria-pressed={value.url === photo.url} onClick={() => choose(photo)}><img src={photo.url} alt="" loading="lazy" /><span>{photo.name}</span></button>)}</div>
        {!visible.length && <p className="eb-photo__hint">{search ? "No matching photos." : tab === "library" ? "Photos you upload appear here." : "Upload a photo for this business."}</p>}
      </div>
      <button className="eb-photo__close" type="button" onClick={close}>Close photos</button>
    </div>}
    {showDescription && <label className="eb-photo__description">{descriptionLabel}<input value={value.alt} maxLength={500} disabled={disabled} onChange={(event) => onChange({ ...value, alt: event.target.value })} placeholder="Describe what the photo shows" /></label>}
    <details className="eb-photo__advanced"><summary>Advanced</summary><label>Image URL<input {...urlAttributes} value={value.url} disabled={disabled} onChange={(event) => onChange({ ...value, url: event.target.value })} placeholder="/images/… or https://…" /></label></details>
    <p className="eb-photo__hint">{help || `JPG, PNG, WebP or AVIF · Up to ${Math.round(maxBytes / 1024 / 1024)} MB each`}</p>
    {error && <p className="eb-photo__error" role="alert">{error}</p>}
  </section>;
}

export async function uploadPhoto(url: string, csrf: string, file: File, alt: string): Promise<PhotoAsset> {
  const data = new FormData(); data.append("image", file); data.append("alt_text", alt);
  const response = await fetch(url, { method: "POST", headers: { Accept: "application/json", "X-CSRF-TOKEN": csrf }, body: data });
  const payload = await response.json().catch(() => null) as { media?: PhotoAsset; message?: string; errors?: Record<string, string[]> } | null;
  if (!response.ok) {
    const validation = payload?.errors?.image?.[0];
    throw new Error(validation || (response.status === 423 ? "Editing is paused. Your saved photos are safe." : response.status === 413 ? "This image is too large. Choose a smaller file." : "Could not upload this image. Try again."));
  }
  if (typeof payload?.media?.id !== "number" || typeof payload.media.url !== "string" || typeof payload.media.mime_type !== "string") throw new Error("The upload was not confirmed. Try again.");
  return payload.media;
}
