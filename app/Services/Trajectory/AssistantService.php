<?php

namespace App\Services\Trajectory;

use App\Http\Controllers\Trajectory\TrajectoryController;
use App\Models\Trajectory\Account;
use App\Models\Trajectory\AssistantTurn;
use App\Models\Trajectory\Event;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Explicit owner instructions use deterministic versioned services, never model tools. */
class AssistantService
{
    public function respond(User $user, Space $space, string $question, array $context, string $requestId, bool $useAi = false): array
    {
        app(FinanceAccess::class)->authorize($user, $space);
        if (isset($context['budget_account_id'])) {
            abort_unless(Account::where('space_id', $space->id)->whereKey($context['budget_account_id'])->exists(), 403);
        }
        $hash = hash('sha256', json_encode([$space->id, $question, $context, $useAi], JSON_THROW_ON_ERROR));
        $turn = AssistantTurn::firstOrCreate(['user_id' => $user->id, 'request_id' => $requestId], [
            'space_id' => $space->id, 'request_hash' => $hash, 'question' => $question, 'context' => $context, 'state' => 'processing',
        ]);
        abort_unless($turn->space_id === $space->id && $turn->request_hash === $hash, 409, 'This message identifier belongs to another request.');
        if (! $turn->wasRecentlyCreated) {
            abort_unless($turn->response !== null, 409, 'This message is already being processed; it will not be sent twice.');

            return ['id' => $turn->id, ...$turn->response];
        }
        try {
            $asksForTransferMatch = preg_match('/\btransfers?\b/i', $question)
                && preg_match('/\b(?:match(?:ing)?|reconcile|look at|review)\b|^\s*transfers?[.!?]?\s*$/i', $question);
            if ($asksForTransferMatch) {
                $reply = ['reply' => 'Open transfer matches to compare the two bank entries and confirm the pair.', 'open_transfer_review' => true];
                $turn->update(['response' => $reply, 'state' => 'answered']);

                return ['id' => $turn->id, ...$reply];
            }
            $destination = $this->destination($space, $question);
            $reviewRequest = preg_match('/\b(?:categorize|classify|review|suggest)\b.*\b(?:uncategorized|needing a category|need(?:ing)? categor|transactions? needing|next transaction)\b|\b(?:next|uncategorized) transaction\b/i', $question);
            $followup = preg_match('/\b(?:what do you mean|why are you asking)\b/i', $question)
                && (AssistantTurn::where('space_id', $space->id)->where('user_id', $user->id)->where('id', '<', $turn->id)->latest('id')->first()?->response['choices'] ?? []);
            if (($reviewRequest || $followup) && count($destination['matches']) === 0) {
                $reply = $this->nextCategoryReview($user, $space, $context);
                $turn->update(['response' => $reply, 'state' => 'answered']);

                return ['id' => $turn->id, ...$reply];
            }

            if (! $destination['intent'] && $this->hasAnalysisContext($context) && ! preg_match('/\b(half|50\s*%|split)\b/i', $question)) {
                $reply = $this->analysisAnswer($user, $space, $context);
                $turn->update(['response' => $reply, 'state' => 'answered']);

                return ['id' => $turn->id, ...$reply];
            }
            $selection = $this->selection($user, $space, $context);
            $split = $this->splitProposal($user, $space, $question, $selection, $context);
            if ($split !== null) {
                $turn->update(['response' => $split, 'state' => isset($split['proposal']) ? 'proposed' : 'answered']);

                return ['id' => $turn->id, ...$split];
            }
            if ($destination['intent']) {
                if (($context['selection_truncated'] ?? false) && preg_match('/\b(these|those|all selected|all of them|every selected)\b/i', $question)) {
                    $reply = ['reply' => 'This view contains more than 100 transactions. Select a smaller complete batch before changing all of it; nothing has changed.', 'clarification' => true];
                    $turn->update(['response' => $reply, 'state' => 'answered']);

                    return ['id' => $turn->id, ...$reply];
                }
                $named = $this->namedSelection($question, $selection);
                if ($named === null) {
                    $reply = ['reply' => 'Which purchase should I change? Select one transaction, name its exact merchant, or say “all selected”. Nothing has changed.', 'clarification' => true];
                    $turn->update(['response' => $reply, 'state' => 'answered']);

                    return ['id' => $turn->id, ...$reply];
                }
                $selection = $named;
            }
            if ($destination['intent'] && $selection->isEmpty()) {
                $reply = ['reply' => 'Open or select the transaction you want to change, then tell me its category or program.', 'clarification' => true];
            } elseif ($destination['intent'] && count($destination['matches']) !== 1) {
                $reply = ['reply' => 'Which exact category or program should I use? Choose one from this workspace; no transaction has changed.', 'clarification' => true, 'choices' => $destination['choices']];
            } elseif ($destination['intent']) {
                $actions = [];
                foreach ($selection as $tx) {
                    $version = $context['transaction_versions'][(string) $tx->id] ?? null;
                    abort_unless(is_int($version) && $version === $tx->version, 409, 'The selected transaction changed. Refresh it before categorizing.');
                    $actions[] = ['transaction_id' => $tx->id, 'version' => $version, 'destination' => $destination['matches'][0]];
                }
                $reply = ['reply' => 'Review these '.count($actions).' transactions before applying the category or program.', 'proposal' => ['actions' => $actions, 'requires_review' => true]];
                $turn->update(['response' => $reply, 'state' => 'proposed']);
                if (count($actions) === 1 || preg_match('/\b(these|those|all selected|all of them|every selected)\b/i', $question)) {
                    return $this->apply($user, $space, $turn);
                }
            } else {
                if ($this->hasAnalysisContext($context)) {
                    $reply = $this->analysisAnswer($user, $space, $context);
                    $turn->update(['response' => $reply, 'state' => 'answered']);

                    return ['id' => $turn->id, ...$reply];
                }
                $data = app(DashboardService::class)->build($space, isset($context['from'], $context['through']) ? 'custom' : 'month', null, $context['from'] ?? null, $context['through'] ?? null, false, $user, $context['account_id'] ?? null);
                if ($selection->isNotEmpty() && preg_match('/\b(expensive|largest|biggest|selected|these|this purchase|this transaction)\b/i', $question)) {
                    $lines = $selection->sortBy('amount_cents')->take(10)->map(fn ($tx) => $tx->posted_on->toDateString().' · '.$tx->merchant.' · $'.number_format(abs($tx->amount_cents) / 100, 2));
                    $reply = ['reply' => "Selected transactions, largest first:\n".$lines->implode("\n"), 'mode' => 'Private calculation'];
                } else {
                    $reply = $useAi ? app(TrajectoryAiService::class)->respond($user, $space, $question, $data, $requestId)
                        : app(\App\Services\Bud\TrajectoryBudService::class)->respond($question, $data);
                }
            }
            app(FinanceAccess::class)->authorize($user->fresh(), $space->fresh());
            $turn->update(['response' => $reply, 'state' => isset($reply['proposal']) ? 'proposed' : 'answered']);

            return ['id' => $turn->id, ...$reply];
        } catch (\Throwable $exception) {
            if ($turn->fresh()->response === null) {
                $turn->update(['state' => 'failed', 'response' => ['reply' => 'This request could not be completed. No automatic retry was sent. Refresh the selected transaction and ask again.']]);
            }
            throw $exception;
        }
    }

