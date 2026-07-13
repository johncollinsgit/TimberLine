<?php

return [
    'categories' => [
        [
            'slug' => 'getting-started',
            'title' => 'Getting Started',
            'description' => 'The essentials for working in {{tenant_name}}.',
        ],
        [
            'slug' => 'customers-and-work',
            'title' => 'Customers & Work',
            'description' => 'Customer records, jobs, schedules, updates, and photos.',
        ],
        [
            'slug' => 'team',
            'title' => 'Team',
            'description' => 'Assignments, access, communication, and field expectations.',
        ],
        [
            'slug' => 'integrations',
            'title' => 'Integrations',
            'description' => 'Connected systems, imports, and tenant-owned data.',
        ],
    ],

    'articles' => [
        [
            'slug' => 'workspace-overview',
            'title' => '{{tenant_name}} Workspace Overview',
            'excerpt' => 'A practical map of this workspace and where daily work belongs.',
            'category' => 'getting-started',
            'featured' => true,
            'updated_at' => '2026-07-13',
            'published' => true,
            'sections' => [
                [
                    'id' => 'one-workspace',
                    'title' => 'One Workspace',
                    'paragraphs' => [
                        '{{tenant_name}} data is isolated from every other Everbranch workspace. Customers, jobs, files, imports, and reports shown here belong to this tenant.',
                    ],
                ],
                [
                    'id' => 'daily-path',
                    'title' => 'Daily Path',
                    'checklist' => [
                        'Start with the dashboard for current work and upcoming assignments.',
                        'Open Customers for contact and service history.',
                        'Open Work for job details, notes, photos, and status updates.',
                        'Use Search to find customers, addresses, jobs, and job notes together.',
                    ],
                ],
            ],
            'related' => ['customers-and-jobs', 'calendar-and-assignments', 'team-access'],
        ],
        [
            'slug' => 'customers-and-jobs',
            'title' => 'Customers and Jobs',
            'excerpt' => 'Keep the customer, service address, job scope, access details, and work history together.',
            'category' => 'customers-and-work',
            'updated_at' => '2026-07-13',
            'published' => true,
            'sections' => [
                [
                    'id' => 'customer-record',
                    'title' => 'Customer Record',
                    'checklist' => [
                        'Confirm the customer name, phone number, and service address.',
                        'Keep customer-wide context on the customer record.',
                        'Keep visit-specific details on the job.',
                    ],
                ],
                [
                    'id' => 'job-record',
                    'title' => 'Job Record',
                    'checklist' => [
                        'Describe the work clearly enough for the assigned team member to arrive prepared.',
                        'Put lock box or access instructions in the prominent access field.',
                        'Set the schedule, assignee, and status.',
                        'Post progress updates and photos as the work changes.',
                    ],
                ],
            ],
            'related' => ['calendar-and-assignments', 'job-updates-and-photos'],
        ],
        [
            'slug' => 'calendar-and-assignments',
            'title' => 'Calendar and Assignments',
            'excerpt' => 'Schedule upcoming work and give each team member a clear next action.',
            'category' => 'customers-and-work',
            'updated_at' => '2026-07-13',
            'published' => true,
            'sections' => [
                [
                    'id' => 'schedule-work',
                    'title' => 'Schedule Work',
                    'checklist' => [
                        'Set the job date and time before assigning field work.',
                        'Open a calendar entry to review the complete job record.',
                        'Use the return-to-calendar action to continue scheduling.',
                    ],
                ],
                [
                    'id' => 'assign-work',
                    'title' => 'Assign Work',
                    'checklist' => [
                        'Assign the responsible team member.',
                        'Use job tasks for smaller actions with their own due dates and statuses.',
                        'Mention teammates in job updates when their attention is needed.',
                    ],
                ],
            ],
            'related' => ['customers-and-jobs', 'team-access'],
        ],
        [
            'slug' => 'job-updates-and-photos',
            'title' => 'Job Updates and Photos',
            'excerpt' => 'Build a durable job history from field notes, mentions, and photos.',
            'category' => 'customers-and-work',
            'updated_at' => '2026-07-13',
            'published' => true,
            'sections' => [
                [
                    'id' => 'post-an-update',
                    'title' => 'Post an Update',
                    'checklist' => [
                        'Describe what was completed, what changed, and what remains.',
                        'Mention a teammate when a response or handoff is required.',
                        'Use the job status for the overall stage; use posts for the work story.',
                    ],
                ],
                [
                    'id' => 'photos',
                    'title' => 'Photos',
                    'checklist' => [
                        'Attach photos to the job or the update they document.',
                        'Use clear captions when the reason for the photo is not obvious.',
                        'Confirm customer-sensitive images belong in this tenant before uploading.',
                    ],
                ],
            ],
            'related' => ['customers-and-jobs'],
        ],
        [
            'slug' => 'team-access',
            'title' => 'Team Access',
            'excerpt' => 'Give owners, admins, and field team members only the access needed for their role.',
            'category' => 'team',
            'updated_at' => '2026-07-13',
            'published' => true,
            'sections' => [
                [
                    'id' => 'roles',
                    'title' => 'Role Boundaries',
                    'paragraphs' => [
                        'Owners and tenant admins manage integrations, reports, and workspace settings. Team members work with customers, jobs, calendars, assignments, and job communication without financial-report access.',
                    ],
                ],
                [
                    'id' => 'tenant-boundary',
                    'title' => 'Tenant Boundary',
                    'paragraphs' => [
                        'A user may belong to more than one workspace, but switching workspaces changes every customer, job, import, report, and guide query to the selected tenant.',
                    ],
                ],
            ],
            'related' => ['workspace-overview'],
        ],
        [
            'slug' => 'connected-systems',
            'title' => 'Connected Systems',
            'excerpt' => 'Understand how imports and integrations stay attached to this workspace.',
            'category' => 'integrations',
            'updated_at' => '2026-07-13',
            'published' => true,
            'sections' => [
                [
                    'id' => 'tenant-owned-connections',
                    'title' => 'Tenant-Owned Connections',
                    'paragraphs' => [
                        'OAuth connections, import runs, exceptions, and imported records are owned by the active tenant. Connecting one workspace never grants another workspace access to that provider account.',
                    ],
                ],
                [
                    'id' => 'safe-imports',
                    'title' => 'Safe Imports',
                    'checklist' => [
                        'Preview or dry-run a new source before creating business records.',
                        'Review proposed matches, conflicts, and skipped records.',
                        'Keep source identifiers so repeated imports update instead of duplicating.',
                        'Verify search and reports from the same tenant after import.',
                    ],
                ],
            ],
            'related' => ['quickbooks-connection'],
        ],
        [
            'slug' => 'quickbooks-connection',
            'title' => 'QuickBooks Connection',
            'excerpt' => 'Connect, audit, and import QuickBooks data without exposing it to another workspace.',
            'category' => 'integrations',
            'updated_at' => '2026-07-13',
            'published' => true,
            'sections' => [
                [
                    'id' => 'connection',
                    'title' => 'Connection',
                    'paragraphs' => [
                        'A tenant admin authorizes the QuickBooks company for this workspace. Tokens and source records are encrypted and tenant-scoped.',
                    ],
                ],
                [
                    'id' => 'review-first',
                    'title' => 'Review Before Import',
                    'checklist' => [
                        'Run the read-only audit first.',
                        'Review aggregate counts, note coverage, and proposed mappings.',
                        'Back up the tenant data before the first live import.',
                        'Run the import twice and confirm the second run creates no duplicates.',
                    ],
                ],
            ],
            'related' => ['connected-systems'],
        ],
    ],
];
