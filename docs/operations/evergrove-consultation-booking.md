# Evergrove consultation intake

`/book` on configured Evergrove hosts presents the business-owner value proposition,
a responsive three-step business brief, and a separate short contact form for the
visitor's main business hurdle. Both forms save to existing `service_inquiries`,
visible to authorized operators in Landlord Messages. No schema changes are needed.

Quick Note and its short contact form appear first, then the live schedule.
The full booking explanation and business brief appear below the calendar.
Supporting graphics and agenda follow.
The shared public header uses a native hamburger dropdown below 980 px, with
the logo left and menu right. Its links are What We Build, Everbranch, Book,
and Start a Project. Contact, Our Work, Use Cases, and Login are removed from
the top navigation. The dropdown works without JavaScript; enhancement closes
it on link selection, outside clicks, Escape, or switching to desktop layout.

The brief stores the business type, selected goals and desired outcome in its
planning payload and readable context. Both forms validate on the server, preserve
validation answers, require session-bound tokens and CSRF, and throttle submissions.
Brief replay in the same session returns to scheduling without a second record;
the short form rotates its token after saving. The full form works without JavaScript.

`GOOGLE_BOOKING_URL` may override the owner's verified one-hour Google Meet appointment
schedule supplied as `https://calendar.app.google/V7dW3trnVqMTUDpYA`; its resolved
Google schedule is the default when the environment value is missing or empty.
Only HTTPS Google Calendar hosts are accepted. Visitors see the embedded live
calendar directly on the page, plus a new-tab fallback. No answers are
added to Google's URL. Visitors use the same email to associate their appointment.
Only Google's completed booking confirms a reservation. With no configured URL,
the forms remain useful and explicitly say that arranging a time requires follow-up.
On October 13, 2026, the public schedule showed `60 min appointments`, Google Meet
details added after booking, and actual selectable dates/times. Its old display
title still said 30 minutes; the authoritative appointment duration was 60 minutes.
Availability and calendar conflict checks remain controlled by Google's schedule
settings. The embed never reconstructs available slots or reveals private events.

CSS/SVG animations honor reduced motion. Form controls are touch-sized, use readable
mobile font sizes, expose labels, and progressively enhance into three steps.

Validation: focused `EvergroveBookingPageTest`, the frontend production build, and
the normal GitHub test/build and Forge readiness release gates. Never directly
replace live assets or bypass the release gate for this page.
