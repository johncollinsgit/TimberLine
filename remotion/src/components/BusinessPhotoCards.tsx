import { Img, staticFile, useCurrentFrame } from "remotion";
import { brand, type } from "../design/tokens";
import { clamp } from "../motion/primitives";

const photos = [
  { label: "Retail", src: "story-photos/retail-shopkeeper.jpg" },
  { label: "Wholesale", src: "story-photos/wholesale-warehouse.jpg" },
  { label: "Field service", src: "story-photos/service-electrician.jpg" },
] as const;

export const BusinessPhotoCards = ({ compact = false }: { compact?: boolean }) => {
  const frame = useCurrentFrame();

  return <div style={{ display: "flex", gap: compact ? 18 : 24, alignItems: "stretch" }}>
    {photos.map((photo, index) => {
      const progress = clamp(frame - 18 - index * 12, [0, 26], [0, 1]);
      const width = compact ? 255 : 350;
      const height = compact ? 190 : 252;
      return <article key={photo.label} style={{ position: "relative", width, height, overflow: "hidden", flexShrink: 0, border: "7px solid #fffdf7", borderRadius: 22, opacity: progress, transform: `translateY(${(1 - progress) * 26}px) rotate(${(index - 1) * (compact ? 1 : 1.5)}deg)`, boxShadow: "0 20px 45px rgba(13,40,39,.22)", background: brand.deep }}>
        <Img src={staticFile(photo.src)} style={{ width: "100%", height: "100%", objectFit: "cover" }} />
        <div style={{ position: "absolute", right: 0, bottom: 0, left: 0, padding: "38px 16px 14px", color: "#fffdf7", background: "linear-gradient(transparent, rgba(13,40,39,.88))", fontFamily: type.sans, fontSize: compact ? 15 : 17, fontWeight: 800 }}>{photo.label}</div>
      </article>;
    })}
  </div>;
};
