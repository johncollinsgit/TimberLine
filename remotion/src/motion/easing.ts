import { Easing } from "remotion";

export const easeOut = Easing.bezier(0.22, 0.72, 0.22, 1);
export const easeInOut = Easing.bezier(0.45, 0, 0.2, 1);
export const timing = { enter: 18, settle: 28, linger: 36 } as const;
