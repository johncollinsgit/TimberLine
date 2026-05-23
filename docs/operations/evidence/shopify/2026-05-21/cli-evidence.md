# Shopify CLI Evidence

Date: 2026-05-21.
Status: captured for read-only CLI discovery; deploy/release evidence remains pending operator approval.

## Commands Run

These commands were run from `/Users/johncollins/Code/myapp`.

| Command | Status | Result |
| --- | --- | --- |
| `command -v shopify` | captured | `/opt/homebrew/bin/shopify` |
| `shopify version` | captured | `3.92.1` |
| `shopify app --help` | captured | Shows `app info`, `app dev`, `app deploy`, `app release`, `app webhook`, and related command families. |
| `shopify app dev --help` | captured | Shows local/dev flags including `--client-id`, `--store`, `--no-update`, `--tunnel-url`, and `--use-localhost`. |
| `shopify app deploy --help` | captured | Confirms deploy creates and releases an app version unless `--no-release` is used. |
| `shopify app webhook trigger --help` | captured | Confirms webhook trigger supports `--topic`, `--address`, `--client-id`, `--client-secret`, `--api-version`, and `--delivery-method`. |
| `SHOPIFY_CLI_NO_ANALYTICS=1 shopify app info --path . --client-id 197d01d6597c938c96b3b35fae6a087c --no-color` | captured | Confirms current app configuration shown below. |

## Captured App Info Summary

`shopify app info` returned:

| Field | Captured value |
| --- | --- |
| Configuration file | `shopify.app.toml` |
| App name | `Modern Forestry Backstage` |
| Client ID | `197d01d6597c938c96b3b35fae6a087c` |
| Dev store | `modernforestry.myshopify.com` |
| Update URLs | `No` |
| User | `info@theforestrystudio.com` |
| Shopify CLI | `3.92.1` |
| Package manager | `npm` |
| OS | `darwin-arm64` |
| Shell | `/bin/zsh` |
| Node version | `v25.8.1` |

Directory components reported by CLI:

| Component type | Name | Path |
| --- | --- | --- |
| `theme_app_extension` | `forestry-marketing-embed` | `extensions/forestry-marketing-embed` |
| `web_pixel` | `forestry-marketing-pixel` | `extensions/forestry-marketing-pixel` |

The captured access scopes match the broad TOML scope set documented in `docs/operations/shopify-scope-branding-decision-record.md`.

## Commands Intentionally Not Run

| Command family | Status | Reason |
| --- | --- | --- |
| `shopify app deploy ...` | pending_operator_approval | It creates an app version and releases it unless `--no-release` is used. This PR must not deploy/release blindly. |
| `shopify app release ...` | pending_operator_approval | It changes the released Shopify app version. |
| `shopify app webhook trigger ...` | pending_operator_approval | It sends live HTTP requests to canonical webhook endpoints and may require the app client secret. |
| `shopify app dev ...` | not_run | It starts a dev preview and can update/dev-link app behavior unless carefully configured. |

## Required Next Operator Action

When ready to deploy Shopify app configuration, review and explicitly approve the exact command first. For a draft-only inspection, prefer `shopify app deploy --no-release --path . --client-id 197d01d6597c938c96b3b35fae6a087c --message "Everbranch external evidence draft"` after confirming the Laravel web app routes are deployed.
