import { Composition } from "remotion";
import { EverbranchExplainer, EverbranchRickrollIntro, frames } from "./EverbranchExplainer";

const total = Object.values(frames).reduce((sum, value) => sum + value, 0);
export const RemotionRoot = () => <><Composition id="EverbranchStory" component={EverbranchExplainer} durationInFrames={total} fps={30} width={1920} height={1080} /><Composition id="EverbranchStoryRickrollIntro" component={EverbranchRickrollIntro} durationInFrames={300} fps={30} width={1920} height={1080} /></>;
