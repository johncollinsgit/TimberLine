# Teams event evidence for Ask Everbranch (staged, not enabled)

`AiTeamEventEvidence` is an unconnected server-side prototype. It has no route,
UI or model-provider caller and must not be treated as an event-aware assistant.
It reads one tenant-scoped event after AI and Teams authorization, verifies the
signed-in user's live Asana source, and returns a bounded UTF-8 untrusted note
excerpt with a source/version binding. Calls fail inside database transactions;
revalidation repeats the authorized source read and compares the binding. It
never writes Asana or calls an AI provider.

This prototype does **not** make the reservation lifecycle, conversation history,
replay, citations or event switching safe. Before connecting it to Ask:

- Stage and revalidate all current and historical event proofs outside the AI
  account/conversation locks. Admit exact local version and identity under the
  reservation lock without making network calls; define cancellation, changed
  access during provider dispatch, settlement, recovery and duplicate behavior.
- Reauthorize every event source before sending a question, returning a result,
  replaying a receipt or exposing encrypted history. Keep raw note snapshots out
  of session responses and minimize retained material; an assistant's answer can
  itself contain source details after access is revoked.
- Add UUID origin and source validation throughout web and mobile, server-issued
  citations, explicit selection and switching, no cross-tenant leakage, source
  freshness and truncation labels, and adversarial prompt-injection tests.
- Complete owner/provider/legal review for content that may include restricted
  Square-derived material. The size bound is not a provider-content filter.
- Test failures, outages, pending and uncertain paid usage, retries, concurrent
  changes, access changes, and real MySQL lock behavior before any rollout.

Current Ask intentionally remains workspace-only. No fixture or source test
establishes approval to send live event notes to any model provider.
