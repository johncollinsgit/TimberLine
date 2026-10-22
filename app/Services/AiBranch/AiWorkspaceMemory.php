<?php

namespace App\Services\AiBranch;

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit workspace knowledge, separate from each person's private memory. */
class AiWorkspaceMemory
{
    public function listing(Tenant $tenant, User $user): array
    {
        app(AiWorkspaceWorkflows::class)->authorize($tenant, $user);
        $cipher = data_get(TenantAccessProfile::where('tenant_id', $tenant->id)->value('metadata'), 'ai_branch.workspace_knowledge');

        return $cipher ? json_decode(Crypt::decryptString($cipher), true, 16, JSON_THROW_ON_ERROR) : [];
    }

    public function change(Tenant $tenant, User $user, ?string $body, ?string $id = null): array
    {
        return DB::transaction(function () use ($tenant, $user, $body, $id) {
            $profile = TenantAccessProfile::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $entries = $this->listing($tenant, $user);
            // Sharing knowledge does not widen the existing owner/admin audience.
            if ($id !== null) {
                abort_unless(Str::isUuid($id) && collect($entries)->contains('id', $id), 404);
                $entries = array_values(array_filter($entries, fn ($entry) => $entry['id'] !== $id));
            } else {
                abort_unless(is_string($body) && trim($body) !== '' && mb_strlen($body) <= 500, 422);
                abort_if(count($entries) >= 20, 422, 'Remove a workspace memory before adding another.');
                $entries[] = ['id' => (string) Str::uuid(), 'body' => trim($body), 'created_by' => $user->id, 'created_at' => now()->toIso8601String()];
            }
            $metadata = (array) $profile->metadata;
            data_set($metadata, 'ai_branch.workspace_knowledge', Crypt::encryptString(json_encode($entries, JSON_THROW_ON_ERROR)));
            $profile->forceFill(['metadata' => $metadata])->save();
            app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $user->id, 'ai_branch.workspace_memory_'.($id ? 'remove' : 'create'),
                targetType: 'workspace_memory', targetId: $id ?? end($entries)['id'], confirmation: ['explicit' => true], context: ['billing_impact' => 'None; shared workspace knowledge only.']);

            return $entries;
        }, 3);
    }
}
