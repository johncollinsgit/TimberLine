import { AbsoluteFill, Img, Sequence, interpolate, staticFile, useCurrentFrame } from "remotion";
import { brand, radius, type } from "./design/tokens";
import { clamp, Reveal } from "./motion/primitives";

export const pestControlFleetFrames = 900;

const MapScene = () => {
  const frame = useCurrentFrame();
  const dotProgress = clamp(frame - 78, [0, 220], [0, 1]);
  const vanX = interpolate(dotProgress, [0, 1], [742, 1328]);
  const vanY = interpolate(dotProgress, [0, 1], [744, 510]);
  const phoneX = interpolate(dotProgress, [0, 1], [720, 1294]);
  const phoneY = interpolate(dotProgress, [0, 1], [778, 548]);

  return <AbsoluteFill style={{ color: brand.ink, background: brand.paper }}>
    <Img src={staticFile("maps/green-shield-fleet-map-osm.png")} style={{ position: "absolute", inset: 0, width: "100%", height: "100%", objectFit: "cover" }} />
    <div style={{ position: "absolute", inset: 0, background: "rgba(247,244,235,.38)" }} />
    <div style={{ position: "absolute", top: 74, left: 82, width: 765, padding: "30px 34px", border: "1px solid rgba(23,62,59,.14)", borderRadius: 22, background: "rgba(255,253,247,.94)", boxShadow: "0 20px 46px rgba(23,62,59,.14)" }}>
      <Reveal at={8}><p style={{ margin: 0, color: brand.clay, fontFamily: type.sans, fontWeight: 850, fontSize: 16, letterSpacing: ".15em", textTransform: "uppercase" }}>Green Shield Pest Control · fictional demo</p></Reveal><Reveal at={27}><h1 style={{ margin: "18px 0 0", fontFamily: type.display, fontSize: 66, fontWeight: 500, lineHeight: .98, letterSpacing: "-.06em" }}>Keep a bird’s-eye view of every vehicle on your team.</h1></Reveal><Reveal at={50}><p style={{ width: 650, margin: "20px 0 0", color: brand.muted, fontFamily: type.sans, fontSize: 23, lineHeight: 1.4 }}>One company van. One scheduled termite-inspection visit. Separate, clearly labeled location sources.</p></Reveal>
    </div>
    <Reveal at={58}><div style={{ position: "absolute", top: 86, right: 86, padding: "16px 20px", border: "1px solid rgba(23,62,59,.14)", borderRadius: radius.card, background: "rgba(255,253,247,.94)", boxShadow: "0 14px 34px rgba(23,62,59,.13)", fontFamily: type.sans, fontSize: 16 }}><strong style={{ display: "block", color: brand.moss }}>Active job</strong><span>Fictional service stop · 10:00 AM</span></div></Reveal>
    <svg viewBox="0 0 1920 1080" style={{ position: "absolute", inset: 0 }}>
      <path d="M 720 778 C 878 728, 986 642, 1080 650 S 1230 620, 1328 510" fill="none" stroke="rgba(255,255,255,.92)" strokeWidth="22" strokeLinecap="round" />
      <path d="M 720 778 C 878 728, 986 642, 1080 650 S 1230 620, 1328 510" fill="none" stroke={brand.moss} strokeWidth="12" strokeLinecap="round" />
      <path d="M 720 778 C 878 728, 986 642, 1080 650 S 1230 620, 1328 510" fill="none" stroke="#c9ded0" strokeWidth="3" strokeDasharray="10 13" strokeLinecap="round" />
      <circle cx={vanX} cy={vanY} r="28" fill="rgba(255,255,255,.93)" /><circle cx={vanX} cy={vanY} r="20" fill={brand.moss} /><path d={`M ${vanX - 7} ${vanY + 5} h14 v-13 h-14 z`} fill="#fff" />
      <circle cx={phoneX} cy={phoneY} r="20" fill="rgba(255,255,255,.93)" /><circle cx={phoneX} cy={phoneY} r="13" fill={brand.clay} /><circle cx={phoneX} cy={phoneY} r="4" fill="#fff" />
      <circle cx="1328" cy="510" r="28" fill="rgba(255,255,255,.93)" /><circle cx="1328" cy="510" r="20" fill={brand.clay} /><circle cx="1328" cy="510" r="7" fill="#fff" />
    </svg>
    <Reveal at={90}><div style={{ position: "absolute", left: 82, bottom: 74, display: "flex", gap: 14, fontFamily: type.sans, fontSize: 17 }}><span style={{ padding: "13px 17px", borderRadius: 999, background: "rgba(231,240,233,.96)", color: "#3f654e", boxShadow: "0 8px 20px rgba(23,62,59,.10)" }}>● Van 17 · Bouncie hardware</span><span style={{ padding: "13px 17px", borderRadius: 999, background: "rgba(248,231,223,.96)", color: "#975338", boxShadow: "0 8px 20px rgba(23,62,59,.10)" }}>● Crew phone · active timer only</span></div></Reveal>
    <p style={{ position: "absolute", right: 30, bottom: 22, margin: 0, padding: "6px 10px", borderRadius: 8, background: "rgba(255,253,247,.86)", color: "#3b4a45", fontFamily: type.sans, fontSize: 13 }}>Map data © OpenStreetMap contributors · fictional route overlay</p>
  </AbsoluteFill>;
};

