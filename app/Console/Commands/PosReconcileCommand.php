<?php

namespace App\Console\Commands;

use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentCore;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\Pos\PosOutbox;
use App\Services\Pos\PosRecoveryAccess;
use App\Services\Pos\PosService;
use App\Services\Pos\PosStore;
use App\Services\Pos\SquareNativeProvider;
use Illuminate\Console\Command;

final class PosReconcileCommand extends Command
{
    protected $signature = 'pos:reconcile {--tenant=} {--limit=100} {--all : Include completed sales for a bounded daily audit}';

    protected $description = 'Recover existing original-provider POS operations and drain their durable commerce effects';

    public function handle(PosStore $store, PaymentJournal $journal, PosService $service, PosOutbox $outbox, SquareNativeProvider $square): int
    {
        if (config('pos_live.servicing_enabled') !== true) {
            $this->line('POS servicing is disabled.');

            return self::SUCCESS;
        }
        $query = $store->db->table('pos_live_sales')->where('runtime_id', $store->runtime());
        if ($this->option('tenant')) {
            $query->where('tenant_id', (int) $this->option('tenant'));
        }
        if (! $this->option('all')) {
            app(\App\Services\Pos\PosUncollectedClosure::class)->excludeClosed($query);
            $query->where(function ($q): void {
                $q->whereIn('state', ['reserved', 'processing', 'unknown', 'review'])
                    ->orWhere(fn ($expired) => $expired->where('state', 'quoted')->where('expires_at', '<=', now()))
                    ->orWhereExists(fn ($effects) => $effects->selectRaw('1')->from('pos_live_outbox as o')
                        ->whereColumn('o.sale_id', 'pos_live_sales.id')->whereColumn('o.runtime_id', 'pos_live_sales.runtime_id')
                        ->whereColumn('o.tenant_id', 'pos_live_sales.tenant_id')->whereIn('o.state', ['pending', 'retry', 'processing']))
                    ->orWhereExists(fn ($refunds) => $refunds->selectRaw('1')->from('payment_core_operations as r')
                        ->whereColumn('r.original_id', 'pos_live_sales.attempt_id')->whereColumn('r.tenant_id', 'pos_live_sales.tenant_id')
                        ->where('r.kind', 'refund')->whereIn('r.state', ['reserved', 'processing', 'unknown', 'review']));
            });
        }
        $core = new PaymentCore($journal, ['square_native' => $square], new PosRecoveryAccess($store));
        $failed = 0;
        foreach ($query->orderBy('reconciled_at')->orderBy('created_at')->limit(min(500, max(1, (int) $this->option('limit'))))->get() as $sale) {
            try {
                if ($sale->state === 'quoted' && strtotime($sale->expires_at) <= time()) {
                    $service->expireUnstarted($sale);
                    $sale = $store->rows('sales', (int) $sale->tenant_id)->where('id', $sale->id)->firstOrFail();
                }
                $snapshot = $service->snapshot($sale);
                $binding = MerchantBinding::restore($snapshot['binding']);
                $operation = $journal->findByRequest((int) $sale->tenant_id, $sale->payment_request_id);
                if ($operation) {
                    $result = $core->recover($binding, $operation['id']);
                    if ($this->option('all') && in_array($result['state'], ['succeeded', 'failed', 'cancelled'], true)) {
                        $square->audit($result);
                    }
                    $service->recordOutcome($sale, $result);
                    $refunds = $store->db->table('payment_core_operations')->where('tenant_id', $sale->tenant_id)
                        ->where('original_id', $operation['id'])->where('kind', 'refund')->get(['id']);
                    foreach ($refunds as $refund) {
                        $result = $core->recover($binding, $refund->id);
                        if ($this->option('all') && in_array($result['state'], ['succeeded', 'failed', 'cancelled'], true)) {
                            $square->audit($result);
                        }
                        if ($result['state'] === 'succeeded') {
                            $service->enqueue((int) $sale->tenant_id, $sale->id, 'refund_'.$result['id'], ['operation_id' => $result['id']]);
                            $service->enqueue((int) $sale->tenant_id, $sale->id, 'customer_refund_'.$result['id'], ['operation_id' => $result['id']]);
                        }
                    }
                }
                $register = $store->rows('registers', (int) $sale->tenant_id)->where('id', $sale->register_id)->firstOrFail();
                $outbox->drainSale(['tenant_id' => (int) $sale->tenant_id, 'register' => $register], $sale->id);
            } catch (\Throwable $error) {
                $failed++;
                $store->event((int) $sale->tenant_id, $sale->id, 'reconciliation_needs_review',
                    ['code' => $error instanceof \App\Services\Pos\PosException ? $error->blocker : 'reconciliation_failed']);
            } finally {
                $store->rows('sales', (int) $sale->tenant_id)->where('id', $sale->id)->update(['reconciled_at' => now()]);
            }
        }
        $review = $store->db->table('pos_live_outbox')->where('runtime_id', $store->runtime())->whereIn('state', ['review', 'retry'])->count();
        $this->line(json_encode(['reconciliation_errors' => $failed, 'commerce_effects_pending_review' => $review]));

        $unresolvedQuery = $store->db->table('pos_live_sales')->where('runtime_id', $store->runtime())
            ->whereIn('state', ['unknown', 'processing'])->where('created_at', '<', now()->subMinutes(5));
        app(\App\Services\Pos\PosUncollectedClosure::class)->excludeClosed($unresolvedQuery);
        $unresolved = $unresolvedQuery->count();
        if ($review || $failed || $unresolved) {
            \Illuminate\Support\Facades\Log::warning('pos.reconciliation_attention', ['runtime_id' => $store->runtime(),
                'errors' => $failed, 'commerce_pending_review' => $review, 'unresolved_payments' => $unresolved]);
        }

        return ($failed || $review || $unresolved) ? self::FAILURE : self::SUCCESS;
    }
}
