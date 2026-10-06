# Level Foundations transcription runbook

## Purpose

This is a disabled-by-default, server-to-server transcription bridge for the Level Foundations editor. It keeps the generative-AI credential in Everbranch and does not expose Everbranch workspace data to Level Foundations.

## Required Everbranch production configuration

- `EVERBRANCH_BUD_AI_PROVIDER=openai`
- `EVERBRANCH_BUD_AI_API_KEY=<provider secret>`
- `LEVEL_FOUNDATIONS_TRANSCRIPTION_ENABLED=true`
- `LEVEL_FOUNDATIONS_TRANSCRIPTION_TOKEN=<unique high-entropy shared secret>`

The matching Level Foundations runtime configuration is:

- `EVERBRANCH_TRANSCRIPTION_URL=https://app.theeverbranch.com/api/integrations/level-foundations/transcriptions`
- `EVERBRANCH_TRANSCRIPTION_TOKEN=<the same shared secret>`

Never commit, log, email, or paste either secret into a ticket. Rotate both values together if the integration token is exposed.

## Release and smoke check

1. Ship Everbranch through the normal protected GitHub Actions and Forge release path.
2. Confirm `https://app.theeverbranch.com/ready` is healthy after the exact release activates.
3. Set the two Everbranch integration values and the two Level Foundations runtime values.
4. From an authenticated Level Foundations admin editor, record a short non-sensitive sample and select **Create transcript**.
5. Confirm text returns, is editable in the Foundation editor, and neither server logs nor browser responses contain a provider secret.

## Disable and incident response

Set `LEVEL_FOUNDATIONS_TRANSCRIPTION_ENABLED=false` to reject new requests immediately. If the shared token might have been exposed, disable first, generate a new high-entropy token, update both runtimes, and then re-enable. Provider-key exposure follows the provider's credential-rotation procedure; never reuse the exposed key.