const TimeAndPolicyScene = () => <AbsoluteFill style={{ color: brand.ink, background: brand.deep }}>
  <div style={{ position: "absolute", top: 135, left: 160, right: 160, textAlign: "center" }}><Reveal at={8}><p style={{ margin: 0, color: "#f3c8aa", fontFamily: type.sans, fontWeight: 850, fontSize: 16, letterSpacing: ".15em", textTransform: "uppercase" }}>An approved boundary</p></Reveal><Reveal at={28}><h2 style={{ margin: "18px 0 0", color: brand.white, fontFamily: type.display, fontSize: 73, fontWeight: 500, letterSpacing: "-.06em" }}>Scheduled work turns tracking on—and off.</h2></Reveal></div>
  <div style={{ position: "absolute", top: 435, left: 220, right: 220, display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 34 }}>
    {[["01", "Assigned shift", "10:00 AM–1:00 PM · termite inspection"], ["02", "Clock in", "Timer starts inside the approved window"], ["03", "Clock out or pause", "Phone sharing stops immediately"]].map(([number, title, detail], index) => <Reveal key={title} at={72 + index * 46} duration={24} y={22}><article style={{ minHeight: 250, padding: 33, border: "1px solid rgba(255,255,255,.14)", borderRadius: radius.card, background: "rgba(255,255,255,.06)", fontFamily: type.sans }}><small style={{ color: "#f3c8aa", fontWeight: 850, letterSpacing: ".14em" }}>{number}</small><h3 style={{ margin: "24px 0 0", color: brand.white, fontFamily: type.display, fontSize: 36, fontWeight: 500, letterSpacing: "-.04em" }}>{title}</h3><p style={{ margin: "17px 0 0", color: "#c6d4cf", fontSize: 18, lineHeight: 1.45 }}>{detail}</p></article></Reveal>)}
  </div>
  <Reveal at={235}><p style={{ position: "absolute", bottom: 126, left: 210, right: 210, margin: 0, color: "#c6d4cf", fontFamily: type.sans, fontSize: 20, textAlign: "center" }}>No personal vehicles. No off-duty tracking. No automatic employment decisions.</p></Reveal>
</AbsoluteFill>;

