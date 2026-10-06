import { AbsoluteFill } from "remotion";
import { CustomerTimeline } from "../components/CustomerTimeline";
import { Label, UIWindow } from "../components/UIWindow";
import { brand, type } from "../design/tokens";
import { CameraMove, Reveal } from "../motion/primitives";

export const CustomerStory = () => <AbsoluteFill style={{ color: brand.ink, background: "#eef1eb" }}>
  <div style={{ position: "absolute", top: 89, left: 175 }}><Reveal at={8}><Label>Customer intelligence</Label></Reveal><Reveal at={23}><h2 style={{ margin: "18px 0 0", fontFamily: type.display, fontSize: 70, fontWeight: 500, letterSpacing: "-.06em" }}>See the whole customer journey.</h2></Reveal></div>
  <CameraMove from={.96} to={1} start={0} end={220}><div style={{ position: "absolute", top: 270, left: 350 }}><Reveal at={38} duration={30} y={24}><UIWindow width={1220} title="Customer · Maya Rivera"><div style={{ display: "grid", gridTemplateColumns: "295px 1fr", minHeight: 570 }}><aside style={{ padding: 28, borderRight: "1px solid #dce5dc", background: "#f7f9f5", fontFamily: type.sans }}><div style={{ display: "grid", placeItems: "center", width: 70, height: 70, borderRadius: "50%", color: "#fff", background: brand.moss, fontSize: 23, fontWeight: 800 }}>MR</div><h3 style={{ margin: "19px 0 4px", fontSize: 24 }}>Maya Rivera</h3><p style={{ margin: 0, color: brand.muted, fontSize: 14 }}>Customer since May 2025</p><div style={{ marginTop: 25, padding: 15, borderRadius: 12, background: "#e7f0e9" }}><strong style={{ display: "block", color: brand.ink, fontSize: 13 }}>Lifecycle</strong><span style={{ color: "#486e5d", fontSize: 14 }}>Returning customer</span></div></aside><main style={{ padding: "32px 38px" }}><Label>One person · one story</Label><h3 style={{ margin: "10px 0 0", fontFamily: type.display, fontSize: 39, fontWeight: 500, letterSpacing: "-.04em" }}>Every meaningful moment, in context.</h3><CustomerTimeline /></main></div></UIWindow></Reveal></div></CameraMove>
</AbsoluteFill>;
