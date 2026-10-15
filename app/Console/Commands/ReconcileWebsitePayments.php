<?php

namespace App\Console\Commands;

use App\Models\WebsitePayment;
use App\Services\ManagedWebsite\WebsitePaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ReconcileWebsitePayments extends Command
{
    protected $signature = 'website:reconcile-payments {--limit=100 : Maximum native attempts to inspect}';

    protected $description = 'Recover native Website Checkout payments and refunds without enabling checkout';

    public function handle(WebsitePaymentService $payments): int
    {
        $failed = 0;
        $attempts = WebsitePayment::query()->where('provider', 'stripe')->where('metadata->checkout_version', 1)
            ->whereNotIn('status', ['expired', 'cancelled', 'failed', 'refunded'])
            ->orderBy('updated_at')->orderBy('id')->limit(max(1, min(500, (int) $this->option('limit'))))->get();
        foreach ($attempts as $payment) {
            try {
                $payments->reconcile($payment);
                if (in_array($payment->fresh()->status, ['payment_review', 'paid_review'], true)) {
                    $failed++;
                }
            } catch (\Throwable $exception) {
                $failed++;
                // Provider bodies may contain shopper data. Log only identity and type.
                Log::warning('Native Website payment reconciliation deferred.', ['website_payment_id' => $payment->id, 'exception_type' => $exception::class]);
            } finally {
                $payment->touch();
            }
        }
        $this->info('Inspected '.$attempts->count().' native attempts; '.$failed.' require another retry or staff review.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
