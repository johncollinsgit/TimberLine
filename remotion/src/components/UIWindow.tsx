import type { ReactNode } from "react";
import { Img, staticFile } from "remotion";
import { brand, radius, type } from "../design/tokens";

export const UIWindow = ({ children, title = "Everbranch workspace", width = 1210 }: { children: ReactNode; title?: string; width?: number }) => (
  <div style={{ width, overflow: "hidden", border: `1px solid ${brand.line}`, borderRadius: radius.card, background: "#fff", boxShadow: "0 38px 100px rgba(8, 34, 32, .18)" }}>
    <div style={{ height: 62, display: "flex", alignItems: "center", gap: 12, padding: "0 24px", borderBottom: `1px solid ${brand.line}`, fontFamily: type.sans, color: brand.ink }}>
      <div style={{ width: 27, height: 27, display: "grid", placeItems: "center", overflow: "hidden", borderRadius: 8, background: brand.ink }}><Img src={staticFile("brand/everbranch-mark.png")} style={{ width: "100%", height: "100%", objectFit: "cover" }} /></div>
      <span style={{ fontSize: 18, fontWeight: 700 }}>{title}</span>
      <span style={{ marginLeft: "auto", padding: "7px 11px", borderRadius: radius.pill, color: "#486e5d", background: brand.greenSoft, fontSize: 12, fontWeight: 800 }}>Live context</span>
    </div>
    {children}
  </div>
);

export const Label = ({ children, color = brand.clay }: { children: ReactNode; color?: string }) => <div style={{ color, fontFamily: type.sans, fontSize: 13, fontWeight: 850, letterSpacing: ".14em", textTransform: "uppercase" }}>{children}</div>;
