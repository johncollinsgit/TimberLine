import { AbsoluteFill } from "remotion";
import { brand, type } from "../design/tokens";
import { BranchLine, DataFlow, Reveal } from "../motion/primitives";

const labels = [[960, 540, "Everbranch"], [470, 300, "Storefront"], [245, 600, "Customers"], [535, 830, "Orders"], [1380, 285, "Marketing"], [1660, 590, "Lifecycle"], [1370, 835, "Analytics"]];
export const System = () => <AbsoluteFill style={{ overflow: "hidden", color: brand.white, background: brand.deep }}>
  <div style={{ position: "absolute", top: 125, width: "100%", zIndex: 3, textAlign: "center" }}><Reveal at={8}><h2 style={{ margin: 0, fontFamily: type.display, fontSize: 73, fontWeight: 500, letterSpacing: "-.065em" }}>One system. One customer story.</h2></Reveal><Reveal at={30}><p style={{ margin: "15px 0 0", color: "#f3c8aa", fontFamily: type.display, fontSize: 39, letterSpacing: "-.04em" }}>Better decisions.</p></Reveal></div>
  <svg viewBox="0 0 1920 1080" style={{ position: "absolute", inset: 0, width: "100%", height: "100%" }}>{labels.slice(1).map(([x, y], index) => <BranchLine key={String(x)} x1={960} y1={540} x2={Number(x)} y2={Number(y)} delay={65 + index * 10} />)}{labels.slice(1).map(([x, y], index) => <DataFlow key={`d${x}`} x1={960} y1={540} x2={Number(x)} y2={Number(y)} delay={130 + index * 8} />)}</svg>
  {labels.map(([x, y, name], index) => <Reveal key={name} at={50 + index * 10} duration={22} y={10}><div style={{ position: "absolute", top: Number(y) - (index === 0 ? 54 : 32), left: Number(x) - (index === 0 ? 150 : 95), display: "grid", placeItems: "center", width: index === 0 ? 300 : 190, height: index === 0 ? 108 : 64, border: index === 0 ? "8px solid rgba(243,200,170,.92)" : "1px solid rgba(231,221,207,.28)", borderRadius: index === 0 ? 20 : 999, color: index === 0 ? brand.deep : "#e7ddcf", background: index === 0 ? "#f3c8aa" : "rgba(255,255,255,.05)", fontFamily: type.sans, fontSize: index === 0 ? 30 : 18, fontWeight: 800 }}>{name}</div></Reveal>)}
</AbsoluteFill>;
