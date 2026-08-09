import { AbsoluteFill } from "remotion";
import { brand, radius, type } from "../design/tokens";
import { Reveal } from "../motion/primitives";

const cards = [["Signal", "Bought 45 days ago", "No second purchase"], ["Audience", "At-risk first-time buyers", "42 customers with a clear next step"], ["Action", "Retention campaign", "Draft ready for review"]];
export const Action = () => <AbsoluteFill style={{ color: brand.ink, background: brand.paper }}>
  <div style={{ position: "absolute", top: 130, left: 170, right: 170, textAlign: "center" }}><Reveal at={12}><div style={{ color: brand.clay, fontFamily: type.sans, fontSize: 16, fontWeight: 850, letterSpacing: ".15em", textTransform: "uppercase" }}>From data to action</div></Reveal><Reveal at={31}><h2 style={{ margin: "17px 0 0", fontFamily: type.display, fontSize: 74, fontWeight: 500, letterSpacing: "-.06em" }}>Turn signals into action.</h2></Reveal></div>
  <div style={{ position: "absolute", top: 402, left: 150, right: 150, display: "grid", gridTemplateColumns: "1fr 105px 1fr 105px 1fr", alignItems: "center" }}>{cards.map(([eyebrow, headline, detail], index) => <>
    <Reveal key={headline} at={75 + index * 38} duration={26} y={22}><article style={{ minHeight: 260, padding: 34, border: `1px solid ${brand.line}`, borderRadius: radius.card, background: brand.bright, boxShadow: "0 17px 44px rgba(23,62,59,.07)" }}><small style={{ color: brand.clay, fontFamily: type.sans, fontSize: 13, fontWeight: 850, letterSpacing: ".14em", textTransform: "uppercase" }}>{eyebrow}</small><h3 style={{ margin: "23px 0 0", fontFamily: type.display, fontSize: 39, fontWeight: 500, letterSpacing: "-.045em", lineHeight: 1.05 }}>{headline}</h3><p style={{ margin: "18px 0 0", color: brand.muted, fontFamily: type.sans, fontSize: 16, lineHeight: 1.5 }}>{detail}</p></article></Reveal>
    {index < 2 ? <Reveal key={`arrow-${index}`} at={101 + index * 38} duration={16}><div style={{ color: brand.moss, textAlign: "center", fontFamily: type.sans, fontSize: 37 }}>→</div></Reveal> : null}
  </>)}</div>
  <Reveal at={195} duration={24}><div style={{ position: "absolute", right: 150, bottom: 123, left: 150, padding: "17px 22px", borderRadius: 14, color: "#486e5d", background: brand.greenSoft, fontFamily: type.sans, fontSize: 18, textAlign: "center" }}>Everbranch prepares context and drafts. Your team stays in control of every message.</div></Reveal>
</AbsoluteFill>;
