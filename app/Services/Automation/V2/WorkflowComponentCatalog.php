<?php

namespace App\Services\Automation\V2;

use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\V2\Operations\AsanaTaskTriggerOperation;
use App\Services\Automation\V2\Operations\DelayControlHandler;
use App\Services\Automation\V2\Operations\FilterControlHandler;
use App\Services\Automation\V2\Operations\GoogleCalendarUpsertEventActionOperation;
use App\Services\Automation\V2\Operations\Native\AddJobNoteActionOperation;
use App\Services\Automation\V2\Operations\Native\ChangeJobStatusActionOperation;
use App\Services\Automation\V2\Operations\Native\CreateJobTaskActionOperation;
use App\Services\Automation\V2\Operations\Native\CustomerCreatedTriggerOperation;
use App\Services\Automation\V2\Operations\Native\JobCreatedTriggerOperation;
use App\Services\Automation\V2\Operations\Native\JobStatusChangedTriggerOperation;
use App\Services\Automation\V2\Operations\Native\SendEmailActionOperation;
use App\Services\Automation\V2\Operations\Native\TaskCompletedTriggerOperation;
use App\Services\Automation\V2\Operations\PathsControlHandler;
use App\Services\Automation\V2\Operations\ShopifyOrderTriggerOperation;
use App\Services\Automation\V2\Operations\SquareOrderTriggerOperation;

