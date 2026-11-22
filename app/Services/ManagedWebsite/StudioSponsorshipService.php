<?php

namespace App\Services\ManagedWebsite;

use App\Models\StudioBrief;
use App\Models\StudioOffer;
use App\Models\TenantSite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StudioSponsorshipService
{
    public function __construct(private WebsiteQuoteOrderService $quotes, private WebsiteInvoicePaymentService $payments) {}

    public function submit(TenantSite $site, int $id, array $data): StudioBrief
    {
        return DB::transaction(function () use ($site, $id, $data) {
            $offer = StudioOffer::where('tenant_site_id', $site->id)->lockForUpdate()->findOrFail($id);
            $fields = ['sponsor' => $data['sponsor'], 'email' => strtolower(trim($data['email'])),
                'brief' => $data['brief'], 'social_clip' => (bool) ($data['social_clip'] ?? false)];
            if ($old = $offer->briefs()->where('request_id', $data['request_id'])->first()) {
                foreach ($fields as $key => $value) {
                    abort_unless($value === $old->$key, 409);
                }

                return $old;
            }
            abort_if($offer->reserved || $offer->closes_at->isPast(), 409, 'This spot is closed.');
            abort_if($fields['social_clip'] && $offer->clip_cents === null, 422);

            return $offer->briefs()->create(['request_id' => $data['request_id'], ...$fields]);
        }, 3);
    }

    public function approve(TenantSite $site, int $offerId, int $briefId, array $tax, User $actor): StudioBrief
    {
        $this->quotes->assertEditor($site, $actor);

        return DB::transaction(function () use ($site, $offerId, $briefId, $tax, $actor) {
            $offer = StudioOffer::where('tenant_site_id', $site->id)->lockForUpdate()->findOrFail($offerId);
            $brief = $offer->briefs()->lockForUpdate()->findOrFail($briefId);
            if ($brief->website_order_id) {
                return $brief;
            }
            abort_if($offer->reserved || $offer->closes_at->isPast() || $brief->status !== 'submitted', 409);
            $lines = [['title' => $offer->title, 'quantity' => 1, 'unit_price_cents' => $offer->price_cents]];
            if ($brief->social_clip) {
                $lines[] = ['title' => 'Edited social clip', 'quantity' => 1, 'unit_price_cents' => $offer->clip_cents];
            }
            $order = $this->quotes->create($site, [
                'request_id' => $brief->request_id, 'workflow' => 'draft', 'customer_name' => $brief->sponsor, 'customer_email' => $brief->email,
                'lines' => $lines, 'fulfillment_method' => 'service', 'delivery_details' => $offer->title, 'delivery_confirmed' => true,
                'shipping_cents' => 0, 'manual_tax_confirmed' => true, 'deposit_type' => 'none', ...$tax,
            ], $actor);
            $this->quotes->issue($order, $actor, 1);
            $brief->update(['status' => 'approved', 'website_order_id' => $order->id]);
            $offer->update(['reserved' => true]);

            return $brief;
        }, 3);
    }

    public function paid(StudioBrief $brief): bool
    {
        $order = $brief->order;

        return $order && ! $order->cancelled_at && ! $order->refunded_cents && $order->payment_status === 'paid'
            && ! $this->payments->hasRefundActivity($order) && $this->payments->balance($order) === 0;
    }

    public function rundown(TenantSite $site, string $episode): array
    {
        $rows = [];
        foreach (StudioOffer::where('tenant_site_id', $site->id)->where('episode_id', $episode)->with('briefs.order')->get() as $offer) {
            foreach ($offer->briefs as $brief) {
                if (! $this->paid($brief)) {
                    continue;
                }
                foreach ($brief->social_clip ? [$offer->kind, 'social_clip'] : [$offer->kind] as $kind) {
                    $rows[] = ['booking_id' => $brief->id, 'kind' => $kind, 'sponsor' => $brief->sponsor,
                        'brief' => $brief->brief, 'seconds' => $kind === 'host_read' ? 60 : null];
                }
            }
        }

        return ['schema_version' => 1, 'episode_id' => $episode, 'deliverables' => $rows];
    }
}
