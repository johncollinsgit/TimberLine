# Marketing SMS links

SMS and MMS execution retain the existing tenant, profile, delivery and signed
click attribution. The visible message uses the configured public short-link
base and a seven-character code, without a long tracking query string.

The normal link shortener stores the signed tracking URL behind this compact
link. A click follows two normal public redirects: the first resolves the saved
signed URL, and the second verifies its original context, records the click and
redirects to the event or shop. No schema change, new provider or tracking-key
rotation is required. Previously sent signed URLs continue to work.

Label campaign links with their purpose, such as `Event details:`, before the
URL. Keep the actual official destination in the campaign's source evidence.
Owner previews and validation probes are not customer purchase or attendance
evidence. A click is attributed to the original delivery's tenant and customer;
query parameters appended to the compact URL cannot replace that saved context.
