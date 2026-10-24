<?php

namespace App\Proposals;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Fresh real-owner checks for private erasure only; never substitutes a User or broadens Access. */
final class ReviewerWorkspaceMaintenanceAuthority
{
    public function __construct(private Access $owners, private ReviewerWorkspaceStore $workspace) {}

    public function assertOwner(Request $request, array $expectedContext): void
    {
        $owner = $this->owners->owner($request);
        $context = $this->workspace->context(false);
        abort_unless((int) $owner->id === (int) $context['tenant']->id
            && (int) $owner->id === (int) $expectedContext['tenant']->id
            && hash_equals($expectedContext['context_hash'], $context['context_hash']), 404);
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $users = User::query()->whereKey($actor->getKey());
        $memberships = DB::table('tenant_user')->where('tenant_id', $owner->id)->where('user_id', $actor->getKey());
        if (DB::transactionLevel() > 0) {
            // Locking reads see current committed authority even when an earlier
            // nonlocking context read established a MySQL repeatable-read snapshot.
            $users->lockForUpdate();
            $memberships->lockForUpdate();
        }
        $user = $users->first();
        $membership = $memberships->first();
        abort_unless($user !== null && $user->is_active === true && $user->email_verified_at !== null
            && $membership !== null && in_array($membership->role, ['owner', 'admin'], true)
            && in_array($membership->membership_active, [true, 1, '1'], true), 403);
    }
}
