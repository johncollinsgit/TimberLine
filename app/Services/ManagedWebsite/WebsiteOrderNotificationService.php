<?php

namespace App\Services\ManagedWebsite;

use App\Jobs\SendWebsiteOrderNotification;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderEvent;
use App\Models\WebsiteOrderNotification;
use App\Models\WebsiteShipment;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** A durable, native-only outbox. Acceptance is not proof of inbox delivery. */
class WebsiteOrderNotificationService
{
    private const COPY = [
        'invoice_payment' => ['Invoice payment received', 'Your payment has been received against your invoice. Open your private invoice to review the receipt and any remaining balance.'],
        'confirmed' => ['Order confirmed', 'Your payment is confirmed. We will update you when your order is ready or on its way.'],
        'payment_review' => ['Your order needs review', 'The store is reviewing your order. Please contact the store before trying another payment.'],
        'preparing' => ['Preparing your package', 'A shipping label is ready. Your package has not yet been handed to the carrier.'],
        'shipped' => ['Your package is on its way', 'Your package has been handed to the carrier. Follow its progress using your private order link.'],
        'delivered' => ['Your package was delivered', 'The carrier reports that your package was delivered. Contact the store if you need help.'],
        'exception' => ['Your delivery needs attention', 'There is an update affecting your delivery. Check your order for the latest status or contact the store.'],
        'label_updated' => ['Your shipping information changed', 'The store updated the shipping label for your order. Your private order page has the current information.'],
    ];

    /** Called inside the transaction that accepts a real order/shipment transition. */
    public function record(WebsiteOrder $order, string $transitionKey, string $kind, ?WebsiteShipment $shipment = null): void
    {
        if (! in_array($order->source, ['native', 'staff_quote', 'staff_draft_invoice'], true)) {
            return;
        }
        if (DB::transactionLevel() < 1 || ! isset(self::COPY[$kind]) || trim($transitionKey) === '') {
            throw new \LogicException('Native order notifications require a supported accepted transition transaction.');
        }
        $order = WebsiteOrder::query()->forTenantId($order->tenant_id)->with('lines')->findOrFail($order->id);
        if ($shipment) {
            $shipment = WebsiteShipment::query()->forTenantId($order->tenant_id)->where('website_order_id', $order->id)->findOrFail($shipment->id);
        }
        $key = hash('sha256', json_encode([$order->id, $shipment?->id, $kind, $transitionKey], JSON_THROW_ON_ERROR));
        if (WebsiteOrderNotification::query()->forTenantId($order->tenant_id)->where('transition_key', $key)->exists()) {
            return;
        }
        $tenant = Tenant::query()->findOrFail($order->tenant_id);
        $readiness = $this->readiness($tenant);
        $recipient = trim((string) data_get($order->customer_snapshot, 'email'));
        $validRecipient = filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false && ! preg_match('/[\r\n]/', $recipient);
        $validToken = preg_match('/^[A-Za-z0-9]{56}$/', (string) $order->lookup_token) === 1;
        $brand = $this->oneLine($tenant->brandProfile?->display_name ?: $tenant->name);
        [$heading, $message] = self::COPY[$kind];
        $snapshot = ['recipient' => $recipient, 'brand' => $brand, 'kind' => $kind,
            'subject' => $heading.' · '.$this->oneLine($order->number).' · '.$brand, 'heading' => $heading, 'message' => $message,
            'order_number' => $order->number, 'currency' => $order->currency, 'subtotal_cents' => $order->subtotal_cents,
            'shipping_cents' => $order->shipping_cents, 'tax_cents' => $order->tax_cents, 'total_cents' => $order->total_cents,
            'items' => $order->lines->map(fn ($line) => ['title' => $line->title, 'quantity' => $line->quantity, 'line_total_cents' => $line->line_total_cents])->all(),
            'carrier' => $shipment?->carrier, 'tracking_number' => $shipment?->tracking_number,
            'order_url' => $readiness['public_url'] && $validToken ? $this->orderUrl($order, $readiness['public_url']) : null,
            ...$this->identity(), 'identity_fingerprint' => $this->identityFingerprint()];
        $ready = $readiness['ready'] && $validRecipient && $validToken;
        $notification = WebsiteOrderNotification::query()->firstOrCreate(['tenant_id' => $order->tenant_id, 'transition_key' => $key], [
            'website_order_id' => $order->id, 'website_shipment_id' => $shipment?->id, 'kind' => $kind,
            'snapshot' => $snapshot, 'status' => $ready ? 'pending' : 'blocked', 'next_attempt_at' => $ready ? now() : null,
            'failure_code' => $ready ? null : (! $validRecipient ? 'invalid_recipient' : (! $validToken ? 'invalid_order_access' : 'not_ready_at_transition')),
        ]);
        if ($notification->wasRecentlyCreated && $ready) {
            // A queue handoff failure must not undo, or misreport, an accepted payment.
            DB::afterCommit(fn () => $this->enqueue($notification->id));
        }
    }

