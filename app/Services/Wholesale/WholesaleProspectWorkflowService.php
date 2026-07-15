<?php

namespace App\Services\Wholesale;

use App\Models\User;
use App\Models\WholesaleAccount;
use App\Models\WholesaleFollowUp;
use App\Models\WholesaleProspect;
use App\Models\WholesaleProspectEvidence;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WholesaleProspectWorkflowService
{
    /** @param array<string,mixed> $data */
    public function apply(WholesaleProspect $prospect, User $actor, array $data): WholesaleProspect
    {
        return DB::transaction(function () use ($prospect, $actor, $data): WholesaleProspect {
            $prospect = WholesaleProspect::query()->forAllTenants()->lockForUpdate()->findOrFail($prospect->id);
            $action = (string) $data['action'];
            $note = trim((string) ($data['note'] ?? ''));

            match ($action) {
                'qualify' => $prospect->forceFill(['status' => 'qualified', 'last_reviewed_at' => now()])->save(),
                'reject' => $this->reject($prospect, (string) ($data['rejection_reason'] ?? '')),
                'mark_duplicate' => $prospect->forceFill(['status' => 'duplicate', 'duplicate_status' => 'confirmed_duplicate', 'last_reviewed_at' => now()])->save(),
                'set_priority' => $prospect->forceFill(['opportunity_priority' => (string) $data['priority'], 'last_reviewed_at' => now()])->save(),
                'mark_do_not_contact' => $prospect->forceFill(['do_not_contact' => true, 'last_reviewed_at' => now()])->save(),
                'clear_do_not_contact' => $prospect->forceFill(['do_not_contact' => false, 'last_reviewed_at' => now()])->save(),
                'add_note', 'request_research' => $this->appendNote($prospect, $note, $action),
                'record_call', 'record_contact_attempt' => $this->recordContact($prospect, $note, $action),
                'schedule_follow_up' => $this->scheduleFollowUp($prospect, $actor, $data),
                'convert' => $this->convert($prospect, $actor),
                default => throw new DomainException('Unsupported prospect action.'),
            };

            $this->recordEvidence($prospect, $actor, $action, $note);

            return $prospect->fresh(['evidence', 'followUps', 'convertedAccount']);
        });
    }

    protected function reject(WholesaleProspect $prospect, string $reason): void
    {
        if (trim($reason) === '') {
            throw new DomainException('A rejection reason is required.');
        }
        $prospect->forceFill(['status' => 'rejected', 'rejection_reason' => trim($reason), 'last_reviewed_at' => now()])->save();
    }

    protected function appendNote(WholesaleProspect $prospect, string $note, string $action): void
    {
        if ($note === '') {
            throw new DomainException('A note is required for this action.');
        }
        $entry = '['.now()->toIso8601String().'] '.str_replace('_', ' ', $action).': '.$note;
        $prospect->forceFill([
            'notes' => trim(collect([$prospect->notes, $entry])->filter()->join("\n")),
            'status' => $action === 'request_research' ? 'research_requested' : $prospect->status,
            'last_reviewed_at' => now(),
        ])->save();
    }

    protected function recordContact(WholesaleProspect $prospect, string $note, string $action): void
    {
        if ($prospect->do_not_contact) {
            throw new DomainException('Contact activity cannot be recorded as a new action while this prospect is marked do not contact.');
        }
        $prospect->forceFill([
            'last_contact_at' => now(),
            'status' => $action === 'record_call' ? 'contacted' : 'outreach_attempted',
            'notes' => $note !== '' ? trim(collect([$prospect->notes, '['.now()->toIso8601String().'] '.$note])->filter()->join("\n")) : $prospect->notes,
        ])->save();
    }

    /** @param array<string,mixed> $data */
    protected function scheduleFollowUp(WholesaleProspect $prospect, User $actor, array $data): void
    {
        $dueAt = $data['due_at'] ?? null;
        if (! $dueAt) {
            throw new DomainException('A due date is required for a prospect follow-up.');
        }

        $open = WholesaleFollowUp::query()->forAllTenants()
            ->where('tenant_id', $prospect->tenant_id)
            ->where('target_type', 'prospect')
            ->where('target_key', $prospect->public_id)
            ->whereIn('status', ['open', 'in_progress'])
            ->first();
        if (! $open) {
            WholesaleFollowUp::query()->create([
                'tenant_id' => $prospect->tenant_id,
                'public_id' => (string) Str::uuid(),
                'target_type' => 'prospect',
                'target_key' => $prospect->public_id,
                'follow_up_type' => 'prospect_review',
                'title' => 'Review prospect: '.$prospect->business_name,
                'status' => 'open',
                'priority' => $data['priority'] ?? $prospect->opportunity_priority,
                'assigned_user_id' => $data['assigned_user_id'] ?? $prospect->assigned_owner_user_id,
                'created_by_user_id' => $actor->id,
                'due_at' => $dueAt,
                'notes' => $data['note'] ?? null,
            ]);
        }
        $prospect->forceFill(['next_action_at' => $dueAt, 'status' => $prospect->status === 'newly_discovered' ? 'review_scheduled' : $prospect->status])->save();
    }

    protected function convert(WholesaleProspect $prospect, User $actor): void
    {
        if ($prospect->converted_wholesale_account_id) {
            return;
        }
        if ($prospect->duplicate_status === 'possible_match' && ! $prospect->existing_customer_match) {
            throw new DomainException('Resolve the possible duplicate before converting this prospect.');
        }

        $canonicalKey = $prospect->existing_customer_match
            ? 'customer:'.$prospect->existing_customer_match
            : 'prospect:'.$prospect->public_id;
        $account = WholesaleAccount::query()->forAllTenants()->firstOrCreate([
            'tenant_id' => $prospect->tenant_id,
            'canonical_key' => $canonicalKey,
        ], [
            'public_id' => (string) Str::uuid(),
            'company_name' => $prospect->business_name,
            'phone' => $prospect->phone,
            'email' => $prospect->public_business_email,
            'website' => $prospect->website,
            'address' => $prospect->address,
            'city' => $prospect->city,
            'state' => $prospect->state,
            'postal_code' => $prospect->postal_code,
            'source_prospect_public_id' => $prospect->public_id,
            'existing_customer_key' => $prospect->existing_customer_match,
            'original_discovery_source' => $prospect->discovery_source,
            'conversion_snapshot' => [
                'fit_score' => $prospect->fit_score,
                'fit_explanation' => $prospect->fit_explanation,
                'evidence_ids' => $prospect->evidence()->pluck('id')->all(),
                'notes' => $prospect->notes,
            ],
            'confirmed_by_user_id' => $actor->id,
            'confirmed_at' => now(),
        ]);

        $prospect->forceFill([
            'status' => 'converted',
            'converted_wholesale_account_id' => $account->id,
            'converted_by_user_id' => $actor->id,
            'converted_at' => now(),
            'last_reviewed_at' => now(),
        ])->save();
        WholesaleFollowUp::query()->forAllTenants()
            ->where('tenant_id', $prospect->tenant_id)
            ->where('target_type', 'prospect')
            ->where('target_key', $prospect->public_id)
            ->whereIn('status', ['open', 'in_progress'])
            ->update(['status' => 'completed', 'completed_at' => now(), 'outcome' => 'Prospect converted to wholesale account.']);
    }

    protected function recordEvidence(WholesaleProspect $prospect, User $actor, string $action, string $note): void
    {
        WholesaleProspectEvidence::query()->create([
            'tenant_id' => $prospect->tenant_id,
            'wholesale_prospect_id' => $prospect->id,
            'source_type' => 'operator_action',
            'signal_type' => $action,
            'summary' => 'Authorized operator recorded '.str_replace('_', ' ', $action).($note !== '' ? ': '.$note : '.'),
            'supports_fit' => null,
            'observed_at' => now(),
            'source_reference' => ['actor_user_id' => $actor->id, 'recorded_at' => now()->toIso8601String()],
        ]);
    }
}
