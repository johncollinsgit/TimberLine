<?php

namespace App\Console\Commands;

use App\Models\SubscriptionPilotCycle;
use App\Models\SubscriptionPilotEnrollment;
use App\Models\SubscriptionPilotEvent;
use App\Services\Subscriptions\SubscriptionPilotExecutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class ProcessSubscriptionPilot extends Command
{
    protected $signature='subscriptions:pilot-process {--collect : Collect explicitly activated due subscriptions} {--reconcile : Recover existing provider objects and paid Shopify handoffs} {--limit=20}';
    protected $description='Process only reviewed pilot authorizations and original payment/order attempts.';

    public function handle(SubscriptionPilotExecutionService $execution): int
    {
        if(!Schema::hasTable('subscription_pilot_cycles') || !Schema::hasTable('subscription_pilot_enrollments'))return self::SUCCESS;
        $limit=filter_var($this->option('limit'),FILTER_VALIDATE_INT);
        if(!$limit || $limit<1 || $limit>100){$this->error('Use a limit from 1 to 100.');return self::FAILURE;}
        if(!$this->option('collect') && !$this->option('reconcile')) {
            $this->line(json_encode(['active'=>SubscriptionPilotEnrollment::where('status','active')->count(),'provider_requests'=>false]));
            return self::SUCCESS;
        }
        $counts=['collected'=>0,'reconciled'=>0,'review_required'=>0];
        if($this->option('reconcile')) {
            foreach(SubscriptionPilotCycle::query()->whereNull('payment_intent_id')->whereNotNull('payment_started_at')->orderBy('updated_at')->limit($limit)->get() as $cycle) {
                try{$execution->recoverUnknown($cycle);$counts['reconciled']++;}catch(\Throwable){$counts['review_required']++;}
            }
            foreach(SubscriptionPilotCycle::query()->whereNotNull('payment_intent_id')->where(function($q){$q->where('handoff_status','!=','confirmed')->orWhereIn('payment_status',['unknown','processing','requires_action','review']);})
                ->orderBy('updated_at')->limit($limit)->get() as $cycle) {
                try{$execution->reconcilePayment($cycle);$counts['reconciled']++;}
                catch(\Throwable){$cycle->update(['last_error_code'=>'original_payment_or_handoff_review']);$counts['review_required']++;}
            }
            // Accepted receipts can recover a lost create response without another charge.
            foreach(SubscriptionPilotEvent::query()->where('provider','stripe')->where('kind','webhook')->where('verification','stripe_signature')
                ->where('created_at','>=',now()->subDays(7))->orderByDesc('id')->limit($limit)->get() as $receipt) {
                try{app(\App\Services\Subscriptions\SubscriptionPilotWebhookService::class)->process($receipt);}
                catch(\Throwable){$counts['review_required']++;}
            }
        }
        if($this->option('collect') && config('subscriptions.pilot_collection_enabled')) {
            foreach(SubscriptionPilotEnrollment::query()->where('status','active')->whereIn('tenant_id',config('subscriptions.pilot_tenant_ids',[]))
                ->whereBetween('next_billing_date',[now()->subDay()->toDateString(),now()->addDay()->toDateString()])->orderBy('updated_at')->orderBy('id')->limit($limit)->get() as $row) {
                $clock=now($row->terms['timezone']);
                if($row->next_billing_date->toDateString()!==$clock->toDateString() || $clock->hour<$row->terms['billing_hour'])continue;
                try{$cycle=$execution->collect($row);$counts[$cycle->payment_status==='succeeded'?'collected':'review_required']++;}
                catch(\Throwable){$counts['review_required']++;}
                $row->touch();
            }
        }
        $this->line(json_encode($counts));
        return self::SUCCESS;
    }
}
