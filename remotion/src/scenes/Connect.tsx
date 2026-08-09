import { AbsoluteFill } from "remotion";
import { brand, type } from "../design/tokens";
import { BranchLine, DataFlow, Reveal } from "../motion/primitives";

const nodes = [[240, 330, "Store"], [315, 750, "Website"], [750, 165, "Campaign"], [1495, 260, "Email"], [1700, 700, "Customer"], [1070, 835, "Order"]];
export const Connect = () => <AbsoluteFill style={{ overflow: "hidden", color: brand.ink, background: brand.paper }}>
  <div style={{ position: "absolute", top: 112, width: "100%", textAlign: "center" }}><Reveal at={15}><div style={{ color: brand.clay, fontFamily: type.sans, fontSize: 16, fontWeight: 850, letterSpacing: ".15em", textTransform: "uppercase" }}>Everbranch connects the moments</div></Reveal><Reveal at={38}><h2 style={{ margin: "18px 0 0", fontFamily: type.display, fontSize: 69, fontWeight: 500, letterSpacing: "-.055em" }}>One connected view of your customer.</h2></Reveal></div>
  <svg viewBox="0 0 1920 1080" style={{ position: "absolute", inset: 0, width: "100%", height: "100%" }}>
    {nodes.map(([x, y], index) => <BranchLine key={String(index)} x1={Number(x)} y1={Number(y)} x2={960} y2={555} delay={82 + index * 10} color="rgba(94,116,93,.42)" />)}
    {nodes.map(([x, y], index) => <DataFlow key={`f${index}`} x1={Number(x)} y1={Number(y)} x2={960} y2={555} delay={112 + index * 9} />)}
  </svg>
  {nodes.map(([left, top, label], index) => <Reveal key={String(label)} at={65 + index * 8} duration={18}><div style={{ position: "absolute", left: Number(left) - 72, top: Number(top) - 25, padding: "13px 18px", border: "1px solid rgba(23,62,59,.15)", borderRadius: 999, background: brand.bright, boxShadow: "0 10px 32px rgba(23,62,59,.08)", fontFamily: type.sans, fontSize: 18, fontWeight: 750 }}>{label}</div></Reveal>)}
  <Reveal at={148} duration={28} y={10}><div style={{ position: "absolute", top: 470, left: 835, display: "grid", placeItems: "center", width: 250, height: 170, border: "10px solid #fffdf7", borderRadius: 26, color: "#fff", background: brand.ink, boxShadow: "0 28px 70px rgba(23,62,59,.25)", fontFamily: type.sans, fontSize: 29, fontWeight: 800 }}>everbranch</div></Reveal>
</AbsoluteFill>;
