# Teams provider read transport

Operations provider GET requests reuse only Guzzle's underlying transport within
one Laravel request or queue job. The container's scoped binding is discarded
between requests/jobs. Each call builds a new Laravel PendingRequest and retains
its fresh token, source generation, allowed HTTPS host, timeout, retry cooldown
and response checks. Calling source services still requires their existing actor,
area, tenant and financial admission. No response or authorization cache is added.
Cookies are disabled for shared reads. OAuth and provider writes keep their
existing separate request path and serialization.

Attach the low-level callable through `PendingRequest::setHandler`. Laravel then
builds its normal per-call fake, recorder and middleware wrappers. Passing a
Guzzle `handler` request option can bypass those wrappers and is not the supported
application path. Guzzle retains its stream fallback when cURL is unavailable.

The read-only production prototype completed one eleven-page Asana inventory in
1.58 seconds (1.75 seconds including access checks), with one observed connection
and ten reused transfers. This is one diagnostic sample, not a p95 result or a
full reporting acceptance measurement. Run the normal protected release flow and
measure the deployed reporting views before claiming their responsiveness target.

Regression coverage includes actual loopback connection reuse, independent
credentials and cookie isolation, scoped lifecycle reset, Laravel fake interception,
credential rotation, source revocation, rate limits and existing actor/tenant gates.
