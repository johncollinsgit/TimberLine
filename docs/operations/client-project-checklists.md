# Client project launch checklists

Authenticated workspace members can open `/client/projects/checklist`. The
navigation entry appears only when the current tenant has an enabled checklist
project. This is a presentation extension of existing client projects, visible
tickets and ticket tasks; it adds no module, entitlement or database tables.

The two lists use `client` and `evergrove` task ownership. Active tenant
owners/admins/managers can update client tasks. Only authorized Evergrove
operators with active membership can update provider tasks. The endpoint checks
task, ticket, project and tenant ownership, rejects hidden tickets and disabled
projects, and records status changes in the existing operator audit table.
Completion is an explicit boolean, is safe to replay, and can be undone. It does
not change agreements, payments, channel activation or website publication.

## Import reviewed client content

Keep private source transcripts and the reviewed JSON out of the repository.
The JSON has `key`, `title`, `summary`, `client_label`, `provider_label`, and
`groups`. Each group has a stable `key`, `title` and `tasks`; each task has a
stable `key`, `title`, `details`, `owner` (`client` or `evergrove`) and `complete`
(boolean). Task keys must be unique across the import.

```
php artisan client-projects:import-checklist <tenant-slug> <reviewed-file.json>
php artisan client-projects:import-checklist <tenant-slug> <reviewed-file.json> --apply --actor=<operator-user-id>
```

The command locks the tenant, creates only missing project/group/task records,
and preserves existing wording and progress on replay. It requires an
operator who belongs to the tenant. No emails, invitations, texts or welcome
notifications are sent. Correct existing content through a separately reviewed,
tenant-scoped update; replay deliberately does not overwrite customer work.

Carolina Barrel's initial content was reviewed against the August/September
2026 emails and the available Sean Nisbet Messages thread. Photo/video receipt
is separate from review, catalog preparation and final approval. Additional
sales channels remain investigation tasks, not promises of provider eligibility
or automatic activation. Welcome delivery is on hold at John's request.

## Verification

`ProjectChecklistTest` covers visibility, tenant isolation, inactive membership,
provider-task authorization, complete/reopen, duplicate updates, input validation
and preview/replay imports. Check desktop and phone layouts, collapsed sections,
saved state after reload, and the failure state if a save is rejected.