    private function nextCategoryReview(User $user, Space $space, array $context): array
    {
        $selected = $this->selection($user, $space, $context);
        $query = Transaction::where('space_id', $space->id)->where('removed', false)->where('pending', false)
            ->whereIn('flow', ['expense', 'refund'])->where(fn ($q) => $q->where('category', 'uncategorized')->orWhere('reviewed', false));
        if (! empty($context['transaction_ids']) || isset($context['budget_account_id'])) {
            $query->whereIn('id', $selected->pluck('id'));
        } elseif (! empty($context['program_id'])) {
            return ['reply' => 'Open the program’s transaction list first so I can review its exact purchases.', 'clarification' => true];
        }
        if (! empty($context['account_id'])) {
            $query->where('account_id', $context['account_id']);
        }
        if (isset($context['from'], $context['through'])) {
            $query->whereDate('posted_on', '>=', $context['from'])->whereDate('posted_on', '<=', $context['through']);
        }
        $transaction = $query->orderByRaw('ABS(amount_cents) DESC')->orderBy('id')->first();
        if (! $transaction) {
            return ['reply' => 'No uncategorized or unreviewed posted purchases remain in this selection.', 'mode' => 'Category review'];
        }
        $attribution = app(AttributionService::class)->describe($user, $space, $transaction);
        $line = count($attribution['lines']) === 1 && $attribution['lines'][0]['space_id'] === $space->id ? $attribution['lines'][0] : null;
        if (! $line) {
            return ['reply' => 'This transaction has multiple allocations. Open its split details to review the intended portion.', 'clarification' => true];
        }
        $suggestions = app(ClassificationSuggestionService::class)->preview($user, $space, ['rows' => [['id' => $transaction->id, 'version' => $transaction->version, 'attribution_detail_id' => $line['detail_id'] ?? null]],
            'account_id' => $context['account_id'] ?? null, 'program_id' => $context['program_id'] ?? null]);
        $suggestion = $suggestions['rows'][0];
        $target = $suggestion['editable'] && $suggestion['category'] !== null
            ? collect($this->destinations($space))->first(fn ($destination) => $destination['key'] === $suggestion['category'] && in_array($destination['type'], ['category', 'subcategory'], true)) : null;
        app(FinanceAccess::class)->authorize($user->fresh(), $space->fresh());

        return ['reply' => $target ? 'I think this goes in '.$target['label'].'. Is that right?' : 'What was this purchase for?', 'mode' => 'Category review',
            'review_transaction' => ['transaction_id' => $transaction->id, 'version' => $transaction->version, 'date' => $transaction->posted_on->toDateString(),
                'merchant' => $transaction->merchant, 'amount_cents' => abs($transaction->amount_cents),
                'suggestion' => $target, 'reason' => $suggestion['reason']]];
    }

