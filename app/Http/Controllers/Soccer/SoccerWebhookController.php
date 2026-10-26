<?php

namespace App\Http\Controllers\Soccer;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Soccer\SoccerPaymentService;
use App\Services\Soccer\SoccerStore;
use Illuminate\Http\Request;

class SoccerWebhookController extends Controller
{
    public function stripe(Request $r, SoccerStore $store, SoccerPaymentService $payments)
    {
        $secret = (string) config('soccer.stripe_webhook_secret');
        $parts = [];
        foreach (explode(',', $r->header('Stripe-Signature', '')) as $p) {
            [$k,$v] = array_pad(explode('=', $p, 2), 2, '');
            $parts[$k][] = $v;
        }
        $ts = $parts['t'][0] ?? '';
        $valid = false;
        if (strlen($secret) >= 16 && ctype_digit($ts) && abs(time() - (int) $ts) < 300) {
            foreach ($parts['v1'] ?? [] as $sig) {
                if (hash_equals(hash_hmac('sha256', $ts.'.'.$r->getContent(), $secret), $sig)) {
                    $valid = true;
                }
            }
        }
        abort_unless($valid, 400);
        $event = json_decode($r->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $obj = $event['data']['object'] ?? [];
        $t = Tenant::where('slug', config('soccer.tenant_slug'))->firstOrFail();
        if ($event['type'] === 'checkout.session.completed' && ($obj['payment_status'] ?? '') === 'paid' && ($obj['currency'] ?? '') === 'usd') {
            $id = $obj['metadata']['soccer_invoice_id'] ?? '';
            $i = $store->query('invoices', $t->id)->where('id', $id)->first();
            if ($i && $i->checkout_id === ($obj['id'] ?? null)) {
                $payments->paid($t->id, $id, 'stripe', 'intent:'.$obj['payment_intent'], (int) $obj['amount_total'], ['payment_intent' => $obj['payment_intent'], 'stripe_event_id' => $event['id']]);
            }
        }
        if (str_starts_with($event['type'], 'refund.') && ! empty($obj['id'])) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($store, $t, $obj) {
                $refund = $store->query('refunds', $t->id)->where('provider_key', $obj['id'])->lockForUpdate()->first();
                if (! $refund || $refund->status === 'succeeded') {
                    return;
                }$status = $obj['status'] ?? 'pending';
                $store->query('refunds', $t->id)->where('id', $refund->id)->update(['status' => $status, 'updated_at' => now()]);
                if ($status === 'succeeded') {
                    $store->query('invoices', $t->id)->where('id', $refund->invoice_id)->increment('refunded_cents', $refund->amount_cents);
                }
            });
        }

        return response()->json(['received' => true]);
    }

    public function sms(Request $r, SoccerStore $store)
    {
        $t = Tenant::where('slug', config('soccer.tenant_slug'))->firstOrFail();
        $account = app(\App\Services\Marketing\Messaging\TenantMessagingGateway::class)->validateTwilioCallback((string) $r->input('AccountSid'), $r->fullUrl(), $r->all(), $r->header('X-Twilio-Signature'));
        abort_unless($account && $account->tenant_id === $t->id, 403);
        if ($r->filled('MessageStatus')) {
            $status = $r->input('MessageStatus');
            abort_unless(in_array($status, ['queued', 'sending', 'sent', 'delivered', 'undelivered', 'failed'], true), 422);
            $store->query('deliveries', $t->id)->where('provider_message_id', $r->input('MessageSid'))->update(['status' => $status, 'updated_at' => now()]);
            \App\Models\MessagingConversationMessage::query()->forTenantId($t->id)->where('provider_message_id', $r->input('MessageSid'))->update(['delivery_status' => $status]);
        } elseif ($r->filled('Body')) {
            $svc = app(\App\Services\Marketing\MessagingConversationService::class);
            $phone = app(\App\Support\Marketing\MarketingIdentityNormalizer::class)->toE164((string) $r->input('From'));
            abort_unless($phone, 422);
            $existing = \App\Models\MessagingConversation::query()->forTenantId($t->id)->where('phone', $phone)->where('source_type', 'soccer_waitlist')->first();
            if ($existing) {
                $svc->appendMessage($existing, ['channel' => 'sms', 'direction' => 'inbound', 'provider' => 'twilio', 'provider_message_id' => $r->input('MessageSid'), 'dedupe_hash' => hash('sha256', 'wren-inbound:'.$r->input('MessageSid')), 'body' => $r->input('Body'), 'from_identity' => $phone, 'received_at' => now()]);
            }
            $keyword = strtoupper(trim((string) ($r->input('OptOutType') ?: $r->input('Body'))));
            $reply = null;
            if (in_array($keyword, \App\Services\Soccer\SoccerSmsConsent::STOP_KEYWORDS, true)) {
                app(\App\Services\Soccer\SoccerSmsConsent::class)->revokePhone($t->id, $phone, (string) $r->input('MessageSid'));
                $reply = 'Wren Family Soccer: you have been unsubscribed. No more program texts will be sent. Help: john@evergrovesoftware.com.';
            } elseif ($keyword === 'HELP') {
                $reply = 'Wren Family Soccer / Watershed Fellowship Upstate LLC: registration and season updates. Help: john@evergrovesoftware.com. Message frequency varies. Msg & data rates may apply. Reply STOP to opt out.';
            }
            // Advanced Opt-Out already sends its own keyword response.
            if ($reply && ! $r->filled('OptOutType')) {
                return response('<?xml version="1.0" encoding="UTF-8"?><Response><Message>'.htmlspecialchars($reply, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</Message></Response>', 200)->header('Content-Type', 'text/xml; charset=UTF-8');
            }
        }

        return response('', 204);
    }
}
