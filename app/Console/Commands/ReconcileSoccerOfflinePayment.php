<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Soccer\SoccerAccess;
use App\Services\Soccer\SoccerPaymentService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class ReconcileSoccerOfflinePayment extends Command
{
    protected $signature = 'soccer:reconcile-offline {invoice}
        {--tenant=wren-family-soccer} {--actor=} {--action=}
        {--amount-cents=} {--reference=} {--reason=} {--expected-family=}
        {--apply : Apply the reviewed reconciliation; otherwise preview only}
        {--restore-places : Restore available places for a verified expired Venmo invoice without creating another receipt}';

    protected $description = 'Preview or apply an organizer-verified soccer Venmo receipt or fee waiver';

    public function handle(SoccerStore $store, SoccerPaymentService $payments, SoccerAccess $access): int
    {
        $data = Validator::make([
            'invoice' => $this->argument('invoice'), 'actor' => $this->option('actor'),
            'action' => $this->option('action'), 'amount' => $this->option('amount-cents'),
            'reason' => $this->option('reason'), 'reference' => $this->option('reference'),
            'family' => $this->option('expected-family'),
        ], [
            'invoice' => 'required|uuid', 'actor' => 'required|integer|min:1',
            'action' => 'required|in:venmo,waive', 'amount' => 'required|integer|min:1',
            'reason' => 'required|string|max:1000', 'reference' => 'nullable|string|max:190',
            'family' => 'required|uuid',
        ])->validate();
        $tenant = Tenant::where('slug', $this->option('tenant'))->firstOrFail();
        $actor = User::findOrFail($data['actor']);
        abort_unless($actor->email_verified_at && $access->enabled($tenant) && $access->admin($tenant, $actor), 403);
        $invoice = $store->row('invoices', $tenant->id, $data['invoice']);
        abort_unless($invoice->family_id === $data['family'], 409, 'Expected family does not own this invoice.');
        abort_unless((int) $invoice->amount_cents === (int) $data['amount'] || $data['action'] === 'waive' && $invoice->status === 'waived', 409, 'Invoice amount changed; review before applying.');
        $this->line(json_encode([
            'mode' => $this->option('apply') ? 'apply' : 'preview', 'tenant_id' => $tenant->id,
            'invoice_id' => $invoice->id, 'family_id' => $invoice->family_id,
            'current_status' => $invoice->status, 'current_amount_cents' => (int) $invoice->amount_cents,
            'action' => $data['action'], 'expected_amount_cents' => (int) $data['amount'],
            'has_card_checkout_to_reconcile' => (bool) $invoice->checkout_id,
            'registration_count' => $store->query('registrations', $tenant->id)->where('offer_group', $invoice->offer_group)->count(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if (! $this->option('apply')) {
            $this->info('Preview only. No provider calls, records, or messages were changed.');

            return self::SUCCESS;
        }
        if ($data['action'] === 'waive') {
            $payments->waive($tenant->id, $invoice->id, $data['reason'], $actor->id, (int) $data['amount']);
            $this->info('Organizer waiver recorded. No payment receipt was created.');
        } else {
            $status = $payments->confirmVenmo($tenant->id, $invoice->id, (int) $data['amount'], $data['reference'], $data['reason'], $actor->id);
            if ($status === 'review' && $this->option('restore-places') && filled($data['reference'])) {
                $status = $payments->restoreVenmoPlaces($tenant->id, $invoice->id, (int) $data['amount'], $data['reference'], $data['reason'], $actor->id);
            }
            $this->info($status === 'paid' ? 'Verified Venmo receipt recorded and current places confirmed.' : 'Receipt recorded for review; current places were not changed.');
            if ($status === 'review') {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
