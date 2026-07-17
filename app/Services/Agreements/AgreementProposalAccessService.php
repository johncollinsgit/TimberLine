<?php

namespace App\Services\Agreements;

use App\Models\Agreement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AgreementProposalAccessService
{
    public function __construct(protected AgreementEventRecorder $events) {}

    public function resolve(string $token): Agreement
    {
        $agreement = Agreement::query()->with(['tenant', 'currentVersion', 'acceptance', 'billingOrders.receipts'])->where('public_token_hash', hash('sha256', $token))->firstOrFail();
        abort_if($agreement->access_revoked_at !== null, 410, 'This proposal link has been revoked.');
        abort_if($agreement->access_expires_at !== null && $agreement->access_expires_at->isPast(), 410, 'This proposal link has expired.');
        abort_if(! in_array($agreement->status, ['sent', 'viewed', 'accepted', 'active'], true), 404);

        return $agreement;
    }

    public function isUnlocked(Request $request, Agreement $agreement): bool
    {
        return hash_equals((string) $request->session()->get($this->sessionKey($agreement), ''), (string) $agreement->currentVersion?->content_hash);
    }

    public function unlock(Request $request, Agreement $agreement, string $password): bool
    {
        if (! Hash::check($password, (string) $agreement->password_hash)) {
            $this->events->record($agreement, 'password_failed', null, ['ip_hash' => hash('sha256', (string) $request->ip())]);

            return false;
        }

        $request->session()->put($this->sessionKey($agreement), (string) $agreement->currentVersion?->content_hash);
        $now = now();
        $agreement->forceFill([
            'status' => $agreement->status === 'sent' ? 'viewed' : $agreement->status,
            'first_viewed_at' => $agreement->first_viewed_at ?: $now,
            'last_viewed_at' => $now,
            'view_count' => ((int) $agreement->view_count) + 1,
        ])->save();
        $this->events->record($agreement, 'viewed', null, ['view_count' => $agreement->view_count]);

        return true;
    }

    protected function sessionKey(Agreement $agreement): string
    {
        return 'agreement_proposal_unlocked.'.(int) $agreement->id;
    }
}
