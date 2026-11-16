import React, { useEffect, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import { PhotoAsset, PhotoPicker, PhotoValue, uploadPhoto } from "./PhotoPicker";

type Slot = PhotoValue & { id: string };
function parse<T>(text: string | undefined, fallback: T): T { try { return text ? JSON.parse(text) as T : fallback; } catch { return fallback; } }
function catalogPhoto(photo: PhotoAsset): PhotoAsset | null {
  // Uploads are never made public through a page URL when used by a catalog.
  const url = typeof photo.id === "number" ? photo.catalog_url : photo.url;
  // Keep the server's owned upload reference; the browser resolves its preview.
  return url ? { ...photo, url } : null;
}

function ConnectedPhoto({ root }: { root: HTMLElement }) {
  const initial = parse<PhotoValue[]>(root.dataset.value, [])[0] || { url: "", alt: "" };
  const [value, setValue] = useState(initial);
  const origin = root.dataset.connectedOrigin || window.location.origin;
  const preview = (url: string) => url ? new URL(url, origin).href : "";
  const rawPhotos = parse<PhotoAsset[]>(root.dataset.photos, []);
  const rawRelated = parse<PhotoAsset[]>(root.dataset.relatedPhotos, []);
  const photos = rawPhotos.map((photo) => ({ ...photo, url: preview(photo.url) }));
  const related = rawRelated.map((photo) => ({ ...photo, url: preview(photo.url) }));
  return <div className="eb-photo-field">
    <input type="hidden" name={root.dataset.name} value={value.url} />
    <PhotoPicker label={root.dataset.label || "Website image"} value={{ ...value, url: preview(value.url) }} photos={photos} related={related} allowRemove={false} showDescription={false} help="Choose an existing photo. New uploads for this connected design are not available yet." onChange={(next) => {
      if (!next.url) return;
      const stored = [...rawPhotos, ...rawRelated].find((photo) => preview(photo.url) === next.url)?.url || next.url;
      setValue({ url: stored, alt: initial.alt });
    }} />
  </div>;
}

function CatalogPhotos({ root }: { root: HTMLElement }) {
  const multiple = root.dataset.multiple === "true";
  const limit = multiple ? 12 : 1;
  const [slots, setSlots] = useState<Slot[]>(() => {
    const values = parse<PhotoValue[]>(root.dataset.value, []);
    return (values.length ? values : [{ url: "", alt: "" }]).map((value) => ({ ...value, id: crypto.randomUUID() }));
  });
  const slotsRef = useRef(slots);
  const [photos, setPhotos] = useState<PhotoAsset[]>(() => parse<PhotoAsset[]>(root.dataset.photos, []).map(catalogPhoto).filter((photo): photo is PhotoAsset => photo !== null));
  const related = parse<PhotoAsset[]>(root.dataset.relatedPhotos, []).map(catalogPhoto).filter((photo): photo is PhotoAsset => photo !== null);
  const [status, setStatus] = useState("");
  const pending = useRef(new Set<Promise<boolean>>());
  const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || "";
  const commit = (next: Slot[]) => { slotsRef.current = next; setSlots(next); };
  const change = (id: string, value: PhotoValue, expected?: PhotoValue) => {
    commit(slotsRef.current.map((slot) => slot.id !== id || (expected && (slot.url !== expected.url || slot.alt !== expected.alt)) ? slot : { ...slot, ...value }));
  };
  const upload = async (id: string, expected: PhotoValue, file: File, alt: string) => {
    let failure: Error | null = null;
    const task = (async () => {
      try {
        const uploaded = await uploadPhoto(root.dataset.uploadUrl || "", csrf, file, alt);
        const photo = catalogPhoto(uploaded);
        if (!photo) throw new Error("The catalog image was not confirmed. Try again.");
        setPhotos((items) => [photo, ...items.filter((item) => item.id !== photo.id)]);
        change(id, { url: photo.url, alt: photo.alt_text || "" }, expected);
        setStatus("Photo ready. Save to apply it."); return true;
      } catch (error) { failure = error instanceof Error ? error : new Error("Could not upload this image. Try again."); setStatus(failure.message); return false; }
    })();
    pending.current.add(task); setStatus("Uploading photo…");
    const ok = await task; pending.current.delete(task);
    if (!ok) throw failure || new Error("Could not upload this image. Try again.");
  };
  useEffect(() => {
    const form = root.closest("form");
    if (!form) return;
    const submit = (event: SubmitEvent) => {
      if (!pending.current.size) return;
      event.preventDefault();
      const button = event.submitter as HTMLButtonElement | HTMLInputElement | null;
      void (async () => {
        while (pending.current.size) if (!(await Promise.all([...pending.current])).every(Boolean)) return;
        // React must commit the confirmed hidden fields before native serialization.
        await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()));
        form.requestSubmit(button?.form === form ? button : undefined);
      })();
    };
    const warn = (event: BeforeUnloadEvent) => { if (pending.current.size) { event.preventDefault(); event.returnValue = ""; } };
    form.addEventListener("submit", submit); window.addEventListener("beforeunload", warn);
    return () => { form.removeEventListener("submit", submit); window.removeEventListener("beforeunload", warn); };
  }, [root]);
  const chosen = slots.filter((slot) => slot.url);
  return <div className="eb-photo-field">
    {chosen.map((slot) => <input key={slot.id} type="hidden" name={root.dataset.name || "image_url"} value={slot.url} />)}
    {!multiple && !chosen.length && <input type="hidden" name={root.dataset.name || "image_url"} value="" />}
    <input type="hidden" name={root.dataset.altName || "image_alt"} value={chosen[0]?.alt || ""} />
    <div className="eb-photo-field__list">{slots.map((slot, index) => <div className="eb-photo-field__row" key={slot.id}>
      {multiple && <header><strong>{index === 0 ? "Cover photo" : `Photo ${index + 1}`}</strong><div>{index > 0 && slot.url && <button type="button" onClick={() => commit([slot, ...slotsRef.current.filter((item) => item.id !== slot.id)])}>Make cover</button>}{slots.length > 1 && <button type="button" aria-label={`Delete photo ${index + 1}`} onClick={() => commit(slotsRef.current.filter((item) => item.id !== slot.id))}>Delete</button>}</div></header>}
      <PhotoPicker label={multiple ? index === 0 ? "Cover image" : `Image ${index + 1}` : root.dataset.label || "Image"} value={slot} photos={photos} related={related} maxBytes={Number(root.dataset.maxBytes) || 10 * 1024 * 1024} showDescription={!multiple || index === 0} descriptionLabel={multiple ? "Cover image description" : "Image description"} help={multiple && index > 0 ? "Edit the description on your cover photo." : undefined} onChange={(value) => change(slot.id, value)} onUpload={(file, alt) => upload(slot.id, slot, file, alt)} />
    </div>)}</div>
    {multiple && <button type="button" className="eb-photo-field__add" disabled={slots.length >= limit} onClick={() => commit([...slotsRef.current, { id: crypto.randomUUID(), url: "", alt: "" }])}>Add photo</button>}
    {status && <p className="eb-photo-field__status" role="status">{status}</p>}
  </div>;
}

export function mountWebsitePhotoFieldsNow() {
  document.querySelectorAll<HTMLElement>("[data-website-photo-field]").forEach((root) => {
    if (root.dataset.mounted) return;
    root.dataset.mounted = "true"; createRoot(root).render(root.dataset.connectedOrigin ? <ConnectedPhoto root={root} /> : <CatalogPhotos root={root} />);
  });
}
mountWebsitePhotoFieldsNow();