class WorkflowComponentCatalog
{
    /**
     * Public, handler-free catalog for the Workflow Studio.
     *
     * @return array{
     *     components:list<array<string,mixed>>,
     *     roadmap:list<array<string,mixed>>,
     *     templates:list<array<string,mixed>>
     * }
     */
    public function publicCatalog(): array
    {
        return [
            'components' => array_values(array_map(
                fn (array $component): array => $this->publicComponent($component),
                $this->components()
            )),
            'roadmap' => array_values(array_map(
                fn (array $component): array => $this->publicComponent($component),
                $this->roadmap()
            )),
            'templates' => array_values(array_map(
                fn (array $template): array => $this->publicTemplate($template),
                $this->templates()
            )),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public function components(): array
    {
        return array_filter(
            $this->definitions(),
            fn (array $component): bool => (bool) $component['available']
        );
    }

    /** @return array<string,array<string,mixed>> */
    public function roadmap(): array
    {
        return array_filter(
            $this->definitions(),
            fn (array $component): bool => ! (bool) $component['available']
        );
    }

    /** @return array<string,mixed>|null */
    public function component(string $key): ?array
    {
        return $this->definitions()[strtolower(trim($key))] ?? null;
    }

    /** @return array<string,mixed> */
    public function executable(string $key): array
    {
        $component = $this->component($key);

        if (! is_array($component) || ! (bool) ($component['available'] ?? false)) {
            throw new AutomationWorkflowException("Workflow component [{$key}] is not available.");
        }

        if (! is_string($component['handler'] ?? null) || trim((string) $component['handler']) === '') {
            throw new AutomationWorkflowException("Workflow component [{$key}] has no executable handler.");
        }

        return $component;
    }

    public function handlerClass(string $key): string
    {
        return (string) $this->executable($key)['handler'];
    }

    /** @return array<string,array<string,mixed>> */
    public function templates(): array
    {
        return [
            'asana_to_google_calendar' => [
                'key' => 'asana_to_google_calendar',
                'name' => 'Asana tasks to Google Calendar',
                'description' => 'Create or update calendar events from new and updated Asana tasks.',
                'available' => true,
                'trigger_component_key' => 'asana.task.created_or_updated',
                'step_component_keys' => ['google_calendar.event.upsert'],
                'trigger_config' => [
                    'modified_overlap_minutes' => 5,
                    'bootstrap_lookback_days' => 14,
                    'poll_limit' => 100,
                    'max_tasks_per_run' => 500,
                ],
                'step_configs' => [[
                    'timezone' => (string) config('app.timezone', 'UTC'),
                    'default_duration_minutes' => 60,
                    'skip_completed_tasks' => true,
                    'date_only_mode' => 'all_day',
                ]],
            ],
            'shopify_order_to_google_calendar' => [
                'key' => 'shopify_order_to_google_calendar',
                'name' => 'Shopify orders to Google Calendar',
                'description' => 'Place new and updated Shopify orders on a fulfillment calendar.',
                'available' => true,
                'trigger_component_key' => 'shopify.order.created_or_updated',
                'step_component_keys' => ['google_calendar.event.upsert'],
                'trigger_config' => [
                    'schedule_source' => 'fulfillment',
                    'poll_limit' => 100,
                ],
                'step_configs' => [[
                    'timezone' => (string) config('app.timezone', 'UTC'),
                    'default_duration_minutes' => 60,
                    'date_only_mode' => 'all_day',
                ]],
            ],
            'square_order_to_google_calendar' => [
                'key' => 'square_order_to_google_calendar',
                'name' => 'Square orders to Google Calendar',
                'description' => 'Place new and updated Square orders on an operations calendar.',
                'available' => true,
                'trigger_component_key' => 'square.order.created_or_updated',
                'step_component_keys' => ['google_calendar.event.upsert'],
                'trigger_config' => [
                    'schedule_source' => 'fulfillment',
                    'poll_limit' => 100,
                ],
                'step_configs' => [[
                    'timezone' => (string) config('app.timezone', 'UTC'),
                    'default_duration_minutes' => 60,
                    'date_only_mode' => 'all_day',
                ]],
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function definitions(): array
    {
        $definitions = [
            $this->componentDefinition(
                key: 'everbranch.customer.created',
                label: 'Customer created',
                description: 'Starts when a customer is created in this workspace.',
                kind: 'trigger',
                provider: 'everbranch',
                providerLabel: 'Everbranch',
                category: 'apps',
                icon: 'everbranch',
                handler: CustomerCreatedTriggerOperation::class,
                connectionRequired: false,
                testPolicy: 'sample_read',
                configFields: [],
                outputFields: [
                    $this->schemaField('customer_id', 'Customer ID', 'string'),
                    $this->schemaField('name', 'Customer name', 'string'),
                    $this->schemaField('email', 'Email', 'string'),
                    $this->schemaField('phone', 'Phone', 'string'),
                ],
            ),
            $this->componentDefinition(
                key: 'everbranch.job.created',
                label: 'Job created',
                description: 'Starts when a field-service job is created in this workspace.',
                kind: 'trigger',
                provider: 'everbranch',
                providerLabel: 'Everbranch',
                category: 'apps',
                icon: 'everbranch',
                handler: JobCreatedTriggerOperation::class,
                connectionRequired: false,
                testPolicy: 'sample_read',
                configFields: [],
                outputFields: $this->jobOutputFields(),
            ),
            $this->componentDefinition(
                key: 'everbranch.job.status_changed',
                label: 'Job status changed',
                description: 'Starts when a field-service job moves to another status.',
                kind: 'trigger',
                provider: 'everbranch',
                providerLabel: 'Everbranch',
                category: 'apps',
                icon: 'everbranch',
                handler: JobStatusChangedTriggerOperation::class,
                connectionRequired: false,
                testPolicy: 'sample_read',
                configFields: [
                    $this->configField('from_status', 'From status', 'string', help: 'Optional. Leave empty to match any prior status.'),
                    $this->configField('to_status', 'To status', 'string', help: 'Optional. Leave empty to match any new status.'),
                ],
                outputFields: [
                    ...$this->jobOutputFields(),
                    $this->schemaField('previous_status', 'Previous status', 'string'),
                    $this->schemaField('status', 'New status', 'string'),
                ],
            ),
            $this->componentDefinition(
                key: 'everbranch.task.completed',
                label: 'Task completed',
                description: 'Starts when a field-service task is completed.',
                kind: 'trigger',
                provider: 'everbranch',
                providerLabel: 'Everbranch',
                category: 'apps',
                icon: 'everbranch',
                handler: TaskCompletedTriggerOperation::class,
                connectionRequired: false,
                testPolicy: 'sample_read',
                configFields: [],
                outputFields: [
                    $this->schemaField('task_id', 'Task ID', 'string'),
                    $this->schemaField('job_id', 'Job ID', 'string'),
                    $this->schemaField('title', 'Task title', 'string'),
                    $this->schemaField('completed_at', 'Completed at', 'datetime'),
                ],
            ),
            $this->componentDefinition(
                key: 'asana.task.created_or_updated',
                label: 'Task created or updated',
                description: 'Starts when a task is created or updated in an Asana project.',
                kind: 'trigger',
                provider: 'asana',
                providerLabel: 'Asana',
                category: 'apps',
                icon: 'asana',
                handler: AsanaTaskTriggerOperation::class,
                connectionRequired: true,
                testPolicy: 'sample_read',
                configFields: [
                    $this->configField('project_gid', 'Project', 'string', true, 'Choose the Asana project to watch.', 'Project ID'),
                    $this->configField('modified_overlap_minutes', 'Polling overlap', 'integer', false, 'Refetch a short overlap to avoid missing edits.', min: 1, max: 60, default: 5),
                    $this->configField('bootstrap_lookback_days', 'Initial lookback', 'integer', false, 'How far back the first poll can read.', min: 1, max: 90, default: 14),
                    $this->configField('poll_limit', 'Items per request', 'integer', false, min: 1, max: 100, default: 100),
                    $this->configField('max_tasks_per_run', 'Maximum tasks per run', 'integer', false, min: 1, max: 1000, default: 500),
                    $this->configField('schedule_source', 'Schedule source', 'select', false, options: [
                        ['value' => 'source_date', 'label' => 'Task due date'],
                    ], default: 'source_date'),
                ],
                outputFields: [
                    $this->schemaField('id', 'Task ID', 'string'),
                    $this->schemaField('name', 'Task name', 'string'),
                    $this->schemaField('notes', 'Notes', 'string'),
                    $this->schemaField('due_on', 'Due date', 'date'),
                    $this->schemaField('completed', 'Completed', 'boolean'),
                    $this->schemaField('permalink_url', 'Asana link', 'string'),
                ],
                connectionProvider: 'asana',
                requiredScopes: ['projects:read', 'tasks:read'],
            ),
            $this->componentDefinition(
                key: 'shopify.order.created_or_updated',
                label: 'Order created or updated',
                description: 'Starts when a Shopify order is created or updated.',
                kind: 'trigger',
                provider: 'shopify',
                providerLabel: 'Shopify',
                category: 'apps',
                icon: 'shopify',
                handler: ShopifyOrderTriggerOperation::class,
                connectionRequired: true,
                testPolicy: 'sample_read',
                configFields: [
                    $this->configField('schedule_source', 'Schedule source', 'select', false, options: [
                        ['value' => 'fulfillment', 'label' => 'Fulfillment date'],
                        ['value' => 'created_at', 'label' => 'Order date'],
                    ], default: 'fulfillment'),
                    $this->configField('poll_limit', 'Orders per poll', 'integer', false, min: 1, max: 250, default: 100),
                ],
                outputFields: $this->commerceOrderOutputFields(),
                connectionProvider: 'shopify',
                requiredScopes: ['read_orders'],
            ),
            $this->componentDefinition(
                key: 'square.order.created_or_updated',
                label: 'Order created or updated',
                description: 'Starts when a Square order is created or updated.',
                kind: 'trigger',
                provider: 'square',
                providerLabel: 'Square',
                category: 'apps',
                icon: 'square',
                handler: SquareOrderTriggerOperation::class,
                connectionRequired: true,
                testPolicy: 'sample_read',
                configFields: [
                    $this->configField('location_id', 'Location', 'string', false, 'Optional. Limit orders to one Square location.'),
                    $this->configField('schedule_source', 'Schedule source', 'select', false, options: [
                        ['value' => 'fulfillment', 'label' => 'Fulfillment date'],
                        ['value' => 'created_at', 'label' => 'Order date'],
                    ], default: 'fulfillment'),
                    $this->configField('poll_limit', 'Orders per poll', 'integer', false, min: 1, max: 100, default: 100),
                ],
                outputFields: $this->commerceOrderOutputFields(),
                connectionProvider: 'square',
                requiredScopes: ['ORDERS_READ'],
            ),
            $this->componentDefinition(
                key: 'everbranch.email.send',
                label: 'Send email',
                description: 'Sends a transactional email through this workspace’s configured email provider.',
                kind: 'action',
                provider: 'everbranch',
                providerLabel: 'Everbranch',
                category: 'apps',
                icon: 'everbranch',
                handler: SendEmailActionOperation::class,
                connectionRequired: false,
                testPolicy: 'safe_preview',
                configFields: [
                    $this->configField('to', 'To', 'mapped_value', true, 'Use a fixed email or map one from an earlier step.', 'name@example.com'),
                    $this->configField('subject', 'Subject', 'mapped_value', true),
                    $this->configField('body', 'Message', 'mapped_value', true),
                    $this->configField('reply_to', 'Reply to', 'mapped_value', false),
                ],
                inputFields: [
                    $this->schemaField('to', 'To', 'string', true),
                    $this->schemaField('subject', 'Subject', 'string', true),
                    $this->schemaField('body', 'Message', 'string', true),
                    $this->schemaField('reply_to', 'Reply to', 'string'),
                ],
                outputFields: [
                    $this->schemaField('message_id', 'Message ID', 'string'),
                    $this->schemaField('accepted_at', 'Accepted at', 'datetime'),
                ],
            ),
            $this->componentDefinition(
                key: 'everbranch.job.task.create',
                label: 'Create job task',
                description: 'Adds a task to an existing field-service job.',
                kind: 'action',
                provider: 'everbranch',
                providerLabel: 'Everbranch',
                category: 'apps',
                icon: 'everbranch',
                handler: CreateJobTaskActionOperation::class,
                connectionRequired: false,
                testPolicy: 'transaction_rollback',
                configFields: [
                    $this->configField('job_id', 'Job', 'mapped_value', true),
                    $this->configField('title', 'Task title', 'mapped_value', true),
                    $this->configField('description', 'Description', 'mapped_value', false),
                    $this->configField('due_at', 'Due at', 'mapped_value', false),
                ],
                inputFields: [
                    $this->schemaField('job_id', 'Job ID', 'string', true),
                    $this->schemaField('title', 'Task title', 'string', true),
                    $this->schemaField('description', 'Description', 'string'),
                    $this->schemaField('due_at', 'Due at', 'datetime'),
                ],
                outputFields: [
                    $this->schemaField('task_id', 'Task ID', 'string'),
                    $this->schemaField('job_id', 'Job ID', 'string'),
                ],
            ),
            $this->componentDefinition(
                key: 'everbranch.job.note.add',
                label: 'Add job note',
                description: 'Adds an internal note to an existing field-service job.',
                kind: 'action',
                provider: 'everbranch',
                providerLabel: 'Everbranch',
                category: 'apps',
                icon: 'everbranch',
                handler: AddJobNoteActionOperation::class,
                connectionRequired: false,
                testPolicy: 'transaction_rollback',
                configFields: [
                    $this->configField('job_id', 'Job', 'mapped_value', true),
                    $this->configField('body', 'Note', 'mapped_value', true),
                ],
                inputFields: [
                    $this->schemaField('job_id', 'Job ID', 'string', true),
                    $this->schemaField('body', 'Note', 'string', true),
                ],
                outputFields: [
                    $this->schemaField('note_id', 'Note ID', 'string'),
                    $this->schemaField('job_id', 'Job ID', 'string'),
                ],
            ),
            $this->componentDefinition(
                key: 'everbranch.job.status.change',
                label: 'Change job status',
                description: 'Moves an existing field-service job to a supported status.',
                kind: 'action',
                provider: 'everbranch',
                providerLabel: 'Everbranch',
                category: 'apps',
                icon: 'everbranch',
                handler: ChangeJobStatusActionOperation::class,
                connectionRequired: false,
                testPolicy: 'transaction_rollback',
                configFields: [
                    $this->configField('job_id', 'Job', 'mapped_value', true),
                    $this->configField('action', 'Status action', 'select', true, options: [
                        ['value' => 'start', 'label' => 'Start'],
                        ['value' => 'resume', 'label' => 'Resume'],
                        ['value' => 'block', 'label' => 'Block'],
                        ['value' => 'complete', 'label' => 'Complete'],
                        ['value' => 'cancel', 'label' => 'Cancel'],
                        ['value' => 'archive', 'label' => 'Archive'],
                        ['value' => 'reopen', 'label' => 'Reopen'],
                    ]),
                    $this->configField('reason', 'Reason', 'mapped_value', false, 'Required when blocking a job.'),
                ],
                inputFields: [
                    $this->schemaField('job_id', 'Job ID', 'string', true),
                    $this->schemaField('action', 'Status action', 'string', true),
                    $this->schemaField('reason', 'Reason', 'string'),
                ],
                outputFields: $this->jobOutputFields(),
            ),
            $this->componentDefinition(
                key: 'google_calendar.event.upsert',
                label: 'Create or update event',
                description: 'Creates a Google Calendar event and updates the same event on later runs.',
                kind: 'action',
                provider: 'google_calendar',
                providerLabel: 'Google Calendar',
                category: 'apps',
                icon: 'google_calendar',
                handler: GoogleCalendarUpsertEventActionOperation::class,
                connectionRequired: true,
                testPolicy: 'write_and_cleanup',
                configFields: [
                    $this->configField('calendar_id', 'Calendar', 'string', true, 'Choose a writable calendar.'),
                    $this->configField('timezone', 'Time zone', 'string', true, default: (string) config('app.timezone', 'UTC')),
                    $this->configField('default_duration_minutes', 'Default duration', 'integer', false, min: 1, max: 1440, default: 60),
                    $this->configField('skip_completed_tasks', 'Skip completed tasks', 'boolean', false, default: true),
                    $this->configField('date_only_mode', 'Date-only events', 'select', false, options: [
                        ['value' => 'all_day', 'label' => 'All-day event'],
                        ['value' => 'default_time', 'label' => 'Use default time'],
                    ], default: 'all_day'),
                    $this->configField(
                        'default_start_time',
                        'Default start time',
                        'string',
                        false,
                        'Used when a source provides a date without a time.',
                        '09:00',
                        default: (string) config(
                            'automation_workflows.workflows.asana_to_google_calendar.action.default_start_time',
                            '09:00',
                        ),
                    ),
                    $this->configField('source_id', 'Source ID', 'mapped_value', false),
                    $this->configField('title', 'Event title', 'mapped_value', false),
                    $this->configField('description', 'Description', 'mapped_value', false),
                    $this->configField('starts_at', 'Starts at', 'mapped_value', false),
                    $this->configField('ends_at', 'Ends at', 'mapped_value', false),
                    $this->configField('location', 'Location', 'mapped_value', false),
                    $this->configField('presentation', 'Calendar appearance', 'object', false),
                ],
                inputFields: [
                    $this->schemaField('source_id', 'Source ID', 'string', true),
                    $this->schemaField('title', 'Event title', 'string', true),
                    $this->schemaField('description', 'Description', 'string'),
                    $this->schemaField('starts_at', 'Starts at', 'datetime', true),
                    $this->schemaField('ends_at', 'Ends at', 'datetime'),
                    $this->schemaField('location', 'Location', 'string'),
                ],
                outputFields: [
                    $this->schemaField('event_id', 'Event ID', 'string'),
                    $this->schemaField('event_url', 'Event URL', 'string'),
                    $this->schemaField('operation', 'Created or updated', 'string'),
                ],
                connectionProvider: 'google_calendar',
                requiredScopes: ['https://www.googleapis.com/auth/calendar.events'],
            ),
            $this->componentDefinition(
                key: 'core.filter',
                label: 'Filter',
                description: 'Continues only when the item matches your conditions.',
                kind: 'filter',
                provider: 'core',
                providerLabel: 'Flow controls',
                category: 'flow_controls',
                icon: 'filter',
                handler: FilterControlHandler::class,
                connectionRequired: false,
                testPolicy: 'deterministic',
                configFields: [
                    $this->configField('logic', 'Match', 'select', true, options: [
                        ['value' => 'and', 'label' => 'All conditions'],
                        ['value' => 'or', 'label' => 'Any condition'],
                    ], default: 'and'),
                    $this->configField('conditions', 'Conditions', 'condition_list', true),
                ],
                outputFields: [
                    $this->schemaField('matched', 'Matched', 'boolean'),
                ],
            ),
            $this->componentDefinition(
                key: 'core.delay_for',
                label: 'Delay for',
                description: 'Pauses each workflow item for a fixed or mapped duration.',
                kind: 'delay',
                provider: 'core',
                providerLabel: 'Flow controls',
                category: 'flow_controls',
                icon: 'delay',
                handler: DelayControlHandler::class,
                connectionRequired: false,
                testPolicy: 'deterministic',
                configFields: [
                    $this->configField('duration', 'Duration', 'mapped_value', true),
                    $this->configField('unit', 'Unit', 'select', true, options: [
                        ['value' => 'minutes', 'label' => 'Minutes'],
                        ['value' => 'hours', 'label' => 'Hours'],
                        ['value' => 'days', 'label' => 'Days'],
                    ], default: 'minutes'),
                ],
                outputFields: [
                    $this->schemaField('resume_at', 'Resume at', 'datetime'),
                ],
            ),
            $this->componentDefinition(
                key: 'core.delay_until',
                label: 'Delay until',
                description: 'Pauses each workflow item until a fixed or mapped date and time.',
                kind: 'delay',
                provider: 'core',
                providerLabel: 'Flow controls',
                category: 'flow_controls',
                icon: 'delay',
                handler: DelayControlHandler::class,
                connectionRequired: false,
                testPolicy: 'deterministic',
                configFields: [
                    $this->configField('datetime', 'Date and time', 'mapped_value', true),
                    $this->configField('past_date_behavior', 'If the time has passed', 'select', true, options: [
                        ['value' => 'continue_if_within_15_minutes', 'label' => 'Continue if up to 15 minutes ago'],
                        ['value' => 'continue_if_within_1_hour', 'label' => 'Continue if up to 1 hour ago'],
                        ['value' => 'continue_if_within_1_day', 'label' => 'Continue if up to 1 day ago'],
                        ['value' => 'continue', 'label' => 'Always continue'],
                    ], default: 'continue_if_within_1_day'),
                ],
                outputFields: [
                    $this->schemaField('resume_at', 'Resume at', 'datetime'),
                ],
            ),
            $this->componentDefinition(
                key: 'core.paths',
                label: 'Paths',
                description: 'Runs one or more ordered branches when their rules match.',
                kind: 'paths',
                provider: 'core',
                providerLabel: 'Flow controls',
                category: 'flow_controls',
                icon: 'paths',
                handler: PathsControlHandler::class,
                connectionRequired: false,
                testPolicy: 'deterministic',
                configFields: [
                    $this->configField('branches', 'Branches', 'path_list', true),
                ],
                outputFields: [
                    $this->schemaField('matched_branch_keys', 'Matched branches', 'array'),
                ],
            ),
            $this->componentDefinition(
                key: 'gmail.message.send',
                label: 'Send email',
                description: 'Planned Gmail action pending separate OAuth consent and send scopes.',
                kind: 'action',
                provider: 'gmail',
                providerLabel: 'Gmail',
                category: 'apps',
                icon: 'gmail',
                handler: '',
                connectionRequired: true,
                testPolicy: 'unavailable',
                configFields: [],
                available: false,
            ),
            $this->componentDefinition(
                key: 'google_sheets.row.create',
                label: 'Create spreadsheet row',
                description: 'Planned Google Sheets action pending spreadsheet scopes and provider execution.',
                kind: 'action',
                provider: 'google_sheets',
                providerLabel: 'Google Sheets',
                category: 'apps',
                icon: 'google_sheets',
                handler: '',
                connectionRequired: true,
                testPolicy: 'unavailable',
                configFields: [],
                available: false,
            ),
            $this->componentDefinition(
                key: 'squarespace.order.created_or_updated',
                label: 'Order created or updated',
                description: 'Squarespace commerce polling remains on the Connections roadmap.',
                kind: 'trigger',
                provider: 'squarespace',
                providerLabel: 'Squarespace',
                category: 'apps',
                icon: 'squarespace',
                handler: '',
                connectionRequired: true,
                testPolicy: 'unavailable',
                configFields: [],
                available: false,
            ),
            $this->componentDefinition(
                key: 'wix.order.created_or_updated',
                label: 'Order created or updated',
                description: 'Wix commerce polling remains on the Connections roadmap.',
                kind: 'trigger',
                provider: 'wix',
                providerLabel: 'Wix',
                category: 'apps',
                icon: 'wix',
                handler: '',
                connectionRequired: true,
                testPolicy: 'unavailable',
                configFields: [],
                available: false,
            ),
            $this->componentDefinition(
                key: 'core.loop',
                label: 'Loop',
                description: 'Planned after bounded iteration and retry semantics are finalized.',
                kind: 'utility',
                provider: 'core',
                providerLabel: 'Utilities',
                category: 'utilities',
                icon: 'loop',
                handler: '',
                connectionRequired: false,
                testPolicy: 'unavailable',
                configFields: [],
                available: false,
            ),
            $this->componentDefinition(
                key: 'core.formatter',
                label: 'Formatter',
                description: 'Planned after a deterministic typed transform contract is available.',
                kind: 'utility',
                provider: 'core',
                providerLabel: 'Utilities',
                category: 'utilities',
                icon: 'formatter',
                handler: '',
                connectionRequired: false,
                testPolicy: 'unavailable',
                configFields: [],
                available: false,
            ),
            $this->componentDefinition(
                key: 'core.webhook',
                label: 'Webhook',
                description: 'Planned after signing, replay protection, and egress policy are complete.',
                kind: 'utility',
                provider: 'core',
                providerLabel: 'Utilities',
                category: 'utilities',
                icon: 'webhook',
                handler: '',
                connectionRequired: false,
                testPolicy: 'unavailable',
                configFields: [],
                available: false,
            ),
            $this->componentDefinition(
                key: 'core.schedule',
                label: 'Schedule',
                description: 'Planned after timezone and missed-schedule behavior are complete.',
                kind: 'trigger',
                provider: 'core',
                providerLabel: 'Utilities',
                category: 'utilities',
                icon: 'schedule',
                handler: '',
                connectionRequired: false,
                testPolicy: 'unavailable',
                configFields: [],
                available: false,
            ),
            $this->componentDefinition(
                key: 'core.code',
                label: 'Code',
                description: 'Not enabled without a sandboxed execution and security contract.',
                kind: 'utility',
                provider: 'core',
                providerLabel: 'Utilities',
                category: 'utilities',
                icon: 'code',
                handler: '',
                connectionRequired: false,
                testPolicy: 'unavailable',
                configFields: [],
                available: false,
            ),
            $this->componentDefinition(
                key: 'core.ai',
                label: 'AI',
                description: 'Not enabled without data-handling, budget, and deterministic retry policies.',
                kind: 'utility',
                provider: 'core',
                providerLabel: 'Utilities',
                category: 'utilities',
                icon: 'ai',
                handler: '',
                connectionRequired: false,
                testPolicy: 'unavailable',
                configFields: [],
                available: false,
            ),
        ];

        return collect($definitions)->keyBy('key')->all();
    }

    /**
     * @param  list<array<string,mixed>>  $configFields
     * @param  list<array<string,mixed>>  $inputFields
     * @param  list<array<string,mixed>>  $outputFields
     * @param  list<string>  $requiredScopes
     * @return array<string,mixed>
     */
    private function componentDefinition(
        string $key,
        string $label,
        string $description,
        string $kind,
        string $provider,
        string $providerLabel,
        string $category,
        string $icon,
        string $handler,
        bool $connectionRequired,
        string $testPolicy,
        array $configFields,
        array $inputFields = [],
        array $outputFields = [],
        ?string $connectionProvider = null,
        array $requiredScopes = [],
        bool $available = true,
    ): array {
        return [
            'key' => $key,
            'component_key' => $key,
            'label' => $label,
            'description' => $description,
            'kind' => $kind,
            'provider' => $provider,
            'provider_label' => $providerLabel,
            'available' => $available,
            'availability' => $available ? 'available' : 'roadmap',
            'category' => $category,
            'icon' => $icon,
            'handler' => $handler,
            'connection_required' => $connectionRequired,
            'connection_provider' => $connectionProvider ?? $provider,
            'required_scopes' => $requiredScopes,
            'test_policy' => $testPolicy,
            'config_fields' => $configFields,
            'config_schema' => ['fields' => $configFields],
            'input_schema' => ['fields' => $inputFields],
            'output_schema' => ['fields' => $outputFields],
        ];
    }

    /** @return array<string,mixed> */
    private function configField(
        string $key,
        string $label,
        string $type,
        bool $required = false,
        string $help = '',
        string $placeholder = '',
        ?int $min = null,
        ?int $max = null,
        array $options = [],
        mixed $default = null,
    ): array {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => $required,
            'help' => $help,
            'placeholder' => $placeholder,
            'min' => $min,
            'max' => $max,
            'options' => $options,
            'default' => $default,
        ], fn (mixed $value, string $field): bool => match ($field) {
            'required' => true,
            'help', 'placeholder' => $value !== '',
            'options' => $value !== [],
            default => $value !== null,
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** @return array<string,mixed> */
    private function schemaField(string $key, string $label, string $type, bool $required = false): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => $required,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function jobOutputFields(): array
    {
        return [
            $this->schemaField('job_id', 'Job ID', 'string'),
            $this->schemaField('title', 'Job title', 'string'),
            $this->schemaField('status', 'Status', 'string'),
            $this->schemaField('customer_id', 'Customer ID', 'string'),
            $this->schemaField('scheduled_start_at', 'Scheduled start', 'datetime'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function commerceOrderOutputFields(): array
    {
        return [
            $this->schemaField('order_id', 'Order ID', 'string'),
            $this->schemaField('order_number', 'Order number', 'string'),
            $this->schemaField('status', 'Status', 'string'),
            $this->schemaField('customer_name', 'Customer name', 'string'),
            $this->schemaField('customer_email', 'Customer email', 'string'),
            $this->schemaField('fulfillment_at', 'Fulfillment date', 'datetime'),
            $this->schemaField('line_items', 'Line items', 'array'),
        ];
    }

    /** @return array<string,mixed> */
    private function publicComponent(array $component): array
    {
        unset($component['handler']);

        $component['config_fields'] = array_values(array_map(
            function (array $field): array {
                $field['type'] = match ($field['type'] ?? 'string') {
                    'integer' => 'number',
                    'mapped_value' => 'mapping',
                    'string' => 'text',
                    default => $field['type'] ?? 'text',
                };

                return $field;
            },
            array_filter(
                (array) ($component['config_fields'] ?? []),
                fn (array $field): bool => ($field['type'] ?? null) !== 'object'
            )
        ));
        $component['config_schema'] = ['fields' => $component['config_fields']];

        return $component;
    }

    /** @return array<string,mixed> */
    private function publicTemplate(array $template): array
    {
        unset($template['trigger_config'], $template['step_configs']);

        return $template;
    }
}
