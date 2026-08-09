import { AbsoluteFill, Img, staticFile } from "remotion";
import { brand, type } from "../design/tokens";
import { Reveal } from "../motion/primitives";

export const EndCard = () => <AbsoluteFill style={{ color: brand.white, background: brand.deep }}>
  <div style={{ position: "absolute", inset: 0, opacity: .24, background: "radial-gradient(circle at 50% 50%, #5e745d 0, transparent 34%)" }} />
  <div style={{ position: "relative", height: "100%", display: "grid", placeItems: "center", textAlign: "center" }}><div><Reveal at={15} duration={25} y={14}><div style={{ display: "inline-flex", gap: 16, alignItems: "center", fontFamily: type.sans, fontSize: 48, fontWeight: 800, letterSpacing: "-.05em" }}><span style={{ display: "grid", placeItems: "center", width: 66, height: 66, overflow: "hidden", borderRadius: 18, background: "#f3c8aa" }}><Img src={staticFile("brand/everbranch-mark.png")} style={{ width: "100%", height: "100%", objectFit: "cover" }} /></span>everbranch</div></Reveal><Reveal at={48} duration={22} y={12}><h1 style={{ margin: "39px 0 0", fontFamily: type.display, fontSize: 78, fontWeight: 500, letterSpacing: "-.065em" }}>Grow with the whole story.</h1></Reveal><Reveal at={83} duration={18} y={10}><div style={{ marginTop: 31, color: "#f3c8aa", fontFamily: type.sans, fontSize: 22, fontWeight: 750 }}>See what Everbranch can do.</div></Reveal></div></div>
</AbsoluteFill>;