    public function apply(User $user, Space $space, AssistantTurn $turn): array
    {
        return DB::transaction(function () use ($user, $space, $turn): array {
            app(FinanceAccess::class)->authorize($user, $space);
            $turn = AssistantTurn::where('space_id', $space->id)->where('user_id', $user->id)->lockForUpdate()->findOrFail($turn->id);
            if ($turn->state === 'applied') {
                return ['id' => $turn->id, ...$turn->response];
            }
            abort_unless($turn->state === 'proposed' && ! empty($turn->response['proposal']['actions']), 409, 'This proposal is no longer available.');
            $applied = [];
            foreach ($turn->response['proposal']['actions'] as $action) {
                $tx = Transaction::where('space_id', $space->id)->lockForUpdate()->findOrFail($action['transaction_id']);
                abort_unless(! $tx->removed && $tx->version === $action['version'], 409, 'A transaction changed after the preview. No batch changes were saved.');
                $attribution = app(AttributionService::class)->describe($user, $space, $tx);
                abort_unless(count($attribution['lines']) === 1 && $attribution['lines'][0]['space_id'] === $space->id, 422, 'This purchase has split ownership or categories. Open its split editor to change the intended portion.');
                $target = $action['destination'];
                if ($target['type'] === 'split') {
                    $saved = app(AttributionService::class)->save($user, $space, $tx, $target['lines'], $tx->version);
                    $applied[] = ['transaction_id' => $tx->id, 'version' => $saved['version'], 'event_id' => $saved['event_id'], 'mode' => 'attribution'];

                    continue;
                }
                $currentTargets = $this->destinations($space);
                abort_unless(collect($currentTargets)->contains(fn ($candidate) => $candidate === $target), 409, 'The category or program changed. Choose it again.');
                if ($target['type'] === 'category' && $attribution['attribution_status'] === 'none') {
                    $tx = app(LedgerService::class)->classify($user, $space, $tx, ['version' => $tx->version, 'category' => $target['key'], 'flow' => $tx->flow, 'face_punched' => $tx->face_punched, 'bullshit_spending' => $tx->bullshit_spending], false);
                    $applied[] = ['transaction_id' => $tx->id, 'version' => $tx->version, 'mode' => 'category'];
                } else {
                    $line = $attribution['lines'][0];
                    if ($target['type'] === 'program') {
                        $line['program_record_id'] = $target['id'];
                    } else {
                        $line['category'] = $target['parent_key'] ?? $target['key'];
                        $line['subcategory_record_id'] = $target['type'] === 'subcategory' ? $target['id'] : null;
                    }
                    $saved = app(AttributionService::class)->save($user, $space, $tx, [$line], $tx->version);
                    $applied[] = ['transaction_id' => $tx->id, 'version' => $saved['version'], 'event_id' => $saved['event_id'], 'mode' => 'attribution'];
                }
            }
            $response = ['reply' => count($applied) === 1 ? 'Saved this transaction to '.$turn->response['proposal']['actions'][0]['destination']['label'].'.' : 'Saved '.count($applied).' reviewed transaction changes.', 'applied' => $applied, 'undo' => ['available' => true]];
            $turn->update(['state' => 'applied', 'response' => $response]);
            Event::create(['space_id' => $space->id, 'actor_id' => $user->id, 'action' => 'assistant_categorization', 'record_id' => $turn->id, 'after' => ['applied' => $applied, 'bank_cash_changed' => false]]);

            return ['id' => $turn->id, ...$response];
        });
    }

