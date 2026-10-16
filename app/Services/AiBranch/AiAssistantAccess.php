<?php

namespace App\Services\AiBranch;

use App\Models\Tenant;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiAssistantAccess
{
    public function authorize(Tenant $tenant, User $user): void
    {
        abort_unless(config('ai_branch.assistant_enabled') && in_array((int) $tenant->id, config('ai_branch.assistant_tenant_ids', []), true), 404);
        abort_unless($user->hasVerifiedEmail(), 403);
        app(AiBranchAccess::class)->authorize($tenant, $user);
    }

    public function allows(Tenant $tenant, User $user): bool
    {
        try {
            $this->authorize($tenant, $user);

            return true;
        } catch (HttpException) {
            return false;
        }
    }
}