const ControlsScene = () => <AbsoluteFill style={{ color: brand.ink, background: brand.paper }}>
  <div style={{ position: "absolute", top: 150, left: 160, right: 160 }}><Reveal at={8}><p style={{ margin: 0, color: brand.clay, fontFamily: type.sans, fontSize: 16, fontWeight: 850, letterSpacing: ".15em", textTransform: "uppercase" }}>Built for a responsible rollout</p></Reveal><Reveal at={28}><h2 style={{ margin: "18px 0 0", fontFamily: type.display, fontSize: 72, fontWeight: 500, letterSpacing: "-.06em" }}>The office sees a clear, controlled picture.</h2></Reveal></div>
  <div style={{ position: "absolute", top: 420, left: 165, right: 165, display: "grid", gridTemplateColumns: "1.15fr 1fr", gap: 38 }}>
    <Reveal at={70} duration={28}><div style={{ padding: 34, border: `1px solid ${brand.line}`, borderRadius: radius.card, background: brand.bright, fontFamily: type.sans, boxShadow: "0 18px 44px rgba(23,62,59,.08)" }}><div style={{ display: "flex", justifyContent: "space-between" }}><strong style={{ fontSize: 20 }}>Fleet tracking · Green Shield</strong><span style={{ color: "#3f654e", fontWeight: 800 }}>● Active</span></div><div style={{ marginTop: 28, display: "grid", gap: 14 }}>{[["Van 17", "Bouncie hardware · 2 min ago"], ["Miles Carter", "Phone · active timer · 2 min ago"]].map(([name, detail]) => <div key={name} style={{ display: "flex", justifyContent: "space-between", padding: "17px 18px", borderRadius: 14, background: "#edf3ed" }}><strong>{name}</strong><span style={{ color: brand.muted }}>{detail}</span></div>)}</div></div></Reveal>
    <div style={{ display: "grid", gap: 18 }}>{[["Owner/admin access", "Location records stay out of ordinary employee, customer, and payroll views."], ["30-day retention", "Raw points are automatically pruned on the tenant’s configured schedule."], ["Policy evidence", "Counsel review, policy acknowledgement, and signed provider webhooks are required."]].map(([title, text], index) => <Reveal key={title} at={105 + index * 43} duration={22} y={16}><article style={{ padding: "20px 24px", borderLeft: `5px solid ${index === 1 ? brand.clay : brand.moss}`, background: "#fffdf7", fontFamily: type.sans }}><strong style={{ fontSize: 20 }}>{title}</strong><p style={{ margin: "8px 0 0", color: brand.muted, fontSize: 17, lineHeight: 1.4 }}>{text}</p></article></Reveal>)}</div>
  </div>
</AbsoluteFill>;

const EndCard = () => <AbsoluteFill style={{ color: brand.white, background: brand.deep }}><div style={{ display: "grid", width: "100%", height: "100%", placeItems: "center", textAlign: "center" }}><div><Reveal at={20}><p style={{ margin: 0, color: "#f3c8aa", fontFamily: type.sans, fontWeight: 850, fontSize: 18, letterSpacing: ".15em", textTransform: "uppercase" }}>Green Shield Pest Control · fictional demo</p></Reveal><Reveal at={54}><h1 style={{ margin: "22px 0 0", fontFamily: type.display, fontSize: 82, fontWeight: 500, letterSpacing: "-.065em" }}>The work is moving.<br />The controls stay clear.</h1></Reveal><Reveal at={115}><p style={{ margin: "30px 0 0", color: "#c6d4cf", fontFamily: type.sans, fontSize: 24 }}>See the demonstration workspace in Everbranch.</p></Reveal></div></div></AbsoluteFill>;

export const PestControlFleetDemo = () => <AbsoluteFill><Sequence from={0} durationInFrames={300}><MapScene /></Sequence><Sequence from={300} durationInFrames={270}><TimeAndPolicyScene /></Sequence><Sequence from={570} durationInFrames={225}><ControlsScene /></Sequence><Sequence from={795} durationInFrames={105}><EndCard /></Sequence></AbsoluteFill>;
