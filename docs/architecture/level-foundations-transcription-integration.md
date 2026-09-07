# Level Foundations transcription integration

## Classification

- **Capability:** a temporary tenant-specific integration override for the Level Foundations content editor.
- **Tenant scope:** it serves only the separately hosted Level Foundations application. It does not resolve, create, or expose an Everbranch tenant, workspace, user, or data record.
- **Entitlement and billing:** this endpoint has no customer-facing entitlement or billing effect. The Level Foundations operator is responsible for the provider usage under Everbranch's configured AI account. Any future customer-facing transcription product must become a tenant-scoped, metered Bud add-on with an approved pricing, budget, audit, and consent model.
- **Canonical contracts reused:** Everbranch's existing Bud provider configuration and Laravel HTTP client. The endpoint adds no tables, jobs, tenant identity path, or audio persistence.
- **Non-Forestry fit:** the integration is deliberately not tied to Modern Forestry. A future generic version must replace the single integration token with a tenant-scoped provider contract before serving another customer.

## Security and data boundary

- The Level Foundations application sends a short-lived audio upload directly to this private endpoint using a bearer token held only in server-side runtime configuration.
- Everbranch validates the token in constant time, rate limits the request, validates the upload type and 25 MB size, then forwards the audio once to the configured provider.
- Audio and transcript contents are neither stored nor logged by Everbranch. Provider errors are returned as generic messages.
- The OpenAI credential remains only in Everbranch runtime configuration. The Level Foundations deployment holds only the integration URL and integration bearer token.

## Activation boundary

This route is disabled until all of these production environment values are set:

- `EVERBRANCH_BUD_AI_API_KEY`
- `LEVEL_FOUNDATIONS_TRANSCRIPTION_ENABLED=true`
- `LEVEL_FOUNDATIONS_TRANSCRIPTION_TOKEN` (a high-entropy secret shared only with Level Foundations runtime configuration)

Set `LEVEL_FOUNDATIONS_TRANSCRIPTION_ENABLED=false` to stop the integration immediately without touching Bud Core or other Everbranch features.
