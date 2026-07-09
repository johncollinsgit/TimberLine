<?php

use App\Services\Automation\Drivers\AsanaGoogleCalendarWorkflowDriver;

return [
    'enabled' => (bool) env('AUTOMATION_WORKFLOWS_ENABLED', false),

    'drivers' => [
        'asana_google_calendar' => AsanaGoogleCalendarWorkflowDriver::class,
    ],

    // This is a neutral, user-agnostic template of defaults only. Real per-tenant
    // instances (project, calendar, and OAuth credentials) live in each tenant's
    // `tenant_marketing_settings` row and shadow this template at runtime — see
    // TenantWorkflowAutomationSettingsService + AutomationWorkflowEngine::resolvedDefinitions().
    // Do NOT bake a specific tenant's project/calendar/tokens in here.
    'workflows' => [
        'asana_to_google_calendar' => [
            'enabled' => (bool) env('AUTOMATION_ASANA_TO_GCAL_ENABLED', false),
            'tenant_id' => (int) env('AUTOMATION_ASANA_TENANT_ID', 0),
            'required_module' => 'workflow_automations',
            'driver' => 'asana_google_calendar',
            'trigger' => [
                'project_gid' => env('AUTOMATION_ASANA_PROJECT_GID'),
                'modified_overlap_minutes' => (int) env('AUTOMATION_ASANA_MODIFIED_OVERLAP_MINUTES', 5),
                'bootstrap_lookback_days' => (int) env('AUTOMATION_ASANA_BOOTSTRAP_LOOKBACK_DAYS', 14),
                'poll_limit' => (int) env('AUTOMATION_ASANA_POLL_LIMIT', 100),
                'max_tasks_per_run' => (int) env('AUTOMATION_ASANA_MAX_TASKS_PER_RUN', 500),
            ],
            'action' => [
                'calendar_id' => env('AUTOMATION_GCAL_CALENDAR_ID'),
                'timezone' => env('AUTOMATION_GCAL_TIMEZONE', 'America/New_York'),
                'default_start_time' => env('AUTOMATION_GCAL_DEFAULT_START_TIME', '12:00:00'),
                'default_duration_minutes' => (int) env('AUTOMATION_GCAL_DEFAULT_DURATION_MINUTES', 60),
                'skip_completed_tasks' => (bool) env('AUTOMATION_GCAL_SKIP_COMPLETED_TASKS', true),
            ],
        ],
    ],
];