    public function undo(User $user, Space $space, AssistantTurn $turn): array
    {
        return DB::transaction(function () use ($user, $space, $turn): array {
            app(FinanceAccess::class)->authorize($user, $space);
            $turn = AssistantTurn::where('space_id', $space->id)->where('user_id', $user->id)->lockForUpdate()->findOrFail($turn->id);
            abort_unless($turn->state === 'applied', 409, 'This change is no longer undoable here.');
            foreach ($turn->response['applied'] as $action) {
                $tx = Transaction::where('space_id', $space->id)->findOrFail($action['transaction_id']);
                if ($action['mode'] === 'attribution') {
                    app(AttributionService::class)->undo($user, $space, $tx, $action['version'], $action['event_id']);
                } else {
                    $request = Request::create('/', 'POST', ['version' => $action['version']]);
                    $request->setUserResolver(fn () => $user);
                    app(TrajectoryController::class)->undo($request, $space, $tx, app(FinanceAccess::class));
                }
            }
            $response = ['reply' => 'Undid the categorization. Original bank amounts and dates are unchanged.', 'undo' => ['available' => false]];
            $turn->update(['state' => 'undone', 'response' => $response]);

            return ['id' => $turn->id, ...$response];
        });
    }

    private function hasAnalysisContext(array $context): bool
    {
        return ! empty($context['focused_transaction']) || ! empty($context['category']) || ! empty($context['program_id']) || ! empty($context['account_id']) || in_array($context['level'] ?? '', ['period', 'transaction', 'transactions'], true);
    }

