<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class WorkspacePresentationService
{
    public function canEdit(?User $user): bool
    {
        $owner = Str::lower(trim((string) config('workspace_presentation.owner_email')));

        return $owner !== '' && $user instanceof User
            && $user->role === 'platform_admin'
            && Str::lower((string) $user->email) === $owner
            && Gate::forUser($user)->allows('manage-landlord-commercial');
    }

    public function forTenant(Tenant $tenant, string $fallback = 'auto'): array
    {
        if ($fallback === 'auto') {
            $experience = app(TenantExperienceProfileService::class)->forTenant((int) $tenant->id, null, $tenant);
            $fallback = ($experience['workspace_focus'] ?? '') === 'website_sales'
                ? 'retail' : (($experience['use_case_profile'] ?? '') === 'field_service' ? 'field' : 'generic');
        }
        $templates = (array) config('workspace_presentation.templates', []);
        $saved = (array) data_get($tenant->accessProfile?->metadata, 'workspace_presentation', []);
        $key = (string) ($saved['template'] ?? $fallback);
        $key = array_key_exists($key, $templates) ? $key : 'generic';

        return ['key' => $key, 'summary' => (string) ($saved['summary'] ?? ''), ...$templates[$key]];
    }

    public function save(Tenant $tenant, User $actor, string $template, ?string $summary): void
    {
        abort_unless($this->canEdit($actor), 403);
        abort_unless(array_key_exists($template, (array) config('workspace_presentation.templates')), 422);
        // The legacy flagship has a separate presentation contract.
        abort_if((string) $tenant->slug === (string) config('tenancy.auth.flagship_tenant_slug'), 422, 'This workspace keeps its existing presentation.');

        DB::transaction(function () use ($tenant, $actor, $template, $summary): void {
            $profile = TenantAccessProfile::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) $profile->metadata;
            $before = (array) ($metadata['workspace_presentation'] ?? []);
            $after = ['template' => $template, 'summary' => trim((string) $summary)];
            if ($before === $after) {
                return;
            }
            $metadata['workspace_presentation'] = $after;
            $profile->forceFill(['metadata' => $metadata])->save();
            app(LandlordOperatorActionAuditService::class)->record(
                tenantId: (int) $tenant->id,
                actorUserId: (int) $actor->id,
                actionType: 'workspace_presentation_updated',
                targetType: 'tenant_access_profile',
                targetId: $profile->id,
                context: ['billing_impact' => 'none', 'entitlement_changes' => false],
                beforeState: $before,
                afterState: $after,
            );
        });
    }
}
