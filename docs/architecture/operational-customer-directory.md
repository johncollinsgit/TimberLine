# Operational customer directory

## Classification

- **Capability:** Shared operational customer-directory presentation and
  recoverable archival controls.
- **Tenant scope:** The presentation is selected from the existing field-work
  profile. Any workspace whose reusable work profile is `trades` receives the
  operational directory. Retail workspaces retain their marketing enrichment;
  field-service workspaces never receive Growave, loyalty, review, or birthday
  customer presentation.
- **Entitlement and billing:** Included with the existing Field Service work
  experience. It creates no new module, plan, billable add-on, or provider
  connection.
- **Canonical contracts:** `MarketingProfile` remains the customer identity
  record. `FieldServiceWorkProfileService` selects the presentation. Field
  jobs remain linked through `FieldServiceJob::marketing_profile_id`.
- **Non-Forestry behavior:** The feature is not tenant-slug specific. A future
  trades workspace receives the same operational presentation automatically;
  other work profiles are unchanged.

## Data and safety contract

- "Delete" in the operational directory means **archive**. It excludes a
  customer from the active directory while retaining jobs, address history,
  consent, source provenance, and all linked records. An authorized user can
  restore archived customers from the Archived filter.
- Legacy marketing, loyalty, Growave, review, and birthday data is not deleted
  or modified. It is intentionally omitted from the operational presentation
  because it is not relevant to field-service customer work. A permanent
  purge, if ever needed, requires a separately reviewed retention and
  referential-integrity workflow.
- Google Calendar is read-only in the Field Service calendar. It reuses the
  existing tenant-owned Google OAuth connection and does not create, update,
  or delete Google events. Connection authorization remains subject to the
  existing workflow-automations entitlement and Google consent flow.