    private function analysisAnswer(User $user, Space $space, array $context): array
    {
        $report = app(AnalysisService::class)->build($user, $space, array_intersect_key($context, array_flip(['from', 'through', 'account_id', 'program_id', 'basis'])));
        if (! empty($context['focused_transaction']) && count($context['transaction_ids'] ?? []) === 1) {
            $pending = Transaction::where('space_id', $space->id)->where('pending', true)->where('removed', false)->find($context['transaction_ids'][0]);
            if ($pending) {
                return ['reply' => $pending->merchant.' · $'.number_format(abs($pending->amount_cents) / 100, 2).' is pending. It is separate from posted spending; its amount can change when the bank posts it. You can categorize this purchase now.', 'mode' => 'Private calculation · pending purchase', 'context_summary' => ['amount_cents' => abs($pending->amount_cents), 'pending' => true, 'basis' => 'pending_bank_purchase']];
            }
        }
        $rows = collect($report['entries']);
        $direction = $context['direction'] ?? 'out';
        $incoming = in_array($direction, ['in', 'income'], true);
        $rows = $rows->where('direction', $incoming ? 'in' : 'out');
        if (isset($context['budget_account_id'])) {
            $rows = $rows->filter(function (array $row) use ($context, $space): bool {
                $source = $row['source_identity'] ?? [];
                $sourceAccount = ($source['source_space_id'] ?? null) === $space->id ? ($source['account_id'] ?? null) : null;

                return ($row['budget_account_id'] ?? $sourceAccount) === (int) $context['budget_account_id'];
            });
        }
        $category = $context['category'] ?? null;
        $name = $incoming ? 'Recorded receipts' : 'Recorded spending';
        if ($category) {
            if ($incoming) {
                $group = collect($report['inflows'])->firstWhere('key', $category);
                $rows = $rows->filter(fn ($row) => ($row['analysis_source']['key'] ?? null) === $category);
            } else {
                $group = collect($report['categories'])->firstWhere('key', $category);
                $keys = [$category, ...array_column($group['children'] ?? [], 'key')];
                $rows = $rows->filter(fn ($row) => in_array(($context['basis'] ?? 'spending') === 'cash' ? ($row['analysis_category'] ?? $row['category']) : $row['category'], $keys, true));
            }
            $name = $group['label'] ?? ucfirst(str_replace('_', ' ', $category));
        }
        if (! empty($context['subcategory'])) {
            $leaf = $context['subcategory'];
            $rows = $rows->filter(fn ($row) => ($row['category'] ?? null) === $leaf);
            $name .= ' / '.($leaf);
        }
        if (! empty($context['focused_transaction']) && ! empty($context['transaction_ids'])) {
            $rows = $rows->whereIn('id', $context['transaction_ids']);
        }
        $amount = ($incoming ? 1 : -1) * (int) $rows->sum('amount_cents');
        $label = '$'.number_format($amount / 100, 2);
        $top = $rows->sortBy(fn ($row) => -abs($row['amount_cents']))->take(10)->map(fn ($row) => $row['date'].' · '.$row['merchant'].' · '.($row['amount_cents'] > 0 && ! $incoming ? 'refund ' : '').'$'.number_format(abs($row['amount_cents']) / 100, 2));
        $reply = $name.' for '.$report['range']['from'].' through '.$report['range']['through'].': '.$label.' across '.$rows->count().' recorded entries.';
        $reply .= "\n".(($context['basis'] ?? 'spending') === 'cash' ? 'This is bank cash movement; repayments can appear here.' : 'Purchases are counted when incurred, net of refunds; card and loan repayments are separate.');
        if ($top->isNotEmpty()) {
            $reply .= "\n\nLargest contributing entries:\n".$top->implode("\n");
        }
        $reply .= "\n\nRecorded history can be incomplete. Select a purchase to change its category or program.";

        return ['reply' => $reply, 'mode' => 'Private calculation · current drilldown', 'context_summary' => ['amount_cents' => $amount, 'count' => $rows->count(), 'basis' => $context['basis'] ?? 'spending', 'range' => $report['range']]];
    }

    private function namedSelection(string $question, \Illuminate\Support\Collection $selection): ?\Illuminate\Support\Collection
    {
        if (! preg_match('/^(?:please\s+)?(?:move|categorize|recategorize|classify|reclassify|put|mark|change|set)\s+(.+?)\s+(?:as|into|to|under)\b/i', trim($question), $match)) {
            return $selection;
        }
        $requested = trim(preg_replace('/\b(?:the|my|our|purchase|purchases|transaction|transactions)\b/i', '', $match[1]));
        $normalize = fn ($value) => mb_strtolower(trim(preg_replace('/[^\pL\pN]+/u', ' ', $value)));
        if (in_array($normalize($requested), ['this', 'that', 'these', 'those', 'it', 'all selected', 'all of them', 'every selected'], true)) {
            return $selection;
        }
        $matches = $selection->filter(fn ($tx) => $normalize($tx->merchant) === $normalize($requested));

        return $matches->count() === 1 ? $matches : null;
    }

