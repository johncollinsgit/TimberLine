import { AbsoluteFill, Sequence } from "remotion";
import { Action } from "./scenes/Action";
import { Connect } from "./scenes/Connect";
import { CustomerStory } from "./scenes/CustomerStory";
import { EndCard } from "./scenes/EndCard";
import { Intelligence } from "./scenes/Intelligence";
import { Problem } from "./scenes/Problem";
import { System } from "./scenes/System";

// 30fps. Scene lengths intentionally live here so the narration and pacing stay easy to revise.
export const frames = { problem: 240, connect: 420, customer: 600, action: 540, intelligence: 510, system: 270, end: 120 } as const;
const offsets = [0, frames.problem, frames.problem + frames.connect, frames.problem + frames.connect + frames.customer, frames.problem + frames.connect + frames.customer + frames.action, frames.problem + frames.connect + frames.customer + frames.action + frames.intelligence, frames.problem + frames.connect + frames.customer + frames.action + frames.intelligence + frames.system];

export const EverbranchExplainer = () => <AbsoluteFill><Sequence from={offsets[0]} durationInFrames={frames.problem}><Problem /></Sequence><Sequence from={offsets[1]} durationInFrames={frames.connect}><Connect /></Sequence><Sequence from={offsets[2]} durationInFrames={frames.customer}><CustomerStory /></Sequence><Sequence from={offsets[3]} durationInFrames={frames.action}><Action /></Sequence><Sequence from={offsets[4]} durationInFrames={frames.intelligence}><Intelligence /></Sequence><Sequence from={offsets[5]} durationInFrames={frames.system}><System /></Sequence><Sequence from={offsets[6]} durationInFrames={frames.end}><EndCard /></Sequence></AbsoluteFill>;

export const EverbranchRickrollIntro = () => <AbsoluteFill><Sequence durationInFrames={240}><Problem founderOnly /></Sequence><Sequence from={240} durationInFrames={60}><Connect /></Sequence></AbsoluteFill>;
