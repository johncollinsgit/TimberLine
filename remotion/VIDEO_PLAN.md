# Everbranch story film

## Purpose

Explain how Everbranch connects customer context, real work, approved follow-up actions, and performance learning in a calm, premium 90-second product story.

## Brand source

- Public-site tokens from `resources/css/public-studio.css`: Ink `#173e3b`, deep ink `#0d2827`, paper `#f6f3ec`, clay `#c96d4b`, moss `#5e745d`.
- The public product studio’s editorial Fraunces display treatment and Inter operational UI treatment.
- The existing workspace grammar: left navigation, compact cards, gentle green status treatments, calm white surfaces, and small rounded corners.
- The composition draws controlled demo UI rather than rendering live data, preserving public safety and repeatable output.

## Timeline and visuals

| Time | Scene | Visual |
| --- | --- | --- |
| 0:00–0:08 | Problem | Founder premise and the simple point of the work: caring for people. Retail, wholesale, and field-service imagery establishes the audiences Everbranch serves. |
| 0:08–0:22 | Connect | Common business systems—including commerce, bookkeeping, email, calendar, phone, and text—connect into Everbranch without obscuring the central message. |
| 0:22–0:42 | Customer story | A public-safe customer profile resolves event history into one timeline. |
| 0:42–1:00 | Action | Signal → audience → approved retention campaign. |
| 1:00–1:17 | Intelligence | Relationship-aware reporting connects performance to a next step. |
| 1:17–1:26 | System | The complete operating system resolves around Everbranch. |
| 1:26–1:30 | End card | Everbranch and the final CTA. |

## Product claims

- Everbranch brings customer context, activity, work, marketing preparation, and performance learning into one operating system.
- It helps teams see an understandable customer story and turn context into a next step.
- It prepares drafts and recommendations for review; a person remains in control of outbound communication.
- Demo amounts, names, and events are fictional and illustrative.

## Implementation

- Remotion 4, 1920×1080, 30fps, 2,700 frames (90 seconds).
- Design tokens, easing, and motion primitives are centralized in `src/design` and `src/motion`.
- Scene timing lives in `src/EverbranchExplainer.tsx`; each scene is independently editable.
- Optional music, UI sounds, and replaceable narration may be added under `audio/` later. The initial render has no embedded narration or music.
- A separate 10-second `EverbranchStoryRickrollIntro` composition is used only by the unlisted surprise route; it redirects to the official YouTube upload rather than copying third-party video.