    private function splitProposal(User $user, Space $space, string $question, \Illuminate\Support\Collection $selection, array $context): ?array
    {
        if (! preg_match('/\b(?:half|50\s*%)\s+(?:(?:to|for)\s+)?(.+?)\s*(?:,|and|\/)??\s+(?:half|50\s*%)\s+(?:(?:to|for)\s+)?(.+?)[.!]?$/i', $question, $matches)) {
            return preg_match('/\bsplit\b/i', $question) ? ['reply' => 'Select one purchase and name both portions, for example “half household and half Modern Forestry”. I will show the exact amounts before saving.', 'clarification' => true] : null;
        }
        if ($selection->count() !== 1) {
            return ['reply' => 'Select one purchase before splitting its amount.', 'clarification' => true];
        }
        $transaction = $selection->first();
        abort_unless(($context['transaction_versions'][(string) $transaction->id] ?? null) === $transaction->version, 409, 'Refresh this purchase before splitting it.');
        $normalize = fn ($value) => mb_strtolower(trim(preg_replace('/[^\pL\pN]+/u', ' ', $value)));
        $spaces = app(FinanceAccess::class)->spaces($user);
        $resolve = function (string $name) use ($space, $spaces, $normalize): ?array {
            $needle = $normalize($name);
            if (in_array($needle, ['household', 'personal', 'family'], true) && $space->kind === 'household') {
                return ['space_id' => $space->id, 'program_record_id' => null, 'label' => $space->name];
            }
            $matches = [];
            foreach ($spaces as $candidate) {
                foreach (app(AttributionService::class)->programs($candidate) as $program) {
                    if ($program['active'] && in_array($needle, [$normalize($program['name']), $normalize($program['key'])], true)) {
                        $matches[] = ['space_id' => $candidate->id, 'program_record_id' => $program['id'], 'label' => $candidate->name.' / '.$program['name']];
                    }
                }
                if ($normalize($candidate->name) === $needle) {
                    $matches[] = ['space_id' => $candidate->id, 'program_record_id' => null, 'label' => $candidate->name];
                }
            }
            if ($matches === []) {
                foreach ($spaces as $candidate) {
                    if (mb_strlen($needle) >= 4 && str_contains($normalize($candidate->name), $needle)) {
                        $matches[] = ['space_id' => $candidate->id, 'program_record_id' => null, 'label' => $candidate->name];
                    }
                }
            }

            return count($matches) === 1 ? $matches[0] : null;
        };
        $a = $resolve($matches[1]);
        $b = $resolve($matches[2]);
        if (! $a || ! $b || [$a['space_id'], $a['program_record_id']] === [$b['space_id'], $b['program_record_id']]) {
            return ['reply' => 'Name two distinct authorized household, business or program destinations. The split is not saved yet.', 'clarification' => true];
        }
        $first = intdiv($transaction->amount_cents, 2);
        $lines = [];
        foreach ([[$a, $first], [$b, $transaction->amount_cents - $first]] as [$target, $amount]) {
            $lines[] = ['space_id' => $target['space_id'], 'amount_cents' => $amount, 'category' => $transaction->category, 'program_record_id' => $target['program_record_id'], 'subcategory_record_id' => null, 'settlement_status' => 'none'];
        }
        $label = $a['label'].' and '.$b['label'];

        return ['reply' => 'Split '.$transaction->merchant.': $'.number_format(abs($first) / 100, 2).' to '.$a['label'].' and $'.number_format(abs($transaction->amount_cents - $first) / 100, 2).' to '.$b['label'].'. Review before saving. This does not move bank money or claim reimbursement.',
            'proposal' => ['requires_review' => true, 'actions' => [['transaction_id' => $transaction->id, 'version' => $transaction->version, 'destination' => ['type' => 'split', 'label' => $label, 'lines' => $lines]]]]];
    }

