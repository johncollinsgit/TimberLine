import type { ReactNode } from "react";
import { AbsoluteFill, interpolate, useCurrentFrame } from "remotion";
import { easeOut } from "./easing";

export const clamp = (value: number, input: readonly number[], output: readonly number[]) =>
  interpolate(value, input, output, { extrapolateLeft: "clamp", extrapolateRight: "clamp", easing: easeOut });

export const Reveal = ({ children, at = 0, duration = 18, y = 24 }: { children: ReactNode; at?: number; duration?: number; y?: number }) => {
  const frame = useCurrentFrame();
  const progress = clamp(frame - at, [0, duration], [0, 1]);
  return <div style={{ opacity: progress, transform: `translateY(${(1 - progress) * y}px)` }}>{children}</div>;
};

export const SceneTransition = ({ children, duration = 18 }: { children: ReactNode; duration?: number }) => {
  const frame = useCurrentFrame();
  const opacity = clamp(frame, [0, duration, 1e9 - duration, 1e9], [0, 1, 1, 1]);
  return <AbsoluteFill style={{ opacity }}>{children}</AbsoluteFill>;
};

export const CameraMove = ({ children, from = 1, to = 1.035, start = 0, end = 240 }: { children: ReactNode; from?: number; to?: number; start?: number; end?: number }) => {
  const frame = useCurrentFrame();
  const scale = clamp(frame, [start, end], [from, to]);
  return <div style={{ width: "100%", height: "100%", transform: `scale(${scale})` }}>{children}</div>;
};

export const BranchLine = ({ x1, y1, x2, y2, delay = 0, color = "rgba(231, 221, 207, .48)", width = 2 }: { x1: number; y1: number; x2: number; y2: number; delay?: number; color?: string; width?: number }) => {
  const frame = useCurrentFrame();
  const progress = clamp(frame - delay, [0, 30], [0, 1]);
  return <line x1={x1} y1={y1} x2={x1 + (x2 - x1) * progress} y2={y1 + (y2 - y1) * progress} stroke={color} strokeWidth={width} strokeLinecap="round" />;
};

export const DataFlow = ({ x1, y1, x2, y2, delay = 0 }: { x1: number; y1: number; x2: number; y2: number; delay?: number }) => {
  const frame = useCurrentFrame();
  const pathProgress = ((frame - delay) % 70 + 70) % 70 / 70;
  const visible = frame >= delay;
  return visible ? <circle cx={x1 + (x2 - x1) * pathProgress} cy={y1 + (y2 - y1) * pathProgress} r="5" fill="#f3c8aa" opacity=".9" /> : null;
};
