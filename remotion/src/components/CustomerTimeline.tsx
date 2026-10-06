import { useCurrentFrame } from "remotion";
import { brand, radius, type } from "../design/tokens";
import { clamp } from "../motion/primitives";

const events = [
  ["Meta campaign", "Alderspring collection", "9:14 AM"],
  ["Product view", "Moss + cedar candle", "9:16 AM"],
  ["Email signup", "Field notes welcome", "9:19 AM"],
  ["Order #1847", "$84.00 · First purchase", "9:31 AM"],
  ["Repeat purchase", "Care set + refill", "42 days later"],
];

export const CustomerTimeline = () => {
  const frame = useCurrentFrame();
  return <div style={{ position: "relative", marginTop: 27, paddingLeft: 28 }}>
    <div style={{ position: "absolute", top: 14, bottom: 16, left: 8, width: 2, background: "#dce5dc" }} />
    {events.map(([label, detail, time], index) => {
      const p = clamp(frame - 20 - index * 19, [0, 16], [0, 1]);
      return <div key={label} style={{ position: "relative", display: "grid", gridTemplateColumns: "180px 1fr auto", gap: 18, alignItems: "center", minHeight: 62, marginBottom: 10, padding: "13px 17px", opacity: p, transform: `translateX(${(1 - p) * 22}px)`, border: "1px solid #dce5dc", borderRadius: radius.small, background: "#fff" }}>
        <span style={{ position: "absolute", left: -25, width: 14, height: 14, border: "4px solid #f6f3ec", borderRadius: "50%", background: index === 3 ? brand.clay : brand.moss }} />
        <strong style={{ fontFamily: type.sans, color: brand.ink, fontSize: 15 }}>{label}</strong>
        <span style={{ fontFamily: type.sans, color: brand.muted, fontSize: 14 }}>{detail}</span>
        <small style={{ fontFamily: type.sans, color: "#7b8983", fontSize: 12 }}>{time}</small>
      </div>;
    })}
  </div>;
};
