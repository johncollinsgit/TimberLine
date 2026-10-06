import { AbsoluteFill, interpolate, useCurrentFrame } from "remotion";
import { BusinessPhotoCards } from "../components/BusinessPhotoCards";
import { brand, type } from "../design/tokens";
import { CameraMove, Reveal } from "../motion/primitives";

const privateSources = ["Shopify", "Customers", "Orders", "Campaigns", "Email", "Reviews", "Attribution", "Retention"];
const privatePositions = [[170, 190], [510, 560], [990, 185], [1495, 350], [1740, 715], [1260, 840], [625, 830], [230, 580]];

const FounderIntro = () => {
  const frame = useCurrentFrame();

  return <AbsoluteFill style={{ overflow: "hidden", color: brand.white, background: brand.deep }}>
    <CameraMove to={1.025} end={240}>
      <div style={{ position: "absolute", inset: 0, opacity: .38, backgroundImage: "linear-gradient(rgba(231,221,207,.055) 1px, transparent 1px), linear-gradient(90deg, rgba(231,221,207,.055) 1px, transparent 1px)", backgroundSize: "74px 74px" }} />
      {privateSources.map((source, index) => {
        const [left, top] = privatePositions[index];
        const progress = interpolate(frame - 40 - index * 5, [0, 22], [0, 1], { extrapolateLeft: "clamp", extrapolateRight: "clamp" });
        return <div key={source} style={{ position: "absolute", left, top, opacity: .58 * progress, transform: `translateY(${(1 - progress) * 12}px)`, padding: "11px 15px", border: "1px solid rgba(231,221,207,.22)", borderRadius: 999, color: "#e7ddcf", fontFamily: type.sans, fontSize: 19, letterSpacing: ".01em" }}>{source}</div>;
      })}
      <div style={{ position: "absolute", top: 156, width: "100%", textAlign: "center" }}>
        <Reveal at={0} duration={20} y={12}><div style={{ color: "#f3c8aa", fontFamily: type.sans, fontSize: 18, fontWeight: 800, letterSpacing: ".16em", textTransform: "uppercase" }}>After 11 years in small business</div></Reveal>
        <Reveal at={16} duration={25} y={12}><div style={{ marginTop: 14, color: "rgba(255,253,247,.74)", fontFamily: type.sans, fontSize: 24 }}>our founder wanted to build a calmer way to run the work.</div></Reveal>
      </div>
      <div style={{ position: "absolute", right: 160, bottom: 130, left: 160, textAlign: "center" }}>
        <Reveal at={65} duration={26} y={20}><h1 style={{ margin: 0, fontFamily: type.display, fontSize: 82, fontWeight: 500, letterSpacing: "-.055em" }}>Your commerce runs everywhere.</h1></Reveal>
        <Reveal at={108} duration={22} y={12}><p style={{ margin: "20px 0 0", color: "#f3c8aa", fontFamily: type.display, fontSize: 49, letterSpacing: "-.045em" }}>Your customer story shouldn’t.</p></Reveal>
        <Reveal at={160} duration={24} y={10}><div style={{ marginTop: 55, display: "inline-flex", gap: 13, alignItems: "center", color: brand.white, fontFamily: type.sans, fontSize: 28, fontWeight: 800, letterSpacing: "-.04em" }}><span style={{ display: "grid", placeItems: "center", width: 43, height: 43, borderRadius: 12, background: "#f3c8aa", color: brand.deep, fontSize: 30 }}>e</span>everbranch</div></Reveal>
      </div>
    </CameraMove>
  </AbsoluteFill>;
};

export const Problem = ({ founderOnly = false }: { founderOnly?: boolean }) => {
  if (founderOnly) return <FounderIntro />;

  return <AbsoluteFill style={{ overflow: "hidden", color: brand.white, background: brand.deep }}>
  <CameraMove to={1.025} end={240}>
    <div style={{ position: "absolute", inset: 0, opacity: .32, backgroundImage: "linear-gradient(rgba(231,221,207,.055) 1px, transparent 1px), linear-gradient(90deg, rgba(231,221,207,.055) 1px, transparent 1px)", backgroundSize: "74px 74px" }} />
    <div style={{ position: "absolute", top: 152, left: 170, width: 790 }}>
      <Reveal at={0} duration={20} y={12}><div style={{ color: "#f3c8aa", fontFamily: type.sans, fontSize: 18, fontWeight: 800, letterSpacing: ".16em", textTransform: "uppercase" }}>After 11 years in small business</div></Reveal>
      <Reveal at={22} duration={26} y={18}><h1 style={{ margin: "25px 0 0", fontFamily: type.display, fontSize: 82, fontWeight: 500, lineHeight: .96, letterSpacing: "-.06em" }}>Being in business is about caring for people.</h1></Reveal>
      <Reveal at={60} duration={24} y={14}><p style={{ maxWidth: 670, margin: "31px 0 0", color: "rgba(255,253,247,.8)", fontFamily: type.sans, fontSize: 27, lineHeight: 1.43 }}>Everbranch helps you do that to the best of your ability — without the headache.</p></Reveal>
      <Reveal at={112} duration={20} y={10}><div style={{ marginTop: 39, color: "#f3c8aa", fontFamily: type.sans, fontSize: 19, fontWeight: 800, letterSpacing: ".12em", textTransform: "uppercase" }}>Retail · wholesale · field service</div></Reveal>
    </div>
    <div style={{ position: "absolute", top: 247, left: 1000 }}><BusinessPhotoCards compact /></div>
    <Reveal at={144} duration={22} y={10}><div style={{ position: "absolute", bottom: 113, left: 170, display: "inline-flex", gap: 13, alignItems: "center", color: brand.white, fontFamily: type.sans, fontSize: 28, fontWeight: 800, letterSpacing: "-.04em" }}><span style={{ display: "grid", placeItems: "center", width: 43, height: 43, borderRadius: 12, background: "#f3c8aa", color: brand.deep, fontSize: 30 }}>e</span>everbranch</div></Reveal>
  </CameraMove>
  </AbsoluteFill>;
};
