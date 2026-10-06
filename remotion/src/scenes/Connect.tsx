import { AbsoluteFill } from "remotion";
import { BusinessPhotoCards } from "../components/BusinessPhotoCards";
import { brand, type } from "../design/tokens";
import { BranchLine, DataFlow, Reveal } from "../motion/primitives";

const nodes = [
  [205, 390, "Shopify", "S", "#95bf47"], [485, 610, "QuickBooks", "qb", "#2ca01c"], [710, 365, "Gmail", "M", "#ea4335"],
  [1210, 365, "Calendar", "31", "#4285f4"], [1430, 600, "Phone", "☎", "#c96d4b"], [1630, 390, "Text", "···", "#5e745d"],
] as const;

export const Connect = () => <AbsoluteFill style={{ overflow: "hidden", color: brand.ink, background: brand.paper }}>
  <div style={{ position: "absolute", top: 78, width: "100%", textAlign: "center" }}><Reveal at={15}><div style={{ color: brand.clay, fontFamily: type.sans, fontSize: 16, fontWeight: 850, letterSpacing: ".15em", textTransform: "uppercase" }}>Your business runs on a lot of systems</div></Reveal><Reveal at={38}><h2 style={{ maxWidth: 1180, margin: "18px auto 0", fontFamily: type.display, fontSize: 68, fontWeight: 500, lineHeight: .98, letterSpacing: "-.055em" }}>One connected view of every customer.</h2></Reveal></div>
  <svg viewBox="0 0 1920 1080" style={{ position: "absolute", inset: 0, width: "100%", height: "100%" }}>
    {nodes.map(([x, y], index) => <BranchLine key={String(index)} x1={x} y1={y} x2={960} y2={505} delay={75 + index * 10} color="rgba(94,116,93,.42)" />)}
    {nodes.map(([x, y], index) => <DataFlow key={`f${index}`} x1={x} y1={y} x2={960} y2={505} delay={106 + index * 9} />)}
  </svg>
  {nodes.map(([left, top, label, mark, color], index) => <Reveal key={label} at={62 + index * 8} duration={18}><div style={{ position: "absolute", left: left - 87, top: top - 30, display: "flex", gap: 12, alignItems: "center", width: 174, height: 60, padding: "0 14px", border: "1px solid rgba(23,62,59,.13)", borderRadius: 16, background: brand.bright, boxShadow: "0 10px 32px rgba(23,62,59,.08)", fontFamily: type.sans, fontSize: 15, fontWeight: 800 }}><span style={{ display: "grid", placeItems: "center", width: 32, height: 32, borderRadius: 10, color: "#fff", background: color, fontSize: mark === "qb" ? 12 : 15, fontWeight: 900 }}>{mark}</span>{label}</div></Reveal>)}
  <Reveal at={140} duration={28} y={10}><div style={{ position: "absolute", top: 420, left: 795, display: "grid", placeItems: "center", width: 330, height: 174, border: "9px solid #fffdf7", borderRadius: 28, color: "#fff", background: brand.ink, boxShadow: "0 28px 70px rgba(23,62,59,.25)", fontFamily: type.sans, fontSize: 32, fontWeight: 800 }}>everbranch</div></Reveal>
  <Reveal at={178} duration={22} y={12}><div style={{ position: "absolute", top: 625, width: "100%", textAlign: "center", color: brand.muted, fontFamily: type.sans, fontSize: 20 }}>So the people you serve never get lost between the tools you use.</div></Reveal>
  <div style={{ position: "absolute", top: 725, left: 385 }}><BusinessPhotoCards /></div>
</AbsoluteFill>;