    /** Safe setup-health data; no live provider calls, credentials, or shopper data. */
    public function readiness(Tenant $tenant): array
    {
        $blockers = [];
        if (! config('managed_website.order_notifications_enabled') || ! in_array($tenant->id, (array) config('managed_website.order_notifications_tenant_ids', []), true)) {
            $blockers[] = 'Native order notifications are not enabled for this workspace.';
        }
        if (! app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'managed_website')) {
            $blockers[] = 'The Managed Website entitlement is unavailable.';
        }
        $identity = $this->identity();
        $config = (array) config('mail.mailers.'.$identity['mailer'], []);
        $transport = (string) ($config['transport'] ?? '');
        $allowed = ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'];
        if (app()->environment('testing')) {
            $allowed[] = 'array';
        }
        if (! config('managed_website.order_notifications_sender_approved') || $identity['mailer'] === ''
            || ! in_array($transport, $allowed, true) || ! filter_var($identity['from_address'], FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n]/', $identity['from_address'].$identity['from_name']) || $identity['from_name'] === '') {
            $blockers[] = 'Select and approve a native transactional mailer and verified sender identity.';
        }
        if (! app()->environment('testing') && in_array(config('queue.default'), ['sync', 'null', null, ''], true)) {
            $blockers[] = 'Native order updates require a persistent queue worker.';
        }
        $site = TenantSite::query()->forTenantId($tenant->id)->first();
        $url = $site ? $this->publicUrl($site) : null;
        if (! $url || ! app(ManagedWebsiteService::class)->publicPage($tenant, '')) {
            $blockers[] = 'A published native Website and verified public host are required for private order links.';
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers, 'public_url' => $url, 'mailer' => $identity['mailer'], 'transport' => $transport];
    }

    public function deliver(int $id): void
    {
        $notification = DB::transaction(function () use ($id): ?WebsiteOrderNotification {
            $row = WebsiteOrderNotification::query()->lockForUpdate()->find($id);
            if (! $row || ! in_array($row->status, ['pending', 'queued', 'retry'], true)
                || ($row->status !== 'queued' && $row->next_attempt_at?->isFuture())) {
                return null;
            }
            $order = WebsiteOrder::query()->forTenantId($row->tenant_id)->find($row->website_order_id);
            $tenant = Tenant::query()->find($row->tenant_id);
            $readiness = $tenant ? $this->readiness($tenant) : ['ready' => false, 'public_url' => null];
            $expectedUrl = $order && $readiness['public_url'] ? $this->orderUrl($order, $readiness['public_url']) : null;
            if (! $order || ! in_array($order->source, ['native', 'staff_quote', 'staff_draft_invoice'], true) || ! $readiness['ready'] || $expectedUrl !== data_get($row->snapshot, 'order_url')
                || ! hash_equals((string) data_get($row->snapshot, 'identity_fingerprint', ''), $this->identityFingerprint())) {
                $row->update(['status' => 'blocked', 'failure_code' => 'readiness_changed', 'next_attempt_at' => null]);

                return null;
            }
            if (! $this->current($row, $order)) {
                $row->update(['status' => 'superseded', 'failure_code' => 'transition_superseded', 'next_attempt_at' => null]);

                return null;
            }
            if ($row->attempts >= 5) {
                $row->update(['status' => 'failed', 'failure_code' => 'attempt_limit', 'next_attempt_at' => null]);

                return null;
            }
            $row->update(['status' => 'processing', 'attempts' => $row->attempts + 1,
                'lease_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addMinutes(5), 'next_attempt_at' => null]);

            return $row;
        });
        if (! $notification) {
            return;
        }
        $transport = app(WebsiteOrderNotificationTransport::class);
        try {
            $mail = $transport->prepare($notification);
        } catch (\Throwable $exception) {
            $this->finish($notification, ['status' => $notification->attempts >= 5 ? 'failed' : 'retry', 'failure_code' => 'local_preparation_failed',
                'next_attempt_at' => $notification->attempts >= 5 ? null : now()->addMinutes(min(60, 2 ** $notification->attempts))]);

            return;
        }
        try {
            $messageId = $transport->send($notification, $mail);
        } catch (\Throwable $exception) {
            // A send exception can follow provider acceptance. Never guess and resend.
            $this->finish($notification, ['status' => 'unknown', 'failure_code' => 'transport_acceptance_unknown']);

            return;
        }
        // If this save fails or the worker dies, the lease expires into unknown,
        // not pending. A provider-accepted message must never be sent blindly again.
        $this->finish($notification, ['status' => 'accepted', 'accepted_at' => now(), 'provider_message_id' => mb_substr($messageId, 0, 255), 'failure_code' => null]);
    }

    /** Explicit operator action only. This never makes blocked history auto-send. */
    public function retryBlocked(WebsiteOrder $order, int $id, User $actor, string $reason): void
    {
        app(WebsiteShippingAccessService::class)->authorize($actor, $order->tenant_id);
        abort_if(mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 1000, 422, 'Record the reason for retrying this update.');
        DB::transaction(function () use ($order, $id, $actor, $reason): void {
            $row = WebsiteOrderNotification::query()->forTenantId($order->tenant_id)->where('website_order_id', $order->id)->lockForUpdate()->findOrFail($id);
            abort_unless(in_array($row->status, ['blocked', 'failed'], true), 422, 'Only a blocked or locally failed update can be retried here.');
            $order = WebsiteOrder::query()->forTenantId($order->tenant_id)->findOrFail($order->id);
            $readiness = $this->readiness(Tenant::query()->findOrFail($order->tenant_id));
            abort_unless($readiness['ready'] && $this->current($row, $order), 422, 'Restore notification readiness and review the current order state first.');
            $snapshot = $row->snapshot;
            abort_unless(filter_var($snapshot['recipient'] ?? '', FILTER_VALIDATE_EMAIL) && preg_match('/^[A-Za-z0-9]{56}$/', (string) $order->lookup_token), 422, 'This saved recipient or private order link needs review.');
            $row->update(['snapshot' => array_merge($snapshot, $this->identity(), ['identity_fingerprint' => $this->identityFingerprint(), 'order_url' => $this->orderUrl($order, $readiness['public_url'])]),
                'status' => 'pending', 'attempts' => 0, 'failure_code' => null, 'next_attempt_at' => now()]);
            $this->audit($order, $row, $actor, 'notification_retry_requested', $reason);
            DB::afterCommit(fn () => $this->enqueue($row->id));
        });
    }

    /** Provider evidence must resolve ambiguity before a deliberate retry is possible. */
    public function resolveUnknown(WebsiteOrder $order, int $id, User $actor, string $outcome, string $reason): void
    {
        app(WebsiteShippingAccessService::class)->authorize($actor, $order->tenant_id);
        abort_unless(in_array($outcome, ['accepted', 'confirmed_not_accepted'], true) && mb_strlen(trim($reason)) >= 10 && mb_strlen($reason) <= 1000, 422, 'Record the provider evidence and its acceptance outcome.');
        DB::transaction(function () use ($order, $id, $actor, $outcome, $reason): void {
            $row = WebsiteOrderNotification::query()->forTenantId($order->tenant_id)->where('website_order_id', $order->id)->lockForUpdate()->findOrFail($id);
            abort_unless($row->status === 'unknown', 422, 'This update has no unresolved transport acceptance.');
            $row->update($outcome === 'accepted'
                ? ['status' => 'accepted', 'accepted_at' => now(), 'failure_code' => 'operator_confirmed_acceptance']
                : ['status' => 'blocked', 'failure_code' => 'operator_confirmed_not_accepted']);
            $this->audit($order, $row, $actor, 'notification_acceptance_reviewed', $outcome.': '.trim($reason));
            // A separate explicit retryBlocked action is required after evidence
            // confirms nonacceptance. This review never dispatches any email.
        });
    }

    /** Recover a missed queue dispatch; expired processing leases are ambiguous. */
    public function scan(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $unknown = 0;
        foreach (WebsiteOrderNotification::query()->where('status', 'processing')->where('lease_expires_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $unknown += WebsiteOrderNotification::query()->whereKey($id)->where('status', 'processing')->where('lease_expires_at', '<=', now())
                ->update(['status' => 'unknown', 'failure_code' => 'send_lease_expired', 'lease_token' => null, 'lease_expires_at' => null]);
        }
        $enqueued = $deferred = 0;
        $rows = WebsiteOrderNotification::query()->whereIn('status', ['pending', 'queued', 'retry'])->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($rows as $id) {
            $this->enqueue($id) ? $enqueued++ : $deferred++;
        }

        return compact('enqueued', 'deferred', 'unknown');
    }

    private function enqueue(int $id): bool
    {
        $claimed = DB::transaction(function () use ($id): bool {
            $row = WebsiteOrderNotification::query()->lockForUpdate()->find($id);
            if (! $row || ! in_array($row->status, ['pending', 'queued', 'retry'], true) || $row->next_attempt_at?->isFuture()) {
                return false;
            }
            $row->update(['status' => 'queued', 'next_attempt_at' => now()->addMinutes(5)]);

            return true;
        });
        if (! $claimed) {
            return true;
        }
        try {
            SendWebsiteOrderNotification::dispatch($id);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Native Website notification queue handoff deferred.', ['website_order_notification_id' => $id, 'exception_type' => $exception::class]);

            return false;
        }
    }

    private function finish(WebsiteOrderNotification $notification, array $state): void
    {
        WebsiteOrderNotification::query()->forTenantId($notification->tenant_id)->whereKey($notification->id)
            ->where('status', 'processing')->where('lease_token', $notification->lease_token)
            ->update($state + ['lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
    }

    private function identity(): array
    {
        return ['mailer' => trim((string) config('managed_website.order_notifications_mailer')),
            'from_address' => trim((string) config('managed_website.order_notifications_from_address')),
            'from_name' => trim((string) config('managed_website.order_notifications_from_name'))];
    }

    private function identityFingerprint(): string
    {
        return hash('sha256', json_encode($this->identity(), JSON_THROW_ON_ERROR));
    }

    private function oneLine(string $value): string
    {
        return mb_substr(trim(preg_replace('/[\r\n]+/', ' ', strip_tags($value))), 0, 190);
    }

    private function orderUrl(WebsiteOrder $order, string $base): string
    {
        if (in_array($order->source, ['staff_quote', 'staff_draft_invoice'], true)) {
            return rtrim($base, '/').'/invoices/'.$order->lookup_token;
        }

        return rtrim($base, '/').'/orders/track?'.http_build_query(['order' => $order->number, 'token' => $order->lookup_token], '', '&', PHP_QUERY_RFC3986);
    }

    private function current(WebsiteOrderNotification $row, WebsiteOrder $order): bool
    {
        if (! in_array($order->source, ['native', 'staff_quote', 'staff_draft_invoice'], true)) {
            return false;
        }
        if ($row->kind === 'payment_review') {
            return in_array($order->payment_status, ['payment_review', 'paid_review'], true);
        }
        if (in_array($row->kind, ['confirmed', 'invoice_payment'], true)) {
            return true; // A delayed receipt remains useful after fulfillment/refund.
        }
        $shipment = WebsiteShipment::query()->forTenantId($order->tenant_id)->where('website_order_id', $order->id)->find($row->website_shipment_id);
        if (! $shipment || data_get($shipment->parcel, '_native.retired')) {
            return false;
        }

        return match ($row->kind) {
            'preparing' => ! data_get($shipment->parcel, '_native.handed_over_at') && ! data_get($shipment->parcel, '_native.carrier_accepted_at') && in_array($shipment->status, ['label_purchased', 'packed'], true),
            'shipped' => ! data_get($shipment->parcel, '_native.exception_active')
                && ! in_array($shipment->status, ['delivered', 'voided', 'cancelled', 'failure', 'error', 'return_to_sender'], true),
            'label_updated' => ! in_array($shipment->status, ['delivered', 'voided', 'cancelled'], true),
            'delivered' => $shipment->status === 'delivered',
            'exception' => (bool) data_get($shipment->parcel, '_native.exception_active') || in_array($shipment->status, ['failure', 'return_to_sender', 'error'], true),
            default => false,
        };
    }

    private function audit(WebsiteOrder $order, WebsiteOrderNotification $row, User $actor, string $type, string $reason): void
    {
        WebsiteOrderEvent::query()->create(['tenant_id' => $order->tenant_id, 'website_order_id' => $order->id, 'user_id' => $actor->id,
            'event_type' => $type, 'visibility' => 'staff', 'message' => 'Order update reviewed by staff.',
            'data' => ['website_order_notification_id' => $row->id, 'reason' => trim($reason)]]);
    }

    private function publicUrl(TenantSite $site): ?string
    {
        $domain = app(ManagedWebsiteDomainService::class)->enabledFor($site->tenant)
            ? $site->domains()->where('status', 'active')->where('is_primary', true)->whereNotNull('verified_at')->whereNotNull('activated_at')->first() : null;
        $host = $domain?->hostname ?: $site->subdomain.'.'.trim((string) config('tenancy.domains.canonical.base_domain'));
        if (! filter_var('https://'.$host, FILTER_VALIDATE_URL) || ! preg_match('/^[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?$/', $host)
            || ! app(ManagedWebsiteService::class)->publicHostAllowed($site, $host)) {
            return null;
        }

        return 'https://'.$host;
    }
}
