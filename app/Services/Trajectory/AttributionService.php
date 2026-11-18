<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Allocation;
use App\Models\Trajectory\AllocationDetail;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Economic detail never replaces the original bank row or invents reimbursement cash. */
class AttributionService
{
    public function available(): bool
    {
        // Older durable recovery steps and rolling code upgrades may have only
        // the original ledger schema. Do not cache a pre-migration false result.
        return Schema::hasTable('trajectory_allocation_details');
    }

    public function hasActiveDetails(Transaction $transaction): bool
    {
        return $this->available() && AllocationDetail::where('transaction_id', $transaction->id)->where('active', true)->exists();
    }

    public function categoryDefinitions(Space $space): array
    {
        return Record::where('space_id', $space->id)->where('kind', 'category_definition')->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id, 'key' => $r->data['key'], 'name' => $r->name, 'parent_record_id' => $r->data['parent_record_id'] ?? null,
            'direction' => $r->data['direction'], 'version' => $r->version, 'active' => $r->active,
        ])->all();
    }

    public function programs(Space $space): array
    {
        return Record::where('space_id', $space->id)->where('kind', 'program')->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id, 'key' => $r->data['key'], 'name' => $r->name, 'version' => $r->version, 'active' => $r->active,
        ])->all();
    }

    public function saveDefinition(User $user, Space $space, string $kind, string $name, array $data, ?Record $record = null, ?int $version = null): Record
    {
        app(FinanceAccess::class)->authorize($user, $space);
        abort_unless($this->available(), 503, 'Economic attribution is not available until its schema upgrade completes.');
        abort_unless(in_array($kind, ['category_definition', 'program'], true), 422);
        $validated = Validator::make(['name' => $name, ...$data], [
            'name' => 'required|string|max:160', 'key' => 'required|string|regex:/^[a-z][a-z0-9_]{0,39}$/',
            'parent_record_id' => 'nullable|integer|min:1', 'direction' => ['required_if:kind,category_definition', Rule::in(['expense', 'income'])],
            'active' => 'sometimes|boolean',
        ])->validate();
        abort_if($kind === 'category_definition' && ! isset($validated['direction']), 422, 'Choose an income or expense category.');

        return DB::transaction(function () use ($user, $space, $kind, $validated, $record, $version) {
            $space = Space::whereKey($space->id)->lockForUpdate()->firstOrFail();
            app(FinanceAccess::class)->authorize($user, $space);
            $records = Record::where('space_id', $space->id)->whereIn('kind', ['category_definition', 'program'])->orderBy('id')->lockForUpdate()->get();
            if ($record) {
                $record = $records->firstWhere('id', $record->id);
                abort_unless($record && $record->kind === $kind && $record->version === $version, 409, 'Definition changed; refresh before saving.');
                abort_unless($record->data['key'] === $validated['key'], 422, 'The category/program key is permanent. Rename its label instead.');
            }
            abort_if($records->contains(fn ($r) => $r->kind === $kind && $r->id !== $record?->id && ($r->data['key'] ?? null) === $validated['key']), 422, 'This key already exists.');
            $parent = null;
            if ($kind === 'category_definition' && ! empty($validated['parent_record_id'])) {
                $parent = $records->firstWhere('id', $validated['parent_record_id']);
                abort_unless($parent && $parent->kind === $kind && $parent->active && empty($parent->data['parent_record_id']) && $parent->id !== $record?->id
                    && $parent->data['direction'] === $validated['direction'], 422, 'Choose an active same-direction top-level category in this space.');
            }
            if ($record && (($validated['parent_record_id'] ?? null) !== ($record->data['parent_record_id'] ?? null) || ($validated['direction'] ?? null) !== ($record->data['direction'] ?? null))) {
                abort_if(AllocationDetail::where('subcategory_record_id', $record->id)->orWhere('category', $record->data['key'])->exists(), 422, 'Used category structure is retained for historical detail; create a new definition.');
                abort_if($records->contains(fn ($r) => ($r->data['parent_record_id'] ?? null) === $record->id), 422, 'A category with children must remain top level.');
            }
            $before = $record?->only(['name', 'data', 'active', 'version']);
            $values = ['space_id' => $space->id, 'kind' => $kind, 'name' => $validated['name'], 'active' => $validated['active'] ?? true,
                'data' => $kind === 'program' ? ['key' => $validated['key']] : ['key' => $validated['key'], 'direction' => $validated['direction'], 'parent_record_id' => $parent?->id],
                'version' => $record ? $record->version + 1 : 1];
            if ($record) {
                $record->update($values);
            } else {
                $record = Record::create($values);
            }
            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'record_id' => $record->id, 'action' => 'save_'.$kind, 'before' => $before, 'after' => $record->only(['name', 'data', 'active', 'version'])]);

            return $record;
        });
    }

    public function describe(User $user, Space $source, Transaction $tx): array
    {
        app(FinanceAccess::class)->authorize($user, $source);
        abort_unless($tx->space_id === $source->id, 404);
        abort_unless($this->available(), 503, 'Economic attribution is not available until its schema upgrade completes.');
        $allocations = Allocation::where('transaction_id', $tx->id)->orderBy('space_id')->get();
        foreach ($allocations->where('amount_cents', '!=', 0) as $allocation) {
            app(FinanceAccess::class)->authorize($user, Space::findOrFail($allocation->space_id));
        }
        $details = AllocationDetail::where('transaction_id', $tx->id)->where('active', true)->orderBy('line_index')->get();
        $valid = $details->isNotEmpty() && $details->every(fn ($detail) => $detail->source_fingerprint === $this->fingerprint($tx));
        $lines = $valid ? $details->map(fn ($detail) => ['detail_id' => $detail->id, ...$this->line($detail)])->all() : $allocations->where('amount_cents', '!=', 0)->map(fn ($a) => [
            'space_id' => $a->space_id, 'amount_cents' => $a->amount_cents, 'category' => $tx->category,
            'subcategory_record_id' => null, 'program_record_id' => null, 'settlement_status' => 'none', 'settlement_note' => null,
        ])->values()->all();
        $targets = app(FinanceAccess::class)->spaces($user)->filter(function (Space $target) use ($user, $source): bool {
            try {
                $this->authorizeTarget($user, $source, $target);

                return true;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                return false;
            }
        })->map(fn ($target) => $target->only(['id', 'name', 'kind']))->values()->all();

        return ['transaction_id' => $tx->id, 'version' => $tx->version, 'source_space_id' => $source->id, 'source_amount_cents' => $tx->amount_cents,
            'flow' => $tx->flow, 'lines' => $lines, 'target_spaces' => $targets, 'attribution_status' => $valid ? 'reviewed' : ($details->isEmpty() ? 'none' : 'needs_review')];
    }

    public function save(User $user, Space $source, Transaction $tx, array $lines, int $version): array
    {
        app(FinanceAccess::class)->authorize($user, $source);
        abort_unless($tx->space_id === $source->id, 404);
        abort_unless($this->available(), 503, 'Economic attribution is not available until its schema upgrade completes.');
        $data = Validator::make(['lines' => $lines], [
            'lines' => 'required|array|min:1|max:50', 'lines.*.space_id' => 'required|integer|min:1',
            'lines.*' => 'array:space_id,amount_cents,category,subcategory_record_id,program_record_id,settlement_status,settlement_note,detail_id',
            'lines.*.detail_id' => 'nullable|integer|min:1',
            'lines.*.amount_cents' => 'required|integer|between:-100000000000,100000000000', 'lines.*.category' => 'required|string|max:40',
            'lines.*.subcategory_record_id' => 'nullable|integer|min:1', 'lines.*.program_record_id' => 'nullable|integer|min:1',
            'lines.*.settlement_status' => ['sometimes', Rule::in(['none', 'open', 'reimbursed'])], 'lines.*.settlement_note' => 'nullable|string|max:1000',
        ])->validate();

        return DB::transaction(function () use ($user, $source, $tx, $data, $version) {
            $existingSpaces = Allocation::where('transaction_id', $tx->id)->pluck('space_id')->all();
            $spaceIds = array_values(array_unique([$source->id, ...$existingSpaces, ...array_column($data['lines'], 'space_id')]));
            $spaces = Space::whereIn('id', $spaceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($spaces->count() === count($spaceIds), 422, 'Unknown finance space.');
            $source = $spaces[$source->id];
            foreach ($spaces as $target) {
                app(FinanceAccess::class)->authorize($user, $target);
                $this->authorizeTarget($user, $source, $target);
            }
            $tx = Transaction::where('space_id', $source->id)->whereKey($tx->id)->lockForUpdate()->firstOrFail();
            abort_unless($tx->version === $version, 409, 'Transaction changed; refresh before saving.');
            abort_if($tx->removed || $tx->amount_cents === 0 || $tx->posted_on->toDateString() > now($source->timezone)->toDateString(), 422, 'Use an actual nonzero bank transaction.');
            abort_unless(in_array($tx->flow, ['expense', 'refund', 'income', 'owner_wages', 'reimbursement'], true), 422, 'Classify financial purpose before assigning economic detail.');
            app(MedicalSharingService::class)->assertUnbound($tx);
            abort_unless(array_sum(array_column($data['lines'], 'amount_cents')) === $tx->amount_cents, 422, 'Detail lines must total the original bank amount.');
            $lines = [];
            foreach ($data['lines'] as $line) {
                abort_unless($tx->amount_cents < 0 ? $line['amount_cents'] < 0 : $line['amount_cents'] > 0, 422, 'Detail lines must have the bank transaction’s sign.');
                $target = $spaces[$line['space_id']];
                $line = $this->validateDimensions($target, $line, $tx->flow);
                $line['settlement_status'] ??= 'none';
                $line['settlement_note'] ??= null;
                abort_if($tx->pending && $line['settlement_status'] !== 'none', 422, 'Pending purchases cannot confirm reimbursement or settlement.');
                abort_if($line['settlement_status'] !== 'none' && ($target->id === $source->id || $tx->amount_cents > 0), 422, 'Settlement notes apply to costs paid for a different finance space.');
                abort_if($line['settlement_status'] === 'reimbursed' && blank($line['settlement_note']), 422, 'Record why this cost is confirmed reimbursed.');
                $lines[] = $line;
            }
            $before = $this->snapshot($tx);
            $result = $this->replace($tx, $lines);
            $event = Event::create(['space_id' => $source->id, 'actor_id' => $user->id, 'action' => 'attribution', 'record_id' => $tx->id,
                'before' => $before, 'after' => ['version' => $tx->version, 'lines' => $lines, 'source_fingerprint' => $this->fingerprint($tx),
                    'cash_changed' => false, 'settlement_is_owner_annotation' => true]]);

            return [...$result, 'event_id' => $event->id, 'cash_changed' => false];
        });
    }

    public function undo(User $user, Space $source, Transaction $tx, int $version, int $eventId): array
    {
        app(FinanceAccess::class)->authorize($user, $source);
        abort_unless($this->available(), 503, 'Economic attribution is not available until its schema upgrade completes.');

        return DB::transaction(function () use ($user, $source, $tx, $version, $eventId) {
            $event = Event::where('space_id', $source->id)->where('record_id', $tx->id)->where('action', 'attribution')->findOrFail($eventId);
            $before = $event->before;
            $ids = array_values(array_unique([$source->id, ...array_column($before['allocations'], 'space_id'), ...Allocation::where('transaction_id', $tx->id)->pluck('space_id')->all()]));
            $spaces = Space::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($spaces as $space) {
                app(FinanceAccess::class)->authorize($user, $space);
            }
            $tx = Transaction::where('space_id', $source->id)->whereKey($tx->id)->lockForUpdate()->firstOrFail();
            abort_unless($tx->version === $version && $version === $event->after['version'] && $this->fingerprint($tx) === $before['source_fingerprint'], 409, 'The source changed after this attribution.');
            abort_if(Event::where('space_id', $source->id)->where('record_id', $tx->id)->where('action', 'attribution_undo')->get()->contains(fn ($e) => ($e->after['undone_event_id'] ?? null) === $eventId), 409);
            $current = $this->snapshot($tx);
            $this->invalidate($tx, 'owner_undo');
            $amounts = array_column($before['allocations'], 'amount_cents', 'space_id');
            foreach (Allocation::where('transaction_id', $tx->id)->get() as $allocation) {
                $allocation->update(['amount_cents' => $amounts[$allocation->space_id] ?? 0]);
            }
            foreach ($before['allocations'] as $allocation) {
                Allocation::updateOrCreate(['transaction_id' => $tx->id, 'space_id' => $allocation['space_id']], ['amount_cents' => $allocation['amount_cents']]);
            }
            $tx->increment('version');
            $tx->update(['reviewed' => $before['reviewed'], 'explanation' => $before['explanation']]);
            $this->writeDetails($tx, $before['lines']);
            Event::create(['space_id' => $source->id, 'actor_id' => $user->id, 'record_id' => $tx->id, 'action' => 'attribution_undo', 'before' => $current,
                'after' => ['version' => $tx->version, 'undone_event_id' => $eventId, 'cash_changed' => false]]);

            return ['transaction_id' => $tx->id, 'version' => $tx->version, 'undone_event_id' => $eventId, 'cash_changed' => false];
        });
    }

    /** Retain invalidated detail history; an amended bank source requires a fresh review. */
    public function invalidate(Transaction $tx, string $reason): int
    {
        if (! $this->available()) {
            return 0;
        }

        return AllocationDetail::where('transaction_id', $tx->id)->where('active', true)->update(['active' => false, 'invalidated_at' => now(), 'invalidation_reason' => $reason]);
    }

    /** Called only after LedgerService proves a stable provider pending-to-posted identity. */
    public function promotePending(Transaction $tx, string $previousFingerprint): void
    {
        if (! $this->available()) {
            return;
        }
        $rows = AllocationDetail::where('transaction_id', $tx->id)->where('active', true)->get();
        if ($rows->isEmpty()) {
            return;
        }
        if (! $rows->every(fn ($row) => $row->source_fingerprint === $previousFingerprint)) {
            $this->invalidate($tx, 'pending_source_changed');
            $tx->reviewed = false;

            return;
        }
        AllocationDetail::whereIn('id', $rows->pluck('id'))->update(['source_fingerprint' => $this->fingerprint($tx)]);
        Event::create(['space_id' => $tx->space_id, 'record_id' => $tx->id, 'action' => 'attribution_pending_posted',
            'before' => ['source_fingerprint' => $previousFingerprint],
            'after' => ['source_fingerprint' => $this->fingerprint($tx), 'detail_ids' => $rows->pluck('id')->all(), 'cash_invented' => false]]);
    }

    public function fingerprint(Transaction $tx): string
    {
        return hash('sha256', json_encode([$tx->space_id, $tx->account_id, $tx->source_key, $tx->posted_on->toDateString(), $tx->amount_cents, $tx->flow], JSON_THROW_ON_ERROR));
    }

    public function decorateEntries(Space $space, array $entries): array
    {
        if (! $this->available()) {
            return array_map(fn ($entry) => [...$entry, 'allocation_details' => [], 'attribution_status' => 'unavailable'], $entries);
        }
        $details = AllocationDetail::where('space_id', $space->id)->whereIn('transaction_id', array_column($entries, 'id'))->where('active', true)->orderBy('line_index')->get()->groupBy('transaction_id');
        $sources = Transaction::whereIn('id', $details->keys())->get()->keyBy('id');
        foreach ($entries as &$entry) {
            $rows = $details->get($entry['id'], collect());
            $valid = $rows->isNotEmpty() && $sources->has($entry['id']) && $rows->every(fn ($r) => $r->source_fingerprint === $this->fingerprint($sources[$entry['id']]))
                && $rows->sum('amount_cents') === $entry['amount_cents'];
            $entry['allocation_details'] = $valid ? $rows->map(fn ($r) => ['id' => $r->id, 'allocation_id' => $r->allocation_id, ...$this->line($r, ($entry['editable'] ?? false) === true)])->all() : [];
            $entry['attribution_status'] = $valid ? 'reviewed' : ($rows->isEmpty() ? 'none' : 'needs_review');
            if ($rows->isNotEmpty() && ! $valid) {
                $entry['reviewed'] = false;
            }
        }

        return $entries;
    }

    /** Opt-in economic projection: cash views must continue using original owned bank rows. */
    public function expandEntries(Space $space, array $entries, ?int $programId = null, ?User $viewer = null): array
    {
        $entries = $this->decorateEntries($space, $entries);
        $categories = collect($this->categoryDefinitions($space))->keyBy('id');
        $sources = Transaction::whereIn('id', array_column($entries, 'id'))->get(['id', 'space_id'])->keyBy('id');
        $spaces = Space::whereIn('id', $sources->pluck('space_id'))->get()->keyBy('id');
        $access = app(FinanceAccess::class);
        $expanded = [];
        foreach ($entries as $entry) {
            $source = $spaces->get($sources->get($entry['id'])?->space_id);
            $canAttribute = $viewer && $source && $access->allows($viewer, $space) && $access->allows($viewer, $source);
            $entry['source_space_id'] = ($entry['editable'] ?? false) ? $space->id : ($canAttribute ? $source->id : null);
            $entry['attribution_editable'] = (bool) $canAttribute;
            if (empty($entry['allocation_details'])) {
                if ($programId === null) {
                    $expanded[] = [...$entry, 'entry_key' => 'transaction:'.$entry['id'], 'source_transaction_id' => $entry['id'], 'program_record_id' => null, 'subcategory_record_id' => null, 'attribution_detail_id' => null];
                }

                continue;
            }
            foreach ($entry['allocation_details'] as $detail) {
                if ($programId !== null && $detail['program_record_id'] !== $programId) {
                    continue;
                }
                $leaf = $detail['subcategory_record_id'] ? $categories->get($detail['subcategory_record_id']) : null;
                $expanded[] = [...$entry, 'entry_key' => 'detail:'.$detail['id'], 'source_transaction_id' => $entry['id'], 'attribution_detail_id' => $detail['id'],
                    'allocation_id' => $detail['allocation_id'], 'amount_cents' => $detail['amount_cents'],
                    'parent_category' => $detail['category'], 'category' => $leaf['key'] ?? $detail['category'],
                    'program_record_id' => $detail['program_record_id'], 'subcategory_record_id' => $detail['subcategory_record_id'], 'settlement_status' => $detail['settlement_status']];
            }
        }

        return $expanded;
    }

    private function validateDimensions(Space $target, array $line, string $flow): array
    {
        $records = Record::where('space_id', $target->id)->where('active', true)->whereIn('kind', ['category_definition', 'program'])->get()->keyBy('id');
        abort_unless(in_array($line['category'], app(WorkspaceService::class)->categories($target), true), 422, 'Choose a category belonging to the destination space.');
        $line['subcategory_record_id'] ??= null;
        $line['program_record_id'] ??= null;
        $selectedDefinition = $records->first(fn ($r) => $r->kind === 'category_definition' && $r->data['key'] === $line['category']);
        if (! empty($selectedDefinition?->data['parent_record_id'])) {
            $parentDefinition = $records->get($selectedDefinition->data['parent_record_id']);
            abort_unless($parentDefinition?->kind === 'category_definition' && ($line['subcategory_record_id'] === null || $line['subcategory_record_id'] === $selectedDefinition->id), 422, 'The selected child category needs its active parent.');
            $line['category'] = $parentDefinition->data['key'];
            $line['subcategory_record_id'] = $selectedDefinition->id;
        }
        if ($line['program_record_id']) {
            abort_unless(($records->get($line['program_record_id'])?->kind) === 'program', 422, 'Choose an active program in the destination space.');
        }
        if ($line['subcategory_record_id']) {
            $subcategory = $records->get($line['subcategory_record_id']);
            $parent = $subcategory ? $records->get($subcategory->data['parent_record_id'] ?? null) : null;
            abort_unless($subcategory?->kind === 'category_definition' && $parent?->kind === 'category_definition' && $parent->data['key'] === $line['category'], 422, 'Choose a child of the selected category in the destination space.');
        }
        $definition = $records->first(fn ($r) => $r->kind === 'category_definition' && $r->data['key'] === $line['category'] && empty($r->data['parent_record_id']));
        if ($definition) {
            abort_unless($definition->data['direction'] === (in_array($flow, ['income', 'owner_wages'], true) ? 'income' : 'expense'), 422, 'The category direction must match the transaction purpose.');
        }

        return $line;
    }

    private function authorizeTarget(User $user, Space $source, Space $target): void
    {
        if ($target->id === $source->id) {
            return;
        }
        $businessesOwned = $source->kind === 'business' && $target->kind === 'business' && $source->owner_user_id === $user->id && $target->owner_user_id === $user->id;
        $householdId = $source->kind === 'household' ? $source->id : $target->id;
        $businessId = $source->kind === 'business' ? $source->id : $target->id;
        $linked = $source->kind !== $target->kind && DB::table('trajectory_links')->where('household_id', $householdId)->where('business_id', $businessId)->exists();
        abort_unless($businessesOwned || $linked, 422, 'Use an explicitly linked household/business or businesses you own.');
        abort_unless($source->currency === $target->currency, 422, 'Cross-currency attribution is not supported.');
    }

    private function snapshot(Transaction $tx): array
    {
        return ['source_fingerprint' => $this->fingerprint($tx), 'version' => $tx->version, 'reviewed' => $tx->reviewed, 'explanation' => $tx->explanation,
            'allocations' => Allocation::where('transaction_id', $tx->id)->get()->map(fn ($a) => $a->only(['space_id', 'amount_cents']))->all(),
            'lines' => AllocationDetail::where('transaction_id', $tx->id)->where('active', true)->orderBy('line_index')->get()->map(fn ($r) => $this->line($r))->all()];
    }

    private function replace(Transaction $tx, array $lines): array
    {
        $this->invalidate($tx, 'replaced_by_owner');
        $totals = collect($lines)->groupBy('space_id')->map(fn ($rows) => (int) $rows->sum('amount_cents'));
        // Retain existing unique transaction/space rows even when their new share is zero.
        foreach (Allocation::where('transaction_id', $tx->id)->get() as $allocation) {
            $allocation->update(['amount_cents' => $totals->get($allocation->space_id, 0)]);
        }
        foreach ($totals as $spaceId => $amount) {
            Allocation::updateOrCreate(['transaction_id' => $tx->id, 'space_id' => $spaceId], ['amount_cents' => $amount]);
        }
        $tx->increment('version');
        $tx->update(['reviewed' => true]);
        $this->writeDetails($tx, $lines);

        return ['transaction_id' => $tx->id, 'version' => $tx->version, 'lines' => $lines];
    }

    private function writeDetails(Transaction $tx, array $lines): void
    {
        foreach ($lines as $index => $line) {
            $allocation = Allocation::where('transaction_id', $tx->id)->where('space_id', $line['space_id'])->firstOrFail();
            AllocationDetail::create(['transaction_id' => $tx->id, 'allocation_id' => $allocation->id, 'space_id' => $line['space_id'], 'revision' => $tx->version,
                'line_index' => $index, 'amount_cents' => $line['amount_cents'], 'category' => $line['category'], 'flow' => $tx->flow,
                'subcategory_record_id' => $line['subcategory_record_id'] ?? null, 'program_record_id' => $line['program_record_id'] ?? null,
                'settlement_status' => $line['settlement_status'] ?? 'none', 'settlement_note' => $line['settlement_note'] ?? null,
                'source_fingerprint' => $this->fingerprint($tx), 'active' => true]);
        }
    }

    private function line(AllocationDetail $detail, bool $includeNote = true): array
    {
        return ['space_id' => $detail->space_id, 'amount_cents' => $detail->amount_cents, 'category' => $detail->category,
            'subcategory_record_id' => $detail->subcategory_record_id, 'program_record_id' => $detail->program_record_id,
            'settlement_status' => $detail->settlement_status, 'settlement_note' => $includeNote ? $detail->settlement_note : null];
    }
}
