<?php

namespace App\Services\Automation;

class WorkflowTemplateCatalog
{
    public function __construct(protected CalendarEventPresentationService $calendarPresentation) {}

    /** @return array<string,array<string,mixed>> */
    public function templates(): array
    {
        return array_filter((array) config('automation_workflows.templates', []), 'is_array');
    }

    /** @return array<string,mixed> */
    public function template(string $key): array
    {
        $template = $this->templates()[strtolower(trim($key))] ?? null;
        if (! is_array($template)) {
            throw new AutomationWorkflowException("Workflow template [{$key}] is not supported.");
        }

        return $template;
    }

    /** @return array<string,array<string,mixed>> */
    public function providers(): array
    {
        return array_filter((array) config('automation_workflows.providers', []), 'is_array');
    }

    /** @return array<string,mixed> */
    public function defaultDefinition(string $templateKey): array
    {
        $template = $this->template($templateKey);
        $sourceProvider = (string) $template['trigger_provider'];
        $commerceProvider = in_array($sourceProvider, ['shopify', 'square', 'squarespace', 'wix'], true);

        return [
            'template_key' => $templateKey,
            'driver' => $template['driver'] ?? null,
            'trigger' => [
                'provider' => $template['trigger_provider'],
                'event' => $template['trigger_event'],
                'project_gid' => null,
                'connection_id' => null,
                'location_ids' => [],
                'modified_overlap_minutes' => 5,
                'bootstrap_lookback_days' => 14,
                'poll_limit' => 100,
                'max_tasks_per_run' => 500,
                'schedule_source' => $sourceProvider === 'shopify' ? 'order_created' : ($commerceProvider ? 'fulfillment' : 'source_date'),
            ],
            'action' => [
                'provider' => $template['action_provider'],
                'event' => $template['action_event'],
                'calendar_id' => null,
                'timezone' => (string) config('app.timezone', 'UTC'),
                'default_duration_minutes' => 60,
                'default_start_time' => '09:00',
                'event_time_mode' => 'source_time',
                'schedule_offset_days' => 0,
                'skip_completed_tasks' => true,
                'date_only_mode' => 'all_day',
                'presentation' => $this->calendarPresentation->defaults($sourceProvider),
            ],
        ];
    }
}