    private function selection(User $user, Space $space, array $context): \Illuminate\Support\Collection
    {
        if (! empty($context['program_id'])) {
            abort_unless(collect(app(AttributionService::class)->programs($space))->contains(fn ($program) => $program['id'] === $context['program_id'] && $program['active']), 403, 'This program belongs to another finance space.');
        }
        $ids = $context['transaction_ids'] ?? [];
        $rows = Transaction::where('space_id', $space->id)->whereIn('id', $ids)->where('removed', false)->get();
        abort_unless($rows->count() === count($ids), 403, 'A selected transaction is not owned by this finance space.');
        if (! empty($context['account_id'])) {
            abort_unless(\App\Models\Trajectory\Account::where('space_id', $space->id)->whereKey($context['account_id'])->exists(), 403);
            abort_unless($rows->every(fn ($row) => $row->account_id === $context['account_id']), 403);
        }
        if (isset($context['from'], $context['through'])) {
            abort_unless($rows->every(fn ($row) => $row->posted_on->toDateString() >= $context['from'] && $row->posted_on->toDateString() <= $context['through']), 409, 'The transaction is outside the selected period. Refresh the view.');
        }

        return $rows;
    }

    private function destination(Space $space, string $question): array
    {
        $all = $this->destinations($space);
        $target = trim($question);
        $intent = preg_match('/\b(categorize|recategorize|classify|reclassify|move|put|mark|set|change)\b/i', $question) && ! preg_match('/\b(budget|target|limit)\b/i', $question);
        if (preg_match('/\b(?:as|into|to|under)\s+(.+?)[.!]?$/i', $question, $match) && $intent) {
            $target = $match[1];
        } elseif (preg_match('/^(?:this|that|it|those)(?:\s+(?:is|was|are|were))?\s+(?:for\s+)?(.+?)[.!]?$/i', $target, $match)) {
            $target = $match[1];
            $intent = true;
        }
        $kind = preg_match('/\bprogram\b/i', $target) ? 'program' : (preg_match('/\bsubcategory\b/i', $target) ? 'subcategory' : (preg_match('/\bcategory\b/i', $target) ? 'category' : null));
        $target = preg_replace('/\b(category|program|subcategory)\b/i', '', $target);
        $normalize = fn ($v) => mb_strtolower(trim(preg_replace('/[^\pL\pN]+/u', ' ', $v)));
        $matches = array_values(array_filter($all, fn ($candidate) => ($kind === null || $candidate['type'] === $kind) && in_array($normalize($target), [$normalize($candidate['key']), $normalize($candidate['label'])], true)));

        return ['intent' => (bool) $intent || $matches !== [], 'matches' => $matches, 'choices' => array_slice($all, 0, 60)];
    }

    private function destinations(Space $space): array
    {
        $labels = $space->settings['income_categories'] ?? [];
        $targets = array_map(fn ($key) => ['type' => 'category', 'key' => $key, 'label' => $labels[$key] ?? ucfirst(str_replace('_', ' ', $key))], app(WorkspaceService::class)->categories($space));
        $definitions = app(AttributionService::class)->categoryDefinitions($space);
        foreach ($definitions as $definition) {
            if (! $definition['active']) {
                continue;
            }
            if ($definition['parent_record_id']) {
                $parent = collect($definitions)->firstWhere('id', $definition['parent_record_id']);
                $targets[] = ['type' => 'subcategory', 'id' => $definition['id'], 'key' => $definition['key'], 'label' => $definition['name'], 'parent_key' => $parent['key']];
            } else {
                $targets = array_values(array_filter($targets, fn ($target) => $target['key'] !== $definition['key']));
                $targets[] = ['type' => 'category', 'key' => $definition['key'], 'label' => $definition['name']];
            }
        }
        foreach (app(AttributionService::class)->programs($space) as $program) {
            if ($program['active']) {
                $targets[] = ['type' => 'program', 'id' => $program['id'], 'key' => $program['key'], 'label' => $program['name']];
            }
        }

        return $targets;
    }
}
