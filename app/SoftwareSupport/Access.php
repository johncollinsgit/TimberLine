<?php

namespace App\SoftwareSupport;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class Access
{
    public function context(Request $request): array
    {
        abort_unless(config('software_support.enabled'), 404);
        $user = $request->user();
        abort_unless($user && $user->is_active, 403);
        $tenant = Tenant::where('slug', $request->route('workspace'))->firstOrFail();
        $membership = $user->tenants()->where('tenants.id', $tenant->id)->wherePivot('membership_active', true)->first();
        abort_unless($membership, 403);
        $ownerId = (int) config('software_support.owner_tenant_id');
        abort_unless($ownerId > 0 && Tenant::whereKey($ownerId)->exists(), 404);
        $owner = $tenant->id === $ownerId;
        if ($owner) {
            abort_unless(in_array((int) $user->id, config('software_support.owner_user_ids', []), true)
                && in_array($membership->pivot->role, ['owner', 'admin'], true), 403);
            $departments = [];
        } else {
            $departments = collect(config('software_support.clients.'.$tenant->id.'.departments', []))
                ->filter(fn ($department) => in_array((int) $user->id, $department['user_ids'] ?? [], true))->all();
            abort_unless($departments !== [], 403);
        }

        return compact('tenant', 'owner', 'ownerId', 'departments');
    }

    public function tickets(array $context): Builder
    {
        $query = Record::where('owner_tenant_id', $context['ownerId'])->where('kind', 'ticket');
        if ($context['owner']) {
            // Revoking a client binding immediately removes it from this queue.
            $clients = array_filter(config('software_support.clients', []), fn ($client) => is_array($client) && ! empty($client['departments']));
            $query->whereIn('client_tenant_id', array_map('intval', array_keys($clients)));
        } else {
            $query->where('client_tenant_id', $context['tenant']->id)->whereIn('department', array_keys($context['departments']));
        }

        return $query;
    }

    public function ticket(array $context, string $id): Record
    {
        return $this->tickets($context)->findOrFail($id);
    }
}
