<?php

use App\Services\Automation\Drivers\AsanaGoogleCalendarWorkflowDriver;

return [
    'enabled' => (bool) env('AUTOMATION_WORKFLOWS_ENABLED', false),
    'oauth_state_cache_store' => env('AUTOMATION_OAUTH_STATE_CACHE_STORE', env('CACHE_STORE', 'file')),

    'drivers' => [
        'asana_google_calendar' => AsanaGoogleCalendarWorkflowDriver::class,
    ],

    'providers' => [
        'asana' => ['label' => 'Asana', 'accent' => 'rose', 'initials' => 'A', 'state' => 'live'],
        'google_calendar' => ['label' => 'Google Calendar', 'accent' => 'sky', 'initials' => '31', 'state' => 'live'],
        'shopify' => ['label' => 'Shopify', 'accent' => 'emerald', 'initials' => 'S', 'state' => 'beta'],
        'square' => ['label' => 'Square', 'accent' => 'zinc', 'initials' => 'Sq', 'state' => 'beta'],
        'squarespace' => ['label' => 'Squarespace', 'accent' => 'zinc', 'initials' => 'Ss', 'state' => 'connector_required'],
        'wix' => ['label' => 'Wix', 'accent' => 'violet', 'initials' => 'W', 'state' => 'connector_required'],
    ],

    'templates' => [
        'asana_to_google_calendar' => [
            'name' => 'Asana tasks to Google Calendar',
            'description' => 'Create and update calendar events from dated tasks in one Asana project.',
            'trigger_provider' => 'asana',
            'trigger_event' => 'New or updated dated task',
            'action_provider' => 'google_calendar',
            'action_event' => 'Create or update event',
            'driver' => 'asana_google_calendar',
            'launchable' => true,
        ],
        'shopify_order_to_google_calendar' => [
            'name' => 'Shopify orders to Google Calendar',
            'description' => 'Place new Shopify orders on a fulfillment calendar.',
            'trigger_provider' => 'shopify',
            'trigger_event' => 'New or updated order',
            'action_provider' => 'google_calendar',
            'action_event' => 'Create or update event',
            'launchable' => false,
        ],
        'square_order_to_google_calendar' => [
            'name' => 'Square orders to Google Calendar',
            'description' => 'Place completed Square orders on an operations calendar.',
            'trigger_provider' => 'square',
            'trigger_event' => 'New or updated order',
            'action_provider' => 'google_calendar',
            'action_event' => 'Create or update event',
            'launchable' => false,
        ],
        'squarespace_order_to_google_calendar' => [
            'name' => 'Squarespace orders to Google Calendar',
            'description' => 'Send Squarespace commerce orders to a team calendar.',
            'trigger_provider' => 'squarespace',
            'trigger_event' => 'New or updated order',
            'action_provider' => 'google_calendar',
            'action_event' => 'Create or update event',
            'launchable' => false,
        ],
        'wix_order_to_google_calendar' => [
            'name' => 'Wix orders to Google Calendar',
            'description' => 'Send Wix store orders to a team calendar.',
            'trigger_provider' => 'wix',
            'trigger_event' => 'New or updated order',
            'action_provider' => 'google_calendar',
            'action_event' => 'Create or update event',
            'launchable' => false,
        ],
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
