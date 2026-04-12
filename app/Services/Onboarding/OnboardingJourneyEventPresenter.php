<?php

namespace App\Services\Onboarding;

use Illuminate\Support\Str;

class OnboardingJourneyEventPresenter
{
    public function labelForEventKey(string $eventKey): string
    {
        $key = strtolower(trim($eventKey));

        return match ($key) {
            OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED => 'Onboarding handoff viewed',
            OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK => 'Onboarding first open acknowledged',
            OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED => 'Onboarding phase changed',
            OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED => 'Onboarding import started',
            OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED => 'Onboarding import completed',
            OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE => 'Onboarding first active module reached',
            default => 'Onboarding '.Str::headline($key),
        };
    }

    public function categoryForEventKey(string $eventKey): string
    {
        $key = strtolower(trim($eventKey));

        $milestones = [
            OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED,
            OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK,
            OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED,
            OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED,
            OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE,
        ];

        if (in_array($key, $milestones, true)) {
            return 'milestone';
        }

        if ($key === OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED) {
            return 'phase_change';
        }

        return 'other';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function payloadSummary(string $eventKey, array $payload): array
    {
        $key = strtolower(trim($eventKey));

        if ($key === OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED) {
            return [
                'from' => data_get($payload, 'from'),
                'to' => data_get($payload, 'to'),
                'payload_type' => data_get($payload, 'payload_type'),
            ];
        }

        if ($key === OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED || $key === OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED) {
            return [
                'import_state' => data_get($payload, 'import_state'),
                'is_stale' => data_get($payload, 'is_stale'),
                'payload_type' => data_get($payload, 'payload_type'),
            ];
        }

        if ($key === OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE) {
            return [
                'active_module_count' => data_get($payload, 'active_module_count'),
                'active_module_keys' => data_get($payload, 'active_module_keys'),
                'payload_type' => data_get($payload, 'payload_type'),
            ];
        }

        if ($key === OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK) {
            return [
                'payload_anchor' => data_get($payload, 'payload_anchor'),
                'opened_path' => data_get($payload, 'opened_path'),
                'provisioning_id' => data_get($payload, 'provisioning_id'),
            ];
        }

        if ($key === OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED) {
            return [
                'payload_type' => data_get($payload, 'payload_type'),
                'phase' => data_get($payload, 'phase'),
            ];
        }

        return $payload === [] ? [] : array_slice($payload, 0, 6, true);
    }

    /**
     * @param  array<string,mixed>  $payloadSummary
     * @return array<int,array{label:string,value:mixed}>
     */
    public function contextSummaryItems(string $eventKey, array $payloadSummary): array
    {
        $key = strtolower(trim($eventKey));
        $items = [];

        $push = static function (array &$items, string $label, mixed $value): void {
            if ($value === null) {
                return;
            }

            if (is_string($value) && trim($value) === '') {
                return;
            }

            if (is_array($value) && $value === []) {
                return;
            }

            $items[] = ['label' => $label, 'value' => $value];
        };

        if ($key === OnboardingJourneyTelemetryService::EVENT_PHASE_CHANGED) {
            $push($items, 'from', $payloadSummary['from'] ?? null);
            $push($items, 'to', $payloadSummary['to'] ?? null);
            $push($items, 'payload', $payloadSummary['payload_type'] ?? null);
        } elseif ($key === OnboardingJourneyTelemetryService::EVENT_FIRST_OPEN_ACK) {
            $push($items, 'anchor', $payloadSummary['payload_anchor'] ?? null);
            $push($items, 'path', $payloadSummary['opened_path'] ?? null);
        } elseif ($key === OnboardingJourneyTelemetryService::EVENT_HANDOFF_VIEWED) {
            $push($items, 'payload', $payloadSummary['payload_type'] ?? null);
            $push($items, 'phase', $payloadSummary['phase'] ?? null);
        } elseif ($key === OnboardingJourneyTelemetryService::EVENT_IMPORT_STARTED || $key === OnboardingJourneyTelemetryService::EVENT_IMPORT_COMPLETED) {
            $push($items, 'import', $payloadSummary['import_state'] ?? null);
            if (($payloadSummary['is_stale'] ?? null) !== null) {
                $push($items, 'stale', (bool) ($payloadSummary['is_stale'] ?? false) ? 'yes' : 'no');
            }
            $push($items, 'payload', $payloadSummary['payload_type'] ?? null);
        } elseif ($key === OnboardingJourneyTelemetryService::EVENT_FIRST_ACTIVE_MODULE) {
            $push($items, 'active', $payloadSummary['active_module_count'] ?? null);
        } else {
            foreach ($payloadSummary as $label => $value) {
                if (! is_string($label) || trim($label) === '') {
                    continue;
                }
                $push($items, $label, $value);
            }
        }

        if ($items === []) {
            return [['label' => 'note', 'value' => 'No additional context']];
        }

        return array_slice($items, 0, 6);
    }

    /**
     * @param  array<int,array{label:string,value:mixed}>  $contextItems
     */
    public function activityRelatedEntity(?int $finalBlueprintId, array $contextItems): string
    {
        $base = $finalBlueprintId !== null && $finalBlueprintId > 0
            ? 'Blueprint #'.$finalBlueprintId
            : 'Onboarding telemetry';

        $selected = [];
        foreach ($contextItems as $item) {
            $label = strtolower(trim((string) ($item['label'] ?? '')));
            if ($label === '' || $label === 'note') {
                continue;
            }

            if (! in_array($label, ['to', 'phase', 'anchor', 'path', 'import', 'active'], true)) {
                continue;
            }

            $value = $item['value'] ?? null;
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $selected[] = $label.': '.$value;

            if (count($selected) >= 2) {
                break;
            }
        }

        return $selected === [] ? $base : ($base.' · '.implode(' · ', $selected));
    }

    public function activityStatusForEventKey(string $eventKey): string
    {
        return match ($this->categoryForEventKey($eventKey)) {
            'milestone' => 'milestone',
            'phase_change' => 'phase',
            default => 'telemetry',
        };
    }
}

