<?php

namespace App\Services\Accounting;

use App\Models\AccountingComplianceTask;
use App\Models\AccountingProfile;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingSetupService
{
    public function applyPreset(Tenant $tenant, string $presetKey): AccountingProfile
    {
        $preset = config('accounting_command_center.presets.'.$presetKey);
        if (! is_array($preset)) {
            throw ValidationException::withMessages(['preset' => 'The accounting preset is not available.']);
        }

        return DB::transaction(function () use ($tenant, $presetKey, $preset): AccountingProfile {
            $profileData = (array) ($preset['profile'] ?? []);
            $profile = AccountingProfile::query()->updateOrCreate(
                ['tenant_id' => (int) $tenant->id],
                [
                    'preset_key' => $presetKey,
                    'entity_type' => $profileData['entity_type'] ?? null,
                    'country_code' => $profileData['country_code'] ?? 'US',
                    'state_code' => $profileData['state_code'] ?? null,
                    'tax_year_basis' => $profileData['tax_year_basis'] ?? 'calendar',
                    'accounting_basis' => $profileData['accounting_basis'] ?? 'accrual',
                    'setup_status' => 'needs_review',
                    'configuration' => (array) ($profileData['configuration'] ?? []),
                    'reviewed_at' => null,
                    'reviewed_by_user_id' => null,
                ]
            );

            foreach ((array) ($preset['compliance_tasks'] ?? []) as $task) {
                $destination = ($task['destination_key'] ?? null)
                    ? (array) config('accounting_command_center.destinations.'.$task['destination_key'], [])
                    : [];
                AccountingComplianceTask::query()->updateOrCreate(
                    [
                        'tenant_id' => (int) $tenant->id,
                        'task_key' => (string) $task['key'],
                        'period_key' => 'setup',
                    ],
                    [
                        'accounting_profile_id' => (int) $profile->id,
                        'name' => (string) $task['name'],
                        'explanation' => 'Confirm applicability, cadence, due date, provider responsibility, and evidence with the owner or accountant.',
                        'jurisdiction' => $task['jurisdiction'] ?? null,
                        'obligation' => $task['obligation'] ?? null,
                        'status' => 'needs_setup',
                        'destination_name' => $destination['name'] ?? null,
                        'destination_url' => $this->safeHttps($destination['url'] ?? null),
                        'quickbooks_expected' => (bool) ($task['quickbooks_expected'] ?? false),
                        'confidence' => 'unverified',
                        'metadata' => ['preset_key' => $presetKey, 'due_date_confirmed' => false],
                    ]
                );
            }

            return $profile->fresh('complianceTasks');
        });
    }

    protected function safeHttps(?string $url): ?string
    {
        $url = trim((string) $url);

        return filter_var($url, FILTER_VALIDATE_URL) && str_starts_with(strtolower($url), 'https://') ? $url : null;
    }
}
