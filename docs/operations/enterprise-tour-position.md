# Tour hint placement

Source-only follow-up to Buildings packet
`2d5d8f0d7a251d48cef5bcf52237202357f7c11cdf2ecda76ad96050ffdb89c1`.
The frozen Buildings source remains unchanged.

The prior placement deliberately preferred a full hint below the highlighted
target, then above it, keeping the fixed tour controls clear. When neither slot
fit, its clamped fallback could cover the target. This reproduced at 720×450 CSS
pixels in both Chromium and WebKit, independently of the sidebar change.

The follow-up keeps those preferred positions. When space is limited, it uses
the larger free slot and allows the hint itself to scroll; it never places the
hint across its target. Revealing a step aligns the target below the actual
controls rather than the viewport center. Resizing repeats that reveal without
moving keyboard focus unless the focused hint must hide; focus then moves to the
existing **Hide tour** control and keeps it visible within the bar. Scrolling within a hint leaves its placement and scroll
position alone so its action remains reachable.

For viewports at most 500 CSS pixels high, the tour control bar has its own
30dvh scroll limit. Its buttons remain present, keep their existing minimum
target size, and are reachable by keyboard. If less than 78px remains for any
usable hint slot, the highlight remains but the hint hides; an existing live
status announces that the user can hide the tour or enlarge the window.

No tour copy, target ID, navigation destination, permissions, clock/playback,
provider or record mutation changes. Existing current-workspace checks remain.

Local regression coverage executes the actual placement function and the actual
shared renderer with fictional records. It covers the old 720px obstruction,
available above/below slots, tiny-height fallback, internal hint scrolling,
resize, desktop/laptop, iPad portrait/landscape, mobile and phone landscape.
Checks include fully visible targets, nonoverlapping hints/controls, reachable
44px hint/control actions, focus preservation, unchanged Hide navigation,
working building search, no overflow, JavaScript errors or outbound requests.
The 200% cases are reflow equivalents at 720×450 and 640×400 CSS pixels, not
native browser zoom. No physical-device, production-browser or live acceptance
is established. Normal independent review and protected release gates apply.
