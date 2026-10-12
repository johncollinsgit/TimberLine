# Field mobile customer contact — 2026-10-12

The mobile job detail previously returned homeowner email and phone even when
`can_edit_customer` was false and Collins customer-directory APIs were forbidden.
Both native platforms could therefore expose full contact through a job.

Field workspaces now apply the same client-access rule to customer directory,
detail, messaging, module-screen, and search reads. Their bootstrap omits client
navigation for ordinary members. Workspaces without Field Service retain their
existing customer behavior, and Collins retains its narrower identity rule.

The job detail now returns null contact fields and `can_view_customer_contact:
false` for ordinary team members. Authorized managers retain contact access;
Collins also retains its verified account restriction. Job names, work-site
addresses and project-manager contact remain available for completing work.

Validation: CollinsEmployeeAccessReleaseTest exercises an actual member, another
Collins admin, and the authorized owner, including customer-directory/search
denial and job-detail redaction. TeamMessageAttachmentTest retains private
conversation download and revoked-membership guards for both PNG photos and PDFs.
Run those files and EverbranchMobilePlatformTest with Pest; 36 tests and 1,012
assertions passed. The backend production frontend build and dirty-file Pint
check passed.

Rollout: merge through the normal protected test/build gate, then confirm `/ready`
reports the merged release. No migration is required. Existing iOS 2.3.23/build 39
and Android APK installations receive the API restriction immediately after that
release. Native 2.3.24/build 40 also masks contact in the shared client and in
administrator employee-preview mode. Do not call this deployed before readiness
verification.
